<?php

declare(strict_types=1);

namespace BOA\API;

use Carbon\CarbonImmutable as Carbon;
use DateTimeInterface;
use RuntimeException;
use Throwable;
use Turnmark\Scraper\BatchScraper;
use Turnmark\Scraper\Scraper;
use ValueError;

/**
 * @author shimomo
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
     * @param \DateTimeInterface|non-empty-string $date
     * @param non-empty-string $version
     * @return void
     * @throws RuntimeException
     */
    public static function sync(DateTimeInterface|string $date = 'today', string $version = self::VERSION): void
    {
        date_default_timezone_set(self::TIMEZONE);

        $startedAt = microtime(true);

        self::assertSupportedVersion($version);

        $date = Carbon::parse($date, self::TIMEZONE);
        $dateYmd = $date->format('Ymd');

        ActionsLogger::startGroup("Synchronizer::sync [{$dateYmd} / {$version}]");
        ActionsLogger::info("date={$date->toDateString()} version={$version}");

        try {
            $payload = ['programs' => []];

            Scraper::setMinCallIntervalSeconds(1.0);

            $program = BatchScraper::scrapeProgram($date);
            $preview = BatchScraper::scrapePreview($date);
            $result = BatchScraper::scrapeResult($date);

            $raceCount = 0;

            foreach ($program as $stadiumNumber => $races) {
                foreach ($races as $raceNumber => $race) {
                    $race['preview'] = $preview[$stadiumNumber][$raceNumber] ?? new \stdClass();
                    $race['result'] = $result[$stadiumNumber][$raceNumber] ?? new \stdClass();

                    $payload['programs']['stadiums'][$stadiumNumber]['races'][$raceNumber] = $race;

                    $raceCount++;
                }
            }

            $stadiumCount = count($payload['programs']['stadiums'] ?? []);
            ActionsLogger::info("scraped: stadiums={$stadiumCount} races={$raceCount}");

            if ($payload['programs'] === []) {
                ActionsLogger::warning("no programs found for {$dateYmd} ({$version}); skipped saving");

                return;
            }

            self::persist($date, $version, $payload);

            ActionsLogger::info("saved: {$stadiumCount} stadiums / {$raceCount} races");
        } catch (Throwable $exception) {
            ActionsLogger::error("sync failed: {$exception->getMessage()}");

            throw $exception;
        } finally {
            $elapsed = number_format(microtime(true) - $startedAt, 3);
            ActionsLogger::info("elapsed={$elapsed}s");
            ActionsLogger::endGroup();
        }
    }

    /**
     * @param \DateTimeInterface|non-empty-string $date
     * @param non-empty-string $version
     * @return void
     * @throws RuntimeException
     */
    public static function syncUpcoming(DateTimeInterface|string $date = 'today', string $version = self::VERSION): void
    {
        date_default_timezone_set(self::TIMEZONE);

        $startedAt = microtime(true);

        self::assertSupportedVersion($version);

        $date = Carbon::parse($date, self::TIMEZONE);
        $dateYmd = $date->format('Ymd');

        ActionsLogger::startGroup("Synchronizer::syncUpcoming [{$dateYmd} / {$version}]");
        ActionsLogger::info("date={$date->toDateString()} version={$version}");

        try {
            try {
                /**
                 * @var array{
                 *   programs: array{
                 *     stadiums?: array<int<1, 24>, array{
                 *       races?: array<int<1, 12>, array<non-empty-string, mixed>>,
                 *     }>,
                 *   },
                 * } $payload
                 */
                $payload = Storage::load(self::resolvePath($date, $version));
            } catch (RuntimeException $exception) {
                ActionsLogger::warning(
                    "source file not found for {$dateYmd} ({$version}); skipped: {$exception->getMessage()}"
                );

                return;
            }

            $updatedCount = 0;
            $skippedCount = 0;

            Scraper::setMinCallIntervalSeconds(1.0);

            foreach (($payload['programs']['stadiums'] ?? []) as $stadiumNumber => $stadium) {
                foreach (($stadium['races'] ?? []) as $raceNumber => $race) {
                    /**
                     * @var array{
                     *   closed_at: ?non-empty-string,
                     *   preview: array<non-empty-string, mixed>,
                     *   result: array<non-empty-string, mixed>,
                     * } $race
                     */

                    $closedAt = $race['closed_at'] ?? null;

                    if ($closedAt === null || !self::shouldRefresh($closedAt, $race['result'])) {
                        $skippedCount++;

                        continue;
                    }

                    try {
                        $program = Scraper::scrapeProgram($date, $stadiumNumber, $raceNumber);
                    } catch (ValueError $exception) {
                        ActionsLogger::warning(
                            "program scrape failed: stadium={$stadiumNumber} race={$raceNumber} closed_at={$closedAt} message={$exception->getMessage()}"
                        );

                        $skippedCount++;

                        continue;
                    }

                    try {
                        $preview = Scraper::scrapePreview($date, $stadiumNumber, $raceNumber);
                    } catch (ValueError $exception) {
                        ActionsLogger::warning(
                            "preview scrape failed: stadium={$stadiumNumber} race={$raceNumber} closed_at={$closedAt} continuing without preview: {$exception->getMessage()}"
                        );

                        /** @var array<non-empty-string, mixed> $preview */
                        $preview = $race['preview'];
                    }

                    try {
                        $result = Scraper::scrapeResult($date, $stadiumNumber, $raceNumber);
                    } catch (ValueError $exception) {
                        ActionsLogger::warning(
                            "result scrape failed: stadium={$stadiumNumber} race={$raceNumber} closed_at={$closedAt} continuing without result: {$exception->getMessage()}"
                        );

                        /** @var array<non-empty-string, mixed> $result */
                        $result = $race['result'];
                    }

                    $program['preview'] = $preview;
                    $program['result'] = $result;

                    $payload['programs']['stadiums'][$stadiumNumber]['races'][$raceNumber] = $program;

                    $updatedCount++;

                    ActionsLogger::info(
                        "updated: stadium={$stadiumNumber} race={$raceNumber} closed_at={$closedAt}"
                    );
                }
            }

            ActionsLogger::info("updated={$updatedCount} skipped={$skippedCount}");

            self::persist($date, $version, $payload);

            ActionsLogger::info('saved');
        } catch (Throwable $exception) {
            ActionsLogger::error("syncUpcoming failed: {$exception->getMessage()}");

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
     * @param non-empty-string $closedAt
     * @param array<non-empty-string, mixed> $result
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
