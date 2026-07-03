# サンプルコード (v1)

実際のエンドポイントからデータを取得してパースするサンプルコードです。

```
https://boatraceopenapi.github.io/api/v1/2026/20260501.json
```

---

## curl

```bash
DATE="20260501"
YEAR="${DATE:0:4}"

curl "https://boatraceopenapi.github.io/api/v1/${YEAR}/${DATE}.json"
```

`jq` を使うと特定のフィールドを絞り込めます。

```bash
# 桐生 1 レースのサブタイトルを取得
curl "https://boatraceopenapi.github.io/api/v1/${YEAR}/${DATE}.json" \
  | jq '.programs.stadiums["1"].races["1"].subtitle'

# 桐生 1 レースの出走選手名一覧
curl "https://boatraceopenapi.github.io/api/v1/${YEAR}/${DATE}.json" \
  | jq '.programs.stadiums["1"].races["1"].racers | to_entries[] | .value.name'

# 桐生 1 レースの 3 連単オッズ 1-2-3
curl "https://boatraceopenapi.github.io/api/v1/${YEAR}/${DATE}.json" \
  | jq '.programs.stadiums["1"].races["1"].odds.trifecta["1"]["2"]["3"]'
```

---

## TypeScript

```typescript
const date = '20260501';
const year = date.slice(0, 4);
const url = `https://boatraceopenapi.github.io/api/v1/${year}/${date}.json`;

// 型定義
interface Racer {
  entry_number: number;
  name: string;
  number: number;
  [key: string]: unknown;
}

interface PreviewRacer {
  entry_number: number;
  course_number: number;
  start_timing: number;
  exhibition_time: number;
  [key: string]: unknown;
}

interface ResultRacer {
  entry_number: number;
  course_number: number;
  start_timing: number;
  place_number: number;
  number: number;
  name: string;
}

interface Payout {
  combination: string;
  amount: number;
}

interface Race {
  date: string;
  stadium_number: number;
  race_number: number;
  title: string;
  subtitle: string;
  racers: Record<string, Racer>;
  preview?: {
    weather_number_source: string;
    racers: Record<string, PreviewRacer>;
    [key: string]: unknown;
  };
  result?: {
    technique_number_source: string;
    racers: Record<string, ResultRacer>;
    payouts: Record<string, Payout[]>;
    [key: string]: unknown;
  };
}

interface Data {
  programs: {
    stadiums: Record<string, { races: Record<string, Race> }>;
  };
}

const response = await fetch(url);
if (!response.ok) throw new Error(`HTTP ${response.status}`);
const data: Data = await response.json();

const stadiums = data.programs.stadiums;

for (const [stadiumNumber, stadium] of Object.entries(stadiums)) {
  for (const [raceNumber, race] of Object.entries(stadium.races)) {
    // 出走表の基本情報
    const { title, subtitle } = race;

    // 出走表の選手情報
    for (const [entryNumber, racer] of Object.entries(race.racers)) {
      const { name } = racer;
      // ...
    }

    const preview = race.preview;
    if (preview) {
      // 直前情報の基本情報
      const weather = preview.weather_number_source;
      // ...
    }

    const result = race.result;
    if (result) {
      const technique = result.technique_number_source;

      // 結果の選手情報
      for (const [entryNumber, racer] of Object.entries(result.racers)) {
        const { place_number, name } = racer;
        // ...
      }

      // 結果の払戻情報
      for (const payout of result.payouts.trifecta) {
        const { combination, amount } = payout;
        // ...
      }
    }
  }
}
```

---

## Go

```go
package main

import (
	"encoding/json"
	"fmt"
	"net/http"
)

// 型定義
type Payout struct {
	Combination string  `json:"combination"`
	Amount      int     `json:"amount"`
}

type ResultRacer struct {
	EntryNumber  int     `json:"entry_number"`
	CourseNumber int     `json:"course_number"`
	StartTiming  float64 `json:"start_timing"`
	PlaceNumber  int     `json:"place_number"`
	Number       int     `json:"number"`
	Name         string  `json:"name"`
}

type Result struct {
	TechniqueNumberSource string                 `json:"technique_number_source"`
	Racers                map[string]ResultRacer `json:"racers"`
	Payouts               map[string][]Payout    `json:"payouts"`
}

type PreviewRacer struct {
	EntryNumber    int     `json:"entry_number"`
	CourseNumber   int     `json:"course_number"`
	StartTiming    float64 `json:"start_timing"`
	ExhibitionTime float64 `json:"exhibition_time"`
}

type Preview struct {
	WeatherNumberSource string                  `json:"weather_number_source"`
	Racers              map[string]PreviewRacer `json:"racers"`
}

type Racer struct {
	EntryNumber int    `json:"entry_number"`
	Name        string `json:"name"`
	Number      int    `json:"number"`
}

type Race struct {
	Title    string            `json:"title"`
	Subtitle string            `json:"subtitle"`
	Racers   map[string]Racer  `json:"racers"`
	Preview  *Preview          `json:"preview"`
	Odds     *Odds             `json:"odds"`
	Result   *Result           `json:"result"`
}

type Data struct {
	Programs struct {
		Stadiums map[string]struct {
			Races map[string]Race `json:"races"`
		} `json:"stadiums"`
	} `json:"programs"`
}

func main() {
	date := "20260501"
	year := date[:4]
	url := fmt.Sprintf("https://boatraceopenapi.github.io/api/v1/%s/%s.json", year, date)

	resp, err := http.Get(url)
	if err != nil {
		panic(err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		panic(fmt.Sprintf("HTTP %d", resp.StatusCode))
	}

	var data Data
	if err := json.NewDecoder(resp.Body).Decode(&data); err != nil {
		panic(err)
	}

	for stadiumNumber, stadium := range data.Programs.Stadiums {
		for raceNumber, race := range stadium.Races {
            // 出走表の基本情報
			title := race.Title
			subtitle := race.Subtitle
			_, _, _, _ = stadiumNumber, raceNumber, title, subtitle

			// 出走表の選手情報
			for _, racer := range race.Racers {
				name := racer.Name
				_ = name
				// ...
			}

			if preview := race.Preview; preview != nil {
                // 直前情報の基本情報
				weather := preview.WeatherNumberSource
				_ = weather
				// ...
			}

			result := race.Result
			if result == nil {
				continue
			}

			technique := result.TechniqueNumberSource
			_ = technique

			// 結果の選手情報
			for _, racer := range result.Racers {
				place := racer.PlaceNumber
				name  := racer.Name
				_, _ = place, name
				// ...
			}

			// 結果の払戻情報
			for _, payout := range result.Payouts["trifecta"] {
				combination := payout.Combination
				amount      := payout.Amount
				_, _ = combination, amount
				// ...
			}
		}
	}
}
```

---

## Python

```python
import urllib.request
import json

date = '20260501'
year = date[:4]
url = f'https://boatraceopenapi.github.io/api/v1/{year}/{date}.json'

with urllib.request.urlopen(url) as response:
    data = json.loads(response.read())

stadiums = data['programs']['stadiums']

for stadium_number, stadium in stadiums.items():
    for race_number, race in stadium['races'].items():
        # 出走表の基本情報
        title = race['title']
        subtitle = race['subtitle']

        # 出走表の選手情報
        for entry_number, racer in race['racers'].items():
            name = racer['name']
            # ...

        preview = race.get('preview')
        if preview:
            # 直前情報の基本情報
            weather = preview['weather_number_source']
            # ...

        result = race.get('result')
        if result:
            technique = result['technique_number_source']

            # 結果の選手情報
            for entry_number, racer in result['racers'].items():
                place = racer['place_number']
                name = racer['name']
                # ...

            # 結果の払戻情報
            for payout in result['payouts']['trifecta']:
                combination = payout['combination']
                amount = payout['amount']
                # ...
```

---

## JavaScript (Node.js / ブラウザ)

```javascript
const date = '20260501';
const year = date.slice(0, 4);
const url = `https://boatraceopenapi.github.io/api/v1/${year}/${date}.json`;

const response = await fetch(url);
if (!response.ok) throw new Error(`HTTP ${response.status}`);
const data = await response.json();

const stadiums = data.programs.stadiums;

for (const [stadiumNumber, stadium] of Object.entries(stadiums)) {
  for (const [raceNumber, race] of Object.entries(stadium.races)) {
    // 出走表の基本情報
    const { title, subtitle } = race;

    // 出走表の選手情報
    for (const [entryNumber, racer] of Object.entries(race.racers)) {
      const { name } = racer;
      // ...
    }

    const preview = race.preview;
    if (preview) {
      // 直前情報の基本情報
      const weather = preview.weather_number_source;
      // ...
    }

    const result = race.result;
    if (result) {
      const technique = result.technique_number_source;

      // 結果の選手情報
      for (const [entryNumber, racer] of Object.entries(result.racers)) {
        const { place_number, name } = racer;
        // ...
      }

      // 結果の払戻情報
      for (const payout of result.payouts.trifecta) {
        const { combination, amount } = payout;
        // ...
      }
    }
  }
}
```

---

## Ruby

```ruby
require 'net/http'
require 'json'
require 'uri'

date = '20260501'
year = date[0, 4]
url = URI("https://boatraceopenapi.github.io/api/v1/#{year}/#{date}.json")

response = Net::HTTP.get_response(url)
raise "HTTP #{response.code}" unless response.is_a?(Net::HTTPSuccess)
data = JSON.parse(response.body)

stadiums = data['programs']['stadiums']

stadiums.each do |stadium_number, stadium|
  stadium['races'].each do |race_number, race|
    # 出走表の基本情報
    title = race['title']
    subtitle = race['subtitle']

    # 出走表の選手情報
    race['racers'].each do |entry_number, racer|
      name = racer['name']
      # ...
    end

    preview = race['preview']
    if preview
      # 直前情報の基本情報
      weather = preview['weather_number_source']
      # ...
    end

    result = race['result']
    next unless result

    # 結果の基本情報
    technique = result['technique_number_source']

    # 結果の選手情報
    result['racers'].each do |entry_number, racer|
      place = racer['place_number']
      name = racer['name']
      # ...
    end

    # 結果の払戻情報
    result['payouts']['trifecta'].each do |payout|
      combination = payout['combination']
      amount = payout['amount']
      # ...
    end
  end
end
```

---

## PHP

```php
<?php

$date = '20260501';
$year = substr($date, 0, 4);
$url = "https://boatraceopenapi.github.io/api/v1/{$year}/{$date}.json";

$response = file_get_contents($url);
if ($response === false) {
    throw new Exception('Failed to fetch data');
}
$data = json_decode($response, true);

$stadiums = $data['programs']['stadiums'];

foreach ($stadiums as $stadiumNumber => $stadium) {
    foreach ($stadium['races'] as $raceNumber => $race) {
        // 出走表の基本情報
        $title = $race['title'];
        $subtitle = $race['subtitle'];

        // 出走表の選手情報
        foreach ($race['racers'] as $entryNumber => $racer) {
            $name = $racer['name'];
            // ...
        }

        $preview = $race['preview'] ?? null;
        if ($preview !== null) {
            // 直前情報の基本情報
            $weather = $preview['weather_number_source'];
            // ...
        }

        $result = $race['result'] ?? null;
        if ($result !== null) {
            // 結果の基本情報
            $technique = $result['technique_number_source'];

            // 結果の選手情報
            foreach ($result['racers'] as $entryNumber => $racer) {
                $place = $racer['place_number'];
                $name = $racer['name'];
                // ...
            }

            // 結果の払戻情報
            foreach ($result['payouts']['trifecta'] as $payout) {
                $combination = $payout['combination'];
                $amount = $payout['amount'];
                // ...
            }
        }
    }
}
```
