# gha-dispatcher

Cloudflare Workers（Durable Object のアラーム）から GitHub Actions の `workflow_dispatch` API を叩き、
`boatraceopenapi/api` の同期ワークフローを確実に起動させるための Worker。

GitHub Actions の `schedule` cron は GitHub 側の負荷状況により数分〜数十分遅延することがあるため、
発火タイミングを外部から制御する目的で導入している。

---

## 0. 構成

| 項目 | 値 |
|---|---|
| Worker 名 | `gha-dispatcher` |
| 対象リポジトリ | `boatraceopenapi/api` |
| dispatch 先 ref | `gh-pages`（デフォルトブランチ） |
| 認証方式 | GitHub App（Installation Access Token） |
| GitHub App | `boatraceopenapi` (App ID `4214299`) |

dispatch は Durable Object `Scheduler` のアラームが 3 分刻み（UTC の 00:00, 00:03, …）で行う。
どの回で何を起動するかは [`src/index.ts`](src/index.ts) の `workflowsForSlot` で定義している。

| 回（UTC） | 起動するワークフロー |
|---|---|
| 15:00（JST 0:00）ちょうどの回 | `sync.yml` のみ |
| それ以外の 3 分刻みの回 | `sync-upcoming.yml` |

`sync.yml` は 15:00 UTC の 1 回だけにしている。2026-09-26〜10-28 の実績で見ると、

- 15:00（JST 0:00）は毎回、前日分の確定と当日分のファイル作成をしていて必須
- 18:00・21:00（JST 3:00・6:00）はほぼ何もコミットせず、15:00 が失敗した日の再試行にしかなっていなかった。
  再試行は `sync.yml` 自身の `schedule` トリガー（2〜7 時間遅れで毎日発火している）で足りる
- 00:00（JST 9:00）は朝の体重・チルトなどを全レース分まとめて入れていたが、1R の締切（8:35）より後で、
  約 17 分間 `sync-upcoming.yml` を待たせていた。各レースのデータは締切 ±30 分に `sync-upcoming.yml` が取り直すので省いた

`sync.yml` の回で `sync-upcoming.yml` を一緒に起動しないのは、待機に回った `sync.yml` が取り消されるのを防ぐため（[8.](#8-リポジトリ側ワークフローとの関係) を参照）。
`sync.yml` は当日分の直前情報も取り直すので、その回の `sync-upcoming.yml` を省いても取りこぼしはない。

- アラームは `alarm()` の中で次の回を先に予約してから dispatch するため、dispatch が失敗しても止まらない
- `sync-upcoming.yml` は、近くにレースがない回（主に夜間）は dispatch しない（下記）
- Cron Triggers（`*/10 * * * *`）は dispatch せず、アラームが消えていたら掛け直す**見張り役**にしている
- `GET /scheduler`（`X-Trigger-Secret` 必須）でもアラームを確保し、次回の予定時刻を返す。dispatch はしない。
  認証なしにすると、叩かれるたびに Durable Object の無料枠（1 日 10 万リクエスト）を消費し、枠が尽きるとアラームまで失敗して同期が止まる

### 夜間の dispatch 停止

`sync-upcoming.yml` は締切 ±30 分のレースしか更新しないため、毎回の dispatch の前に
GitHub Pages の `today.json`（`https://boatraceopenapi.github.io/api/v1/today.json`）を読み、
締切時刻（`closed_at`）が前後 35 分以内のレースがある回だけ dispatch する（`hasRaceNear`）。

- 10 月の実績では、最初の締切は 8:35、最後の締切は 20:45〜22:54（ナイター開催の 19・20・24 場）。
  およそ 23:25〜8:05 は何もしない run を 3 分ごとに起動していた（1 日 480 回のうち約 175 回）
- 余裕を 35 分にしているのは、run の起動が dispatch の 1〜2 分後になることと、Pages のキャッシュ（`max-age=600`）のため
- `closed_at` は日付込みで比べるので、日付が変わって `today.json` がまだ前日分でも、全レースが過去になり dispatch しない
- `today.json` が取得できない、または `closed_at` が 1 件もないときは、従来どおり dispatch する（この判定のせいで同期を止めない）
- 約 1.2 MB あり `JSON.parse` だと Free プランの CPU 上限（10 ms）に近いので、正規表現で `closed_at` だけを拾っている
- Pages の URL や `today.json` の場所を変えたら、`src/index.ts` の `TODAY_JSON_URL` も直すこと。
  直し忘れても止まりはしないが、黙って終日 dispatch に戻る

### Cron Triggers から切り替えた経緯

2026-10-28 08:48 UTC を最後に Cron Triggers が発火しなくなった。デプロイ・Secret に変更はなく、
`wrangler triggers deploy` による再登録、`wrangler deploy` による再デプロイでも復旧せず、
HTTP からの起動だけは正常だった（Cloudflare Community にも同様の報告が複数ある）。
そのため dispatch をアラームに移し、Cron Triggers は見張り役に格下げした。
Cron Triggers が復活しても dispatch が二重になることはない。

---

## 1. 認証方式について（PAT ではなく GitHub App）

Fine-grained PAT ではなく GitHub App を使っている。理由は以下のとおり。

- PAT は最大1年で失効し、手動ローテーションが必要になる
- リポジトリ側のワークフロー（[`sync.yml`](../.github/workflows/sync.yml) / [`sync-upcoming.yml`](../.github/workflows/sync-upcoming.yml)）が
  すでに `actions/create-github-app-token` で同じ App を使っており、認証情報を一本化できる

Worker は起動のたびに次の3ステップを実行する（[`src/index.ts`](src/index.ts)）。

1. App の秘密鍵で RS256 の JWT を生成（有効期限は発行から540秒、時計ずれ対策で `iat` を60秒巻き戻す）
2. `GET /repos/{owner}/{repo}/installation` で installation ID を解決
3. `POST /app/installations/{id}/access_tokens` で Installation Access Token を取得し、dispatch に使用

### GitHub App に必要な権限

**Repository permissions → Actions: Read and write**（Read-only だと 403）。

現状の App の権限（`GET /app` で確認済み）:

```
actions: write / contents: write / metadata: read
```

`contents: write` はワークフロー側が JSON をコミットするために必要なもので、Worker 自体は `actions: write` しか使わない。

### 秘密鍵の形式に注意

Worker は WebCrypto の `importKey('pkcs8', ...)` を使うため、**PKCS#8 形式（`-----BEGIN PRIVATE KEY-----`）でなければ動かない**。
GitHub からダウンロードできる `.pem` は PKCS#1（`-----BEGIN RSA PRIVATE KEY-----`）なので、鍵をローテーションする際は毎回変換が必要。

```bash
openssl pkcs8 -topk8 -nocrypt -in app.private-key.pem -out app.pkcs8.pem
```

---

## 2. Secret 一覧

| 名前 | 用途 |
|---|---|
| `APP_ID` | GitHub App の App ID |
| `APP_PRIVATE_KEY` | GitHub App の秘密鍵（PKCS#8、複数行 PEM） |
| `BOA_TOKEN` | `fetch` ハンドラ（手動トリガー）の認証用トークン |

### ローカル開発用（`.dev.vars`）

`wrangler dev` は本番 Secret を読み込まないため、プロジェクトルートに `.dev.vars` を置く。
複数行の PEM はダブルクオートで囲む。

```
APP_ID=1234567
APP_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQD...
-----END PRIVATE KEY-----"
BOA_TOKEN=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

`.dev.vars` は本プロジェクトの [`.gitignore`](.gitignore) の `.dev.vars*` で除外されている。
**このディレクトリを public リポジトリにコミットする場合、`.gitignore` を必ず一緒にコミットすること**
（リポジトリルートの `.gitignore` は `.dev.vars` をカバーしていない）。

### 本番用の登録

複数行 PEM があるため、対話プロンプトの `wrangler secret put` より `secret bulk` のほうが確実。
`.dev.vars` を `.env` 形式としてそのまま読める。

```bash
npx wrangler secret bulk .dev.vars
```

個別に登録する場合:

```bash
npx wrangler secret put APP_ID
```

登録済み Secret の確認:

```bash
npx wrangler secret list
```

Secret はコード/設定とは別管理なので、`wrangler deploy` しても消えない。再登録は不要。

---

## 3. 別マシンにクローンした場合のセットアップ

`node_modules` は `workerd` / `esbuild` などプラットフォーム依存のバイナリを含むため、
**ディレクトリごとコピーしても動かない**（Windows の `workerd.exe` が入ったまま Linux で実行すると
`Permission denied` や実行形式エラーになる）。必ず入れ直すこと。

```bash
npm ci
```

Cloudflare へのログイン状態はマシンごとに持つ（`~/.config/.wrangler`）。

```bash
npx wrangler login
```

ブラウザが開けない環境では、ダッシュボードで "Edit Cloudflare Workers" テンプレートの API トークンを発行し、
`CLOUDFLARE_API_TOKEN` 環境変数に設定する方法でもよい。

すでに同名の Worker がデプロイ済みかどうかは以下で確認できる。
**同じアカウントに同名の Worker がある場合、`wrangler deploy` は新規作成ではなく上書き更新になる。**

```bash
npx wrangler deployments list
```

---

## 4. ローカル動作確認

```bash
npx wrangler dev
```

起動時に `.dev.vars` の3変数がバインドされていることがログに出る。

`fetch` ハンドラの疎通確認（`X-Trigger-Secret` が必要。未指定なら 401 が返る＝Worker は正常起動）:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8787/
```

**注意: 以下は実際に GitHub Actions のワークフローを起動する。**

```bash
curl -H "X-Trigger-Secret: $BOA_TOKEN" "http://localhost:8787/?workflow=sync-upcoming.yml"
```

見張り役の cron ハンドラのシミュレーション（ローカルでは cron は自動発火しない）:

```bash
curl "http://localhost:8787/cdn-cgi/handler/scheduled?cron=*/10+*+*+*+*"
```

`cron` パラメータは `WATCHDOG_CRON` と一致させること。一致しないと `Unknown cron` になる。

**注意: `wrangler dev` 中は 3 分ごとにアラームが発火し、実際に GitHub Actions を起動する。** 一度 `/scheduler` を叩くか見張りを動かすとアラームが回り続けるので、確認が済んだら `wrangler dev` を終了すること。

---

## 5. デプロイ

事前チェック（バンドルできるかの確認のみ。Cloudflare への認証なしでも実行できる）:

```bash
npx wrangler deploy --dry-run
```

本番デプロイ:

```bash
npx wrangler deploy
```

初回デプロイ後（または Durable Object を作り直した後）は、アラームを起動する。
デプロイしても既存のアラームは消えないので、通常の再デプロイでは不要。

```bash
curl -H "X-Trigger-Secret: $BOA_TOKEN" https://gha-dispatcher.boatraceopenapi.workers.dev/scheduler
```

### `Would you like to register a workers.dev subdomain now?` と聞かれたら

- **Yes** にする。アラームの初回起動と掛け直しに `/scheduler` を使うため
- `BOA_TOKEN` による `X-Trigger-Secret` チェックがすべてのパスで効くので、公開しても認証は効く

---

## 6. コード/設定を変更した後の反映

```bash
npx wrangler deploy
```

- デプロイはアトミックに切り替わるためダウンタイムなし
- 問題があればダッシュボード → Deployments タブから即ロールバック可能
- `wrangler.jsonc` のバインディングや `.dev.vars` のキーを変更したら型を再生成する:

```bash
npx wrangler types
```

`worker-configuration.d.ts` は生成物。`.dev.vars` のキーから `Cloudflare.Env` が組み立てられるため、
`.dev.vars` がない状態で再生成すると環境変数の型が消える点に注意。

---

## 7. 動作確認・監視

- Cloudflare ダッシュボード → Workers & Pages → `gha-dispatcher` → **Logs**（Real-time Logs）で実行ログを確認
  （`observability.enabled: true` を設定済み）
- ダッシュボードの **Triggers** タブで cron 設定が反映されているか確認
- `curl -H "X-Trigger-Secret: $BOA_TOKEN" https://gha-dispatcher.boatraceopenapi.workers.dev/scheduler` で次回の予定時刻を確認（アラームが消えていれば掛け直される）
- GitHub 側 → Actions タブでワークフローが起動しているか確認

dispatch 失敗時は `console.error` にステータスとレスポンスボディを出したうえで throw する。

---

## 8. リポジトリ側ワークフローとの関係

- ワークフローの `schedule` トリガーは**残しておく**（フェイルセーフ）。
  Worker が止まっても GitHub 純正 cron がバックアップとして機能する
- 両ワークフローとも `workflow_dispatch` を宣言済み。これがないと dispatch API は 404 を返す
- 両ワークフローの `concurrency` グループ名は `sync-${{ github.ref }}` で**共通**。
  そのため sync.yml と sync-upcoming.yml は互いにキューイングされ、
  `cancel-in-progress: false` により「実行中1 + 待機1」に自動的に絞られる。
  Worker 発火と GitHub 純正 cron がタイミング的に重なっても二重実行にはならない
- その代わり、**待機中の run は後から来た run に置き換えられて取り消される**。
  `sync.yml` が待機に回ると 3 分後の `sync-upcoming.yml` に取り消されるため（2026-10-27 15:00 に発生）、
  `sync.yml` の回は `sync.yml` だけを dispatch している。
  ただし直前の回の `sync-upcoming.yml` が 3 分を超えて長引いた場合は、まだ取り消されうる

---

## 9. 運用上の注意点

| 項目 | 内容 |
|---|---|
| 秘密鍵のローテーション | GitHub の `.pem` は PKCS#1。`openssl pkcs8 -topk8 -nocrypt` で PKCS#8 に変換してから登録する |
| `.dev.vars` | Git に絶対コミットしない。`.gitignore` の `.dev.vars*` が唯一の防御線 |
| 401 が返る | `fetch` ハンドラの `X-Trigger-Secret` が `BOA_TOKEN` と不一致 |
| 403 が返る | GitHub App の Actions 権限が Read-only、または対象リポジトリに未インストール |
| 404 が返る | ワークフローに `workflow_dispatch` がない、ファイル名の誤り、ref (`gh-pages`) の誤り |
| `Unknown cron` ログ | `wrangler.jsonc` の cron 文字列と `WATCHDOG_CRON` が不一致 |
| `Alarm dispatch failed` ログ | アラームからの dispatch が失敗。次の回は予約済みなので自動で再開する |
| `today.json fetch failed` / `no closed_at found` ログ | 夜間停止の判定ができず、その回は dispatch した。続くなら `TODAY_JSON_URL` と Pages を確認 |
| レート制限 | Installation Access Token は 5,000 req/h。3分間隔（1日480回）でも余裕がある |
| Cron Triggers 上限 | 無料枠でも利用可、1 Worker あたり最大5個。現在1個（見張り役） |
| Durable Objects | 無料枠では SQLite バックエンド必須（`new_sqlite_classes`）。アラーム起動は 1 日 10 万リクエストの枠に数えられる（1 日約 480 回） |
| workers.dev サブドメイン | `/scheduler` での起動・確認と手動 dispatch のため有効にしている。どのパスも `X-Trigger-Secret` 必須 |
