<?php

declare(strict_types=1);

namespace BOA\API;

use Carbon\CarbonImmutable as Carbon;
use Closure;
use DateTimeInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\BrowserKit\Response;
use Throwable;
use Turnmark\Scraper\BatchScraper;
use Turnmark\Scraper\Factories\HttpBrowserFactory;
use Turnmark\Scraper\Scraper;
use ValueError;

/**
 * @author shimomo
 *
 * @psalm-type Race = array<array-key, mixed>
 * @psalm-type Payload = array{programs: array{stadiums?: array<int<1, 24>, array{races?: array<int<1, 12>, Race>}>}}
 */
final class Synchronizer
{
    /**
     * @var non-empty-string
     */
    private const string TIMEZONE = 'Asia/Tokyo';

    /**
     * @var non-empty-string
     */
    private const string VERSION = 'v1';

    /**
     * @var non-empty-list<non-empty-string>
     */
    private const array SUPPORTED_VERSIONS = ['v1'];

    /**
     * syncUpcoming() が取り直す範囲（締切の何分前から）。直前情報は締切の 30〜7 分前に出揃う
     *
     * @var positive-int
     */
    private const int MINUTES_BEFORE_CLOSE = 30;

    /**
     * syncUpcoming() が取り直す範囲（締切の何分後まで）。結果は締切の 8〜31 分後に入るので、
     * 10 分間隔の起動でも 30 分を過ぎてから最低 1 回は取り直せるよう 40 分にしている
     *
     * @var positive-int
     */
    private const int MINUTES_AFTER_CLOSE = 40;

    /**
     * 結果（3 連単の払戻）がまだ入っていないレースは、締切のこの分数後まで取り直し続ける。
     * 水面状況などで発売・発走が遅れても closed_at が更新されないことがあり、
     * 2026-10 には締切後 30 分のあいだ取り直しても結果が入らなかったレースが約 0.4% あった
     *
     * @var positive-int
     */
    private const int MINUTES_AFTER_CLOSE_UNTIL_RESULT = 120;

    /**
     * 公式サイトへのリクエスト間隔の下限（秒）。スクレイパーの既定は 3 秒で、1 秒より短くはできない
     *
     * @var float
     */
    private const float MIN_CALL_INTERVAL_SECONDS = 1.0;

    /**
     * 指定した日の全レース（出走表・直前情報・結果）を取り直し、その日のファイルを作り直す。
     * 当日なら today.json も書き換える。0 時に前日分の確定と当日分の作成に使うほか、
     * 当日分のファイルが無いときは syncUpcoming() からも呼ばれる。
     *
     * 締切時刻（closed_at）が取れなかったレースは、取得に失敗したものとして既存のファイルのデータを残す。
     * 全レースで取れなかったときは、取得そのものが失敗しているので保存せずに例外を投げる。
     *
     * $minCallIntervalSeconds は、過去分を長時間かけて取り直すとき（bin/sync-range.php）に間隔を伸ばすためのもの
     *
     * @param \DateTimeInterface|non-empty-string $date
     * @param non-empty-string $version
     * @param float $minCallIntervalSeconds
     * @return void
     * @throws RuntimeException
     * @throws ValueError
     */
    public static function sync(
        DateTimeInterface|string $date = 'today',
        string $version = self::VERSION,
        float $minCallIntervalSeconds = self::MIN_CALL_INTERVAL_SECONDS,
    ): void {
        date_default_timezone_set(self::TIMEZONE);

        self::assertSupportedVersion($version);
        self::assertValidMinCallInterval($minCallIntervalSeconds);

        $date = Carbon::parse($date, self::TIMEZONE);

        self::runInGroup('sync', $date, $version, static function () use ($date, $version, $minCallIntervalSeconds): void {
            $dateYmd = $date->format('Ymd');

            Scraper::setMinCallIntervalSeconds($minCallIntervalSeconds);
            ActionsLogger::info("min_call_interval={$minCallIntervalSeconds}s");

            $program = BatchScraper::scrapeProgram($date);
            $preview = BatchScraper::scrapePreview($date);
            $result = BatchScraper::scrapeResult($date);

            $payload = ['programs' => []];
            $raceCount = 0;

            foreach ($program as $stadiumNumber => $races) {
                foreach ($races as $raceNumber => $race) {
                    $race['preview'] = $preview[$stadiumNumber][$raceNumber] ?? new stdClass();
                    $race['result'] = $result[$stadiumNumber][$raceNumber] ?? new stdClass();

                    $payload['programs']['stadiums'][$stadiumNumber]['races'][$raceNumber] = $race;

                    $raceCount++;
                }
            }

            $stadiumCount = count($payload['programs']['stadiums'] ?? []);
            ActionsLogger::info("scraped: stadiums={$stadiumCount} races={$raceCount}");

            if ($payload['programs'] === []) {
                ActionsLogger::warning("no programs found; skipped saving: date={$dateYmd} version={$version}");

                return;
            }

            $usableCount = self::countUsableRaces($payload);

            if ($usableCount === 0) {
                throw new RuntimeException(
                    "no race had closed_at; refused to save over existing data: date={$dateYmd} version={$version}"
                );
            }

            if ($usableCount < $raceCount) {
                $payload = self::keepExistingRaces($payload, self::loadExisting($date, $version));
                $unusableCount = $raceCount - $usableCount;
                $keptCount = self::countUsableRaces($payload) - $usableCount;

                ActionsLogger::warning(
                    "races without closed_at; kept existing data: date={$dateYmd} unusable={$unusableCount} kept={$keptCount}"
                );
            }

            self::persist($date, $version, $payload);

            ActionsLogger::info("saved: stadiums={$stadiumCount} races={$raceCount}");
        });
    }

    /**
     * 締切が近いレースと、締切後まだ結果が入っていないレースだけを取り直して保存する（範囲は shouldRefresh()）。
     * 日中 10 分おきに呼ばれる。
     *
     * 出走表の締切時刻が取れなかったレースは、取得に失敗したものとして既存のデータを残し、次の回に取り直す。
     * 当日分のファイルが無いとき（0 時の sync() が作れなかった日）は、代わりに sync() で作る
     *
     * @param \DateTimeInterface|non-empty-string $date
     * @param non-empty-string $version
     * @return void
     * @throws RuntimeException
     */
    public static function syncUpcoming(DateTimeInterface|string $date = 'today', string $version = self::VERSION): void
    {
        date_default_timezone_set(self::TIMEZONE);

        self::assertSupportedVersion($version);

        $date = Carbon::parse($date, self::TIMEZONE);

        if (!file_exists(self::resolvePath($date, $version))) {
            // このままだと today.json が前日のまま 1 日残るので、ここで作る。
            // sync() も自分のロググループを開くので、グループの外で呼ぶ
            ActionsLogger::warning(
                "source file not found; falling back to sync(): date={$date->format('Ymd')} version={$version}"
            );

            self::sync($date, $version);

            return;
        }

        self::runInGroup('syncUpcoming', $date, $version, static function () use ($date, $version): void {
            try {
                /** @var Payload $payload */
                $payload = Storage::load(self::resolvePath($date, $version));
            } catch (RuntimeException $exception) {
                ActionsLogger::warning("source file could not be loaded; skipped: message={$exception->getMessage()}");

                return;
            }

            $updatedCount = 0;
            $skippedCount = 0;

            Scraper::setMinCallIntervalSeconds(self::MIN_CALL_INTERVAL_SECONDS);

            foreach (($payload['programs']['stadiums'] ?? []) as $stadiumNumber => $stadium) {
                foreach (($stadium['races'] ?? []) as $raceNumber => $race) {
                    $closedAt = $race['closed_at'] ?? null;
                    $existingPreview = self::arrayOrEmpty($race['preview'] ?? null);
                    $existingResult = self::arrayOrEmpty($race['result'] ?? null);

                    if (!is_string($closedAt) || $closedAt === '' || !self::shouldRefresh($closedAt, $existingResult)) {
                        $skippedCount++;

                        continue;
                    }

                    $context = "stadium={$stadiumNumber} race={$raceNumber} closed_at={$closedAt}";

                    try {
                        // 空振りしたときに応答の中身をログに出せるよう、ブラウザを自分で作って渡す
                        // （中身はスクレイパーが既定で作るものと同じで、レースごとに作り直す）
                        $httpBrowser = HttpBrowserFactory::create();
                        $program = Scraper::scrapeProgram($date, $stadiumNumber, $raceNumber, $httpBrowser);
                    } catch (ValueError $exception) {
                        ActionsLogger::warning("program scrape failed; skipped: {$context} message={$exception->getMessage()}");

                        $skippedCount++;

                        continue;
                    }

                    if (!self::isUsableRace($program)) {
                        ActionsLogger::warning("program scrape returned no closed_at; kept existing data: {$context}");
                        ActionsLogger::warning('program response: ' . self::describeLastResponse($httpBrowser));

                        $skippedCount++;

                        continue;
                    }

                    try {
                        $preview = Scraper::scrapePreview($date, $stadiumNumber, $raceNumber);
                    } catch (ValueError $exception) {
                        ActionsLogger::warning("preview scrape failed; kept existing preview: {$context} message={$exception->getMessage()}");

                        $preview = $existingPreview;
                    }

                    try {
                        $result = Scraper::scrapeResult($date, $stadiumNumber, $raceNumber);
                    } catch (ValueError $exception) {
                        ActionsLogger::warning("result scrape failed; kept existing result: {$context} message={$exception->getMessage()}");

                        $result = $existingResult;
                    }

                    $program['preview'] = $preview;
                    $program['result'] = $result;

                    $payload['programs']['stadiums'][$stadiumNumber]['races'][$raceNumber] = $program;

                    $updatedCount++;

                    ActionsLogger::info("updated: {$context}");
                }
            }

            self::persist($date, $version, $payload);

            ActionsLogger::info("saved: updated={$updatedCount} skipped={$skippedCount}");
        });
    }

    /**
     * 出走表ページとして読めない応答を受けると、スクレイパーは例外を投げずに全項目 null を返す。
     * そのまま保存すると既存のデータが消え、closed_at が null になったレースは以後取り直されないので、
     * 締切時刻が取れなかったレースのデータは使わない
     *
     * @internal
     * @param Race $race
     * @return bool
     */
    public static function isUsableRace(array $race): bool
    {
        return ($race['closed_at'] ?? null) !== null;
    }

    /**
     * @internal
     * @param Payload $payload
     * @return int<0, max>
     */
    public static function countUsableRaces(array $payload): int
    {
        $count = 0;

        foreach (($payload['programs']['stadiums'] ?? []) as $stadium) {
            foreach (($stadium['races'] ?? []) as $race) {
                if (self::isUsableRace($race)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * 締切時刻が取れなかったレースを、既存のファイルにある使えるデータで置き換える。
     * 0 時の同期は前日分も作り直すので、取得に失敗した回が確定済みのデータを消さないようにする
     *
     * @internal
     * @param Payload $payload
     * @param Payload|array{} $existing
     * @return Payload
     */
    public static function keepExistingRaces(array $payload, array $existing): array
    {
        foreach (($payload['programs']['stadiums'] ?? []) as $stadiumNumber => $stadium) {
            foreach (($stadium['races'] ?? []) as $raceNumber => $race) {
                $existingRace = $existing['programs']['stadiums'][$stadiumNumber]['races'][$raceNumber] ?? null;

                if (self::isUsableRace($race) || $existingRace === null || !self::isUsableRace($existingRace)) {
                    continue;
                }

                $payload['programs']['stadiums'][$stadiumNumber]['races'][$raceNumber] = $existingRace;
            }
        }

        return $payload;
    }

    /**
     * @param \DateTimeInterface $date
     * @param non-empty-string $version
     * @return Payload|array{}
     */
    private static function loadExisting(DateTimeInterface $date, string $version): array
    {
        $path = self::resolvePath($date, $version);

        if (!file_exists($path)) {
            return [];
        }

        try {
            /** @var Payload */
            return Storage::load($path);
        } catch (RuntimeException $exception) {
            ActionsLogger::warning(
                "existing file could not be loaded; nothing to keep: message={$exception->getMessage()}"
            );

            return [];
        }
    }

    /**
     * 締切の MINUTES_BEFORE_CLOSE 分前〜MINUTES_AFTER_CLOSE 分後のレースは、直前情報と結果を取るため必ず取り直す。
     * その後も MINUTES_AFTER_CLOSE_UNTIL_RESULT 分後までは、結果（3 連単の払戻）が入っていなければ取り直す
     *
     * @param non-empty-string $closedAt
     * @param array<array-key, mixed> $result
     * @return bool
     */
    private static function shouldRefresh(string $closedAt, array $result): bool
    {
        $closedAt = Carbon::parse($closedAt, self::TIMEZONE);
        $now = Carbon::now(self::TIMEZONE);

        if ($closedAt->between(
            $now->subMinutes(self::MINUTES_AFTER_CLOSE),
            $now->addMinutes(self::MINUTES_BEFORE_CLOSE),
        )) {
            return true;
        }

        if (!$closedAt->between(
            $now->subMinutes(self::MINUTES_AFTER_CLOSE_UNTIL_RESULT),
            $now,
        )) {
            return false;
        }

        /** @var array{payouts?: array{trifecta?: list<mixed>}} $result */
        return ($result['payouts']['trifecta'] ?? []) === [];
    }

    /**
     * 空振りした応答の中身を 1 行にまとめる。公式サイトが何を返しているのか（エラー、待機ページ、別のページ）を
     * 後から確かめるためのもので、2026-11-18 からの空振りの原因はまだ分かっていない
     *
     * @internal
     * @param \Symfony\Component\BrowserKit\Response $response
     * @param string $uri
     * @return string
     */
    public static function describeResponse(Response $response, string $uri): string
    {
        $content = $response->getContent();

        $parts = [
            "status={$response->getStatusCode()}",
            "url={$uri}",
        ];

        $title = preg_match('/<title[^>]*>(.*?)<\/title>/is', $content, $matches) === 1
            ? self::squash(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : '';

        $parts[] = $title === '' ? 'title=(none)' : 'title="' . $title . '"';
        $parts[] = 'length=' . strlen($content);

        foreach (['content-type', 'server', 'retry-after', 'location'] as $name) {
            $value = $response->getHeader($name);

            if (is_string($value) && $value !== '') {
                $parts[] = "{$name}=\"{$value}\"";
            }
        }

        if ($title === '') {
            $body = self::squash(strip_tags($content));

            if ($body !== '') {
                $parts[] = 'body="' . (mb_strlen($body) > 200 ? mb_substr($body, 0, 200) . '…' : $body) . '"';
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param \Symfony\Component\BrowserKit\HttpBrowser $httpBrowser
     * @return string
     */
    private static function describeLastResponse(HttpBrowser $httpBrowser): string
    {
        try {
            /** @var \Symfony\Component\BrowserKit\Response $response */
            $response = $httpBrowser->getInternalResponse();

            return self::describeResponse($response, $httpBrowser->getHistory()->current()->getUri());
        } catch (Throwable $exception) {
            return "(could not read the response: {$exception->getMessage()})";
        }
    }

    /**
     * 保存済みのファイルでは、直前情報や結果がまだ無いレースは空の配列になっている
     *
     * @param mixed $value
     * @return array<array-key, mixed>
     */
    private static function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param string $text
     * @return string
     */
    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * sync() と syncUpcoming() の共通の外枠。ロググループを開き、失敗したらログに出して投げ直し、
     * 最後に経過時間を出してグループを閉じる
     *
     * @param non-empty-string $name
     * @param \DateTimeInterface $date
     * @param non-empty-string $version
     * @param \Closure(): void $callback
     * @return void
     */
    private static function runInGroup(string $name, DateTimeInterface $date, string $version, Closure $callback): void
    {
        $startedAt = microtime(true);
        $dateYmd = $date->format('Ymd');

        ActionsLogger::startGroup("Synchronizer::{$name} [{$dateYmd} / {$version}]");
        ActionsLogger::info("date={$date->format('Y-m-d')} version={$version}");

        try {
            $callback();
        } catch (Throwable $exception) {
            ActionsLogger::error("{$name} failed: date={$dateYmd} version={$version} message={$exception->getMessage()}");

            throw $exception;
        } finally {
            $elapsed = number_format(microtime(true) - $startedAt, 3);
            ActionsLogger::info("elapsed={$elapsed}s");
            ActionsLogger::endGroup();
        }
    }

    /**
     * @param non-empty-string $version
     * @return void
     * @throws RuntimeException
     */
    private static function assertSupportedVersion(string $version): void
    {
        if (!in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw new RuntimeException("Unsupported version: {$version}");
        }
    }

    /**
     * スクレイパーも 1 秒未満を弾くが、取得を始める前（ロググループを開く前）に止める
     *
     * @param float $seconds
     * @return void
     * @throws ValueError
     */
    private static function assertValidMinCallInterval(float $seconds): void
    {
        if ($seconds < self::MIN_CALL_INTERVAL_SECONDS) {
            throw new ValueError(sprintf('$minCallIntervalSeconds must be 1 or greater, %s given.', $seconds));
        }
    }

    /**
     * @param \DateTimeInterface $date
     * @param non-empty-string $version
     * @param array<non-empty-string, mixed> $payload
     * @return void
     */
    private static function persist(DateTimeInterface $date, string $version, array $payload): void
    {
        Storage::save(self::resolvePath($date, $version), $payload);

        if (Carbon::parse($date, self::TIMEZONE)->isToday()) {
            Storage::save(self::resolveTodayPath($version), $payload);
        }
    }

    /**
     * @param \DateTimeInterface $date
     * @param non-empty-string $version
     * @return non-empty-string
     */
    private static function resolvePath(DateTimeInterface $date, string $version): string
    {
        $dateY = $date->format('Y');
        $dateYmd = $date->format('Ymd');

        return __DIR__ . "/../docs/{$version}/{$dateY}/{$dateYmd}.json";
    }

    /**
     * @param non-empty-string $version
     * @return non-empty-string
     */
    private static function resolveTodayPath(string $version): string
    {
        return __DIR__ . "/../docs/{$version}/today.json";
    }
}
