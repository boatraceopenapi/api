# レスポンススキーマ (v1)

> 📅 対応期間: 2026年07月01日以降

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
| `national_top_1_percent` | `float` | — | 全国1着率（%） |
| `national_top_2_percent` | `float` | — | 全国2着内率（%） |
| `national_top_3_percent` | `float` | — | 全国3着内率（%） |
| `local_top_1_percent` | `float` | — | 当地1着率（%） |
| `local_top_2_percent` | `float` | — | 当地2着内率（%） |
| `local_top_3_percent` | `float` | — | 当地3着内率（%） |
| `motor_number` | `integer` | — | モーター番号 |
| `motor_top_2_percent` | `float` | — | モーター2着内率（%） |
| `motor_top_3_percent` | `float` | — | モーター3着内率（%） |
| `boat_number` | `integer` | — | ボート番号 |
| `boat_top_2_percent` | `float` | — | ボート2着内率（%） |
| `boat_top_3_percent` | `float` | — | ボート3着内率（%） |

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
| `amount` | `integer` | 払戻金額（円） |

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
