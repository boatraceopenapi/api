<?php

declare(strict_types=1);

namespace BOA\API;

use Carbon\CarbonImmutable as Carbon;
use DateTimeInterface;
use RuntimeException;
use Throwable;
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
     * @var non-empty-list<non-empty-string>
     */
    private const array PAYOUT_KEYS = [
        'trifecta',
        'trio',
        'exacta',
        'quinella',
        'quinella_place',
        'win',
        'place',
    ];

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

            $programBulk = Scraper::scrapeProgramBulk($date);
            $previewBulk = Scraper::scrapePreviewBulk($date);
            $resultBulk = Scraper::scrapeResultBulk($date);

            $raceCount = 0;

            foreach ($programBulk as $stadiumNumber => $items) {
                foreach ($items as $raceNumber => $program) {
                    $preview = $previewBulk[$stadiumNumber][$raceNumber] ?? [];
                    $result = $resultBulk[$stadiumNumber][$raceNumber] ?? [];

                    $payload['programs']['stadiums'][$stadiumNumber]['races'][$raceNumber]
                        = self::buildRace($program, $preview, $result);

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

                    if ($closedAt === null || !self::isWithinThirtyMinutes($closedAt)) {
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

                    $payload['programs']['stadiums'][$stadiumNumber]['races'][$raceNumber]
                        = self::buildRace($program, $preview, $result);

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
     * @param array<non-empty-string, mixed> $program
     * @param array<non-empty-string, mixed> $preview
     * @param array<non-empty-string, mixed> $result
     * @return array<non-empty-string, mixed>
     */
    private static function buildRace(array $program, array $preview, array $result): array
    {
        /**
         * @var array{
         *   payouts: array<non-empty-string, array<mixed>>,
         * } $result
         */
        $result['payouts'] = self::normalizeObject($result['payouts'] ?? [], self::PAYOUT_KEYS);

        $program['preview'] = $preview;
        $program['result'] = $result;

        return $program;
    }

    /**
     * @param non-empty-string $closedAt
     * @return bool
     */
    private static function isWithinThirtyMinutes(string $closedAt): bool
    {
        return Carbon::parse($closedAt, self::TIMEZONE)->between(
            Carbon::now(self::TIMEZONE)->subMinutes(30),
            Carbon::now(self::TIMEZONE)->addMinutes(30),
        );
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

    /**
     * @param array<non-empty-string, mixed> $payload
     * @param list<non-empty-string> $keys
     * @return array<non-empty-string, mixed>
     */
    private static function normalizeObject(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && $payload[$key] === []) {
                $payload[$key] = new \stdClass();
            }
        }

        return $payload;
    }
}
