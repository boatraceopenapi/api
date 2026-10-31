# レスポンススキーマ (v1)

> 📅 対応期間: 2026年01月01日以降

## エンドポイント

```
https://boatraceopenapi.github.io/api/v1/YYYY/YYYYMMDD.json
```

## `_source` フィールドについて

`_source` サフィックスが付くフィールドは、スクレイピングで取得したオリジナルの文字列です。  
対応する `_source` なしのフィールドは、そのオリジナル文字列を変換・正規化した値です。

例:
| フィールド | 値 | 説明 |
|---|---|---|
| `day_number_source` | `"初日"` | スクレイピング元の文字列 |
| `day_number` | `1` | 変換後の数値 |

コード値（`stadium_number`・`grade_number`・`place_number` など）の対応表は [codes.md](codes.md) を参照してください。  
欠損や中止レースの扱いなど、データを集計する前に知っておくべきことは「[データの注意点](#データの注意点)」にまとめています。

---

## JSON 全体構造

レスポンスは以下の階層で構成されます。  
スタジアム番号・レース番号はいずれも文字列キー（例: `"1"`）です。

```
{
  "programs": {
    "stadiums": {
      "{stadium_number}": {
        "races": {
          "{race_number}": {
            // 出走表
            "date": "...",
            "stadium_number": ...,
            "race_number": ...,
            ...
            "racers": { "{entry_number}": { ... } },

            // 直前情報
            "preview": {
              ...
              "racers": { "{entry_number}": { ... } }
            },

            // 結果
            "result": {
              ...
              "racers": { "{entry_number}": { ... } }
            }
          }
        }
      }
    }
  }
}
```

---

## 出走表

`programs.stadiums.{stadium_number}.races.{race_number}` 直下のフィールドです。

### 基本情報

| フィールド | 型 | `_source` | 説明 |
|---|---|---|---|
| `date` | `string` | — | レース開催日（`YYYY-MM-DD`） |
| `stadium_number` | `integer` | — | レース場番号 |
| `race_number` | `integer` | — | レース番号 |
| `closed_at` | `string` | — | 締切日時（`YYYY-MM-DD HH:MM:SS`） |
| `grade_number` | `integer` | ✅ | グレード番号 |
| `title` | `string` | — | レースタイトル |
| `subtitle` | `string` | — | レースサブタイトル |
| `distance` | `integer` | ✅ | 距離（メートル） |
| `day_number` | `integer` | ✅ | 開催日番号（何日目か） |

### 選手情報 (`racers`)

枠番（`1`〜`6`）をキーとするオブジェクトです。

| フィールド | 型 | `_source` | 説明 |
|---|---|---|---|
| `entry_number` | `integer` | — | 枠番 |
| `name` | `string` | — | 選手名 |
| `number` | `integer` | — | 選手登録番号 |
| `rank_number` | `integer` | ✅ | 級別番号 |
| `branch_number` | `integer` | ✅ | 支部番号 |
| `birthplace_number` | `integer` | ✅ | 出身地番号 |
| `age` | `integer` | ✅ | 年齢 |
| `weight` | `float` | ✅ | 体重（kg） |
| `flying_count` | `integer` | ✅ | フライング回数 |
| `late_count` | `integer` | ✅ | 出遅れ回数 |
| `average_start_timing` | `float` | — | 平均スタートタイミング |
| `national_win_rate` | `float` | — | 全国勝率 |
| `national_top_2_percent` | `float` | — | 全国 2 連対率（%） |
| `national_top_3_percent` | `float` | — | 全国 3 連対率（%） |
| `local_win_rate` | `float` | — | 当地勝率 |
| `local_top_2_percent` | `float` | — | 当地 2 連対率（%） |
| `local_top_3_percent` | `float` | — | 当地 3 連対率（%） |
| `motor_number` | `integer` | — | モーター番号 |
| `motor_top_2_percent` | `float` | — | モーター 2 連対率（%） |
| `motor_top_3_percent` | `float` | — | モーター 3 連対率（%） |
| `boat_number` | `integer` | — | ボート番号 |
| `boat_top_2_percent` | `float` | — | ボート 2 連対率（%） |
| `boat_top_3_percent` | `float` | — | ボート 3 連対率（%） |

---

## 直前情報 (`preview`)

`programs.stadiums.{stadium_number}.races.{race_number}.preview` 直下のフィールドです。

### 基本情報

| フィールド | 型 | `_source` | 説明 |
|---|---|---|---|
| `date` | `string` | — | レース開催日（`YYYY-MM-DD`） |
| `stadium_number` | `integer` | — | レース場番号 |
| `race_number` | `integer` | — | レース番号 |
| `wind_speed` | `integer` | ✅ | 風速（m） |
| `wind_direction_number` | `integer` | ✅ | 風向番号 |
| `wave_height` | `integer` | ✅ | 波高（cm） |
| `weather_number` | `integer` | ✅ | 天候番号 |
| `air_temperature` | `float` | ✅ | 気温（℃） |
| `water_temperature` | `float` | ✅ | 水温（℃） |

### 選手情報 (`racers`)

枠番（`1`〜`6`）をキーとするオブジェクトです。

| フィールド | 型 | `_source` | 説明 |
|---|---|---|---|
| `entry_number` | `integer` | — | 枠番 |
| `course_number` | `integer` | — | 進入コース番号 |
| `start_timing` | `float` | ✅ | スタートタイミング |
| `weight` | `float` | ✅ | 体重（kg） |
| `weight_adjustment` | `float` | ✅ | 体重調整量（kg） |
| `exhibition_time` | `float` | ✅ | 展示タイム（秒） |
| `tilt_adjustment` | `float` | ✅ | チルト調整 |
| `propeller` | `string` | — | プロペラ（新品に交換した場合は `"新"`、それ以外は `null`）※ |
| `parts` | `array` | — | 交換部品の一覧（交換がなければ空配列）※ |

`parts` の各要素は次のとおりです。

| フィールド | 型 | `_source` | 説明 |
|---|---|---|---|
| `number` | `integer` | ✅ | 部品番号（[codes.md](codes.md#交換部品-previewracersentry_numberpartsnumber)） |
| `quantity` | `integer` | — | 個数（表示がない部品は `null`） |

※ 古いファイルには、このフィールド自体が存在しない場合があります（[データの注意点](#フィールドの有無がファイルによって違う)）。

---

## 結果 (`result`)

`programs.stadiums.{stadium_number}.races.{race_number}.result` 直下のフィールドです。

### 基本情報

| フィールド | 型 | `_source` | 説明 |
|---|---|---|---|
| `date` | `string` | — | レース開催日（`YYYY-MM-DD`） |
| `stadium_number` | `integer` | — | レース場番号 |
| `race_number` | `integer` | — | レース番号 |
| `wind_speed` | `integer` | ✅ | 風速（m） |
| `wind_direction_number` | `integer` | ✅ | 風向番号 |
| `wave_height` | `integer` | ✅ | 波高（cm） |
| `weather_number` | `integer` | ✅ | 天候番号 |
| `air_temperature` | `float` | ✅ | 気温（℃） |
| `water_temperature` | `float` | ✅ | 水温（℃） |
| `technique_number` | `integer` | ✅ | 決まり手番号 |
| `remarks` | `string` | — | 備考（例: `"【返還艇あり】"`、`"【同着あり】"`。なければ `null`）※ |
| `refunds` | `integer[]` | — | 返還艇の枠番の一覧（なければ空配列）※ |

※ 古いファイルには、このフィールド自体が存在しない場合があります（[データの注意点](#フィールドの有無がファイルによって違う)）。

### 選手情報 (`racers`)

枠番（`1`〜`6`）をキーとするオブジェクトです。

| フィールド | 型 | `_source` | 説明 |
|---|---|---|---|
| `entry_number` | `integer` | — | 枠番 |
| `course_number` | `integer` | — | 進入コース番号 |
| `start_timing` | `float` | ✅ | スタートタイミング |
| `place_number` | `integer` | ✅ | 着順番号 |
| `number` | `integer` | ✅ | 選手登録番号 |
| `name` | `string` | — | 選手名 |

### 払戻情報 (`payouts`)

賭式をキーとする配列です。同じ賭式で複数の払戻がある場合（同着など）、配列に複数の要素が含まれます。

| フィールド | 型 | 説明 |
|---|---|---|
| `combination` | `string` | 的中組み合わせ（例: `"4-3-1"`、`"1=3=4"`） |
| `amount` | `integer` | 払戻金額（円、100 円あたり）。金額が表示されない場合は `null` |
| `label` | `string` | 金額の代わりに表示される文言（例: `"不成立"`）。通常は `null` ※ |

※ 古いファイルには、このフィールド自体が存在しない場合があります（[データの注意点](#フィールドの有無がファイルによって違う)）。

```json
"payouts": {
  "trifecta": [
    { "combination": "4-3-1", "amount": 3040 }
  ],
  "trio": [
    { "combination": "1=3=4", "amount": 300 }
  ],
  "exacta": [
    { "combination": "4-3", "amount": 1290 }
  ],
  "quinella": [
    { "combination": "3=4", "amount": 380 }
  ],
  "quinella_place": [
    { "combination": "3=4", "amount": 160 },
    { "combination": "1=4", "amount": 200 },
    { "combination": "1=3", "amount": 260 }
  ],
  "win": [
    { "combination": "4", "amount": 330 }
  ],
  "place": [
    { "combination": "4", "amount": 140 },
    { "combination": "3", "amount": 140 }
  ]
}
```

---

## データの注意点

集計やモデル構築の前に確認しておくべき点です。

### レースを一意に決めるキー

- レース: `(date, stadium_number, race_number)`
- 出走艇: `(date, stadium_number, race_number, entry_number)`
- 選手は `number`（登録番号）で識別してください。`name` はキーに使わないでください。
- JSON のオブジェクトキー（`"1"` など）は文字列です。値として入っている `stadium_number` などは整数です。

### 中止・順延のレース

- 開催が中止・順延になった日のレースも出走表に残ります。このとき `day_number` は `null` で、`day_number_source` が `"中止"` または `"順延"` になります。
- レースが行われなかった場合、`result` の気象・決まり手・選手の着順などはすべて `null` になり、払戻は空配列になります。
- 出走表そのものが取得できなかったレースは、`closed_at` や選手情報まで `null` です。
- 集計の際は「`result.racers` の `place_number` がすべて `null` のレース」を除外してください。

### 着順・スタートタイミング

- `place_number` の `7` 以上は着順ではありません（[codes.md](codes.md#着順-place_number)）。
- `start_timing` はフライングのとき負の値になります（`"F.03"` → `-0.03`）。出遅れ（`"L"`）と欠場のときは `null` です。
- `course_number` は枠番 `entry_number` と一致するとは限りません（前付けなどで入れ替わることがあり、珍しくありません）。
- 直前情報の `course_number`・`start_timing` はスタート展示のもので、本番の進入・スタートとは別物です。本番の値は `result` 側を使ってください。
- 同着の場合、同じ `place_number` を持つ艇が複数あります。

### 払戻

- 同着や返還があると、同じ賭式に複数の要素が入ります（`place` は通常 2 件、`quinella_place` は通常 3 件）。
- 返還艇がある場合、`result.remarks` に `"【返還艇あり】"` が入り、`result.refunds` に枠番が入ります。
- 全艇フライングなどでレースが成立しないと、決まり手は `null`、一部の賭式は空配列になります。
- `amount` が `null` の要素があります（金額が表示されなかったもの）。`label` に理由が入ることがあります。

### フィールドの有無がファイルによって違う

次のフィールドは後から追加されたもので、追加前に生成されたファイルには存在しません。値が `null` なのではなく、キー自体がありません。過去分は順次取り直していますが、どのファイルが新しい形式かは日付からは判断できないので、キーの有無で確認してください。

- `preview.racers.*.propeller` / `preview.racers.*.parts`
- `result.remarks` / `result.refunds`
- `result.payouts.*[].label`

また、直前情報が取れなかった選手では、`preview.racers.*` から `course_number` と `start_timing` のキーが欠けることがあります。キーの有無に頼らず、「キーがない」と「`null`」を同じ欠損として扱ってください。

### 時点（リーク）に関する注意

1 つのレースオブジェクトに、時点の違う情報がまとめて入っています。予測モデルの特徴量を作るときは、使う情報がレースの何分前に分かるものかを区別してください。

| 部分 | 分かる時点 |
|---|---|
| 出走表（`racers` など） | 前日まで |
| 直前情報（`preview`） | 締切の 30〜7 分前ごろ |
| 結果（`result`） | 締切の 8〜30 分後ごろ |

- `result` の気象情報はレース時点のものです。予測に使う気象は `preview` 側を使ってください。
- 気象の観測はレースごとに 1 回で、あるレースの `preview` の気象は、同じ場の 1 つ前のレースの `result` の気象と同じ値です。`preview` の気象をレース間で差分しても、1 つ前のレースの「直前→本番」の変化を測り直しているだけになります。
- 過去日のファイルの `preview` は、取り直した後の最終的な値です。締切前に実際に見えていた値とは食い違うことがあります。
- 出走表の勝率・連対率・モーター成績などは、公式の出走表に載っている値です。そのレースの結果は含まれていません。

### 更新とデータの確定

- 当日のファイルは約 10 分ごとに上書きされ、直前情報と結果が順に埋まっていきます。`today.json` も同じ内容です。
- 毎日 0 時（JST）に前日分をまとめて取り直します。過去日を取り込む場合は、翌日以降に取得したファイルを使ってください。
