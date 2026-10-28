#!/bin/sh
#
# 過去分の JSON を夜間だけ取得し、1 か月ごとにコミットして push する。
#
# 新しい月から古い月へ遡る。HEAD にある日（または取得済みで未コミットの日）は飛ばすので、
# 止まっても同じコマンドを流し直せば続きから始まる。
#
# 使い方:
#
#     bin/backfill.sh 2020-01-01 2025-12-31
#
# 環境変数:
#
#     AVOID_LIVE_WINDOW  1（既定）なら日中帯（07:15〜23:15）の内側では取得せず、明けるまで寝る
#     LIVE_WINDOW_START  日中帯の開始（既定 07:15）
#     LIVE_WINDOW_END    日中帯の終了（既定 23:15）
#     PUSH               1（既定）なら月ごとのコミットのあと push する
#     PHP                php のパス（既定 php）
#
# ⚠️ 開発用の作業ディレクトリとは別の worktree で動かすこと。月ごとに git pull --rebase する。
# ⚠️ データのコミットは同期ワークフロー（boatraceopenapi[bot]）と同じく署名しない。
#    無人で数週間動かすので、pinentry の入力待ちで止まらないようにしている。

PHP=${PHP:-php}

# 時刻の大小は HHMM の整数で比べる。先頭の 0 を落とさないと8進数と見なされる。
#
# @param $1 HH:MM
to_time_number() {
    number=$(echo "$1" | tr -d : | sed 's/^0*//')
    [ -z "$number" ] && number=0
    echo "$number"
}

# 日中帯の内側なら、帯が明けるまで寝てから戻る。
# 判定は 1 日ぶんを始める前にしか行わないので、帯の直前に始めた回は数分はみ出す。
wait_for_live_window() {
    [ "${AVOID_LIVE_WINDOW:-1}" = "1" ] || return 0

    window_start=${LIVE_WINDOW_START:-07:15}
    window_end=${LIVE_WINDOW_END:-23:15}

    start=$(to_time_number "$window_start")
    end=$(to_time_number "$window_end")

    while : ; do
        now=$(to_time_number "$(date +%H:%M)")

        if [ "$now" -lt "$start" ] || [ "$now" -gt "$end" ]; then
            return 0
        fi

        resume_at=$(( $(date -d "$window_end" +%s) + 60 )) || exit 1
        seconds=$(( resume_at - $(date +%s) ))

        [ "$seconds" -le 0 ] && return 0

        echo "Inside the live window ($(date +%H:%M)). Sleeping ${seconds}s."
        sleep "$seconds"
    done
}

# @param $1 YYYY-MM-DD
day_path() {
    echo "docs/v1/$(date -d "$1" +%Y)/$(date -d "$1" +%Y%m%d).json"
}

# 取得した月のファイルを確かめる。開催場数 0 または 24 は開催一覧の取得の失敗を疑う
# （boatpulse の 2017-05〜2026-08 の実績で最小 7・最大 21）。
#
# @param $@ ファイルのパス
validate_month() {
    for file in "$@"; do
        if [ ! -s "$file" ]; then
            echo "Missing: $file"
            return 1
        fi

        stadiums=$(jq '.programs.stadiums | length' "$file") || return 1

        if [ "$stadiums" -eq 0 ] || [ "$stadiums" -ge 24 ]; then
            echo "Unexpected stadium count ($stadiums): $file"
            return 1
        fi

        # 結果のないレースは開催中止もありうるので、止めずに件数だけ出す
        missing=$(jq '[.programs.stadiums[].races[] | select((.result.payouts.trifecta // []) | length == 0)] | length' "$file") || return 1

        if [ "$missing" -gt 0 ]; then
            echo "Races without result: $missing in $file"
        fi
    done
}

# @param $1 YYYY-MM
# @param $@ ファイルのパス
#
# POSIX sh の関数には局所変数がないので、呼び出し側の month を上書きしない名前にしている
commit_month() {
    commit_label=$1
    shift

    git add -- "$@" || return 1

    if git diff --cached --quiet; then
        echo "Nothing to commit for $commit_label."
        return 0
    fi

    git -c commit.gpgsign=false commit -q -m "chore: backfill JSON files for $commit_label" || return 1

    [ "${PUSH:-1}" = "1" ] || return 0

    git -c commit.gpgsign=false pull -q --rebase origin gh-pages || return 1
    git push -q origin HEAD:gh-pages || return 1

    echo "Pushed $commit_label."
}

startdate=$1
enddate=$2

if [ -z "$startdate" ] || [ -z "$enddate" ]; then
    echo "Usage: $0 START_DATE END_DATE"
    exit 1
fi

script_dir=$(cd "$(dirname "$0")" && pwd) || exit 1
cd "$(dirname "$script_dir")" || exit 1

month=$(date -d "$enddate" +%Y-%m-01) || exit 1
first_month=$(date -d "$startdate" +%Y-%m-01) || exit 1

while [ "$(date -d "$month" +%Y%m)" -ge "$(date -d "$first_month" +%Y%m)" ]; do
    files=""
    day=$month

    while [ "$(date -d "$day" +%Y%m)" = "$(date -d "$month" +%Y%m)" ]; do
        day_number=$(date -d "$day" +%Y%m%d)

        if [ "$day_number" -ge "$(date -d "$startdate" +%Y%m%d)" ] && [ "$day_number" -le "$(date -d "$enddate" +%Y%m%d)" ]; then
            path=$(day_path "$day")
            files="$files $path"

            if git cat-file -e "HEAD:$path" 2>/dev/null || [ -s "$path" ]; then
                :
            else
                wait_for_live_window

                "$PHP" bin/sync-range.php "$day" "$day" v1
                exit_code=$?

                if [ $exit_code -ne 0 ]; then
                    echo "sync-range.php failed for $day (exit code: $exit_code). Rerun $0 $startdate $enddate to resume."
                    exit $exit_code
                fi
            fi
        fi

        day=$(date -d "$day +1 day" +%Y-%m-%d) || exit 1
    done

    # shellcheck disable=SC2086
    validate_month $files || { echo "Validation failed for $(date -d "$month" +%Y-%m). Stopping."; exit 1; }
    # shellcheck disable=SC2086
    commit_month "$(date -d "$month" +%Y-%m)" $files || { echo "Commit or push failed for $(date -d "$month" +%Y-%m). Stopping."; exit 1; }

    month=$(date -d "$month -1 month" +%Y-%m-01) || exit 1
done

echo "Completed $startdate .. $enddate."
