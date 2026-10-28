<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BOA\API\Synchronizer;
use Carbon\CarbonImmutable as Carbon;

/**
 * 指定した期間の JSON を 1 日ずつ取り直す。
 *
 * 使い方: php bin/sync-range.php START_DATE END_DATE [VERSION]
 *
 * 失敗した日は間隔を空けて再試行し、それでも失敗した日は飛ばして最後に一覧を出す（終了コード 1）。
 * 途中で止めたときは、続きから再開するコマンドを出す。夜間だけ遡るときは bin/backfill.sh から呼ぶ。
 */

const TIMEZONE = 'Asia/Tokyo';
const MAX_ATTEMPTS = 3;
const RETRY_WAIT_SECONDS = 30;

/**
 * @param float $seconds
 * @return string
 */
function formatDuration(float $seconds): string
{
    $seconds = (int) max(0, $seconds);

    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);
}

date_default_timezone_set(TIMEZONE);

if (!isset($argv[1], $argv[2])) {
    echo 'Usage: php bin/sync-range.php START_DATE END_DATE [VERSION]' . PHP_EOL;

    exit(1);
}

$startDate = Carbon::parse($argv[1], TIMEZONE)->startOfDay();
$endDate = Carbon::parse($argv[2], TIMEZONE)->startOfDay();
$version = $argv[3] ?? '';

if ($version === '') {
    $version = 'v1';
}

if ($startDate->gt($endDate)) {
    echo "開始日 ({$startDate->toDateString()}) が終了日 ({$endDate->toDateString()}) より後です。" . PHP_EOL;

    exit(1);
}

$dates = [];

for ($date = $startDate; $date->lte($endDate); $date = $date->addDay()) {
    $dates[] = $date;
}

$total = count($dates);
$completedCount = 0;
$failedDates = [];
$startedAt = microtime(true);

echo "対象期間: {$startDate->toDateString()} 〜 {$endDate->toDateString()}（{$total}日 / {$version}）" . PHP_EOL;
echo PHP_EOL;

register_shutdown_function(
    static function () use (&$completedCount, $dates, $total, $endDate, $version): void {
        if ($completedCount >= $total) {
            return;
        }

        $resumeDate = $dates[$completedCount]->toDateString();

        echo PHP_EOL . '中断しました。再開するには:' . PHP_EOL;
        echo "  php bin/sync-range.php {$resumeDate} {$endDate->toDateString()} {$version}" . PHP_EOL;
    }
);

// Ctrl+C でも shutdown 関数を通して再開コマンドを出す
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, static fn(): never => exit(130));
}

foreach ($dates as $index => $date) {
    $elapsed = microtime(true) - $startedAt;
    $remaining = $index > 0 ? $elapsed / (float) $index * (float) ($total - $index) : null;

    printf(
        '[%d/%d] %s 処理開始... 経過=%s 残り=%s%s',
        $index + 1,
        $total,
        $date->toDateString(),
        formatDuration($elapsed),
        $remaining === null ? '--:--:--' : formatDuration($remaining),
        PHP_EOL,
    );

    $succeeded = false;

    for ($attempt = 1; $attempt <= MAX_ATTEMPTS && !$succeeded; $attempt++) {
        try {
            Synchronizer::sync($date, $version);

            $succeeded = true;
        } catch (Throwable $exception) {
            printf(
                '  %s 失敗 (%d/%d): %s%s',
                $date->toDateString(),
                $attempt,
                MAX_ATTEMPTS,
                $exception->getMessage(),
                PHP_EOL,
            );

            if ($attempt < MAX_ATTEMPTS) {
                sleep(RETRY_WAIT_SECONDS);
            }
        }
    }

    if (!$succeeded) {
        $failedDates[] = $date->toDateString();

        echo "  {$date->toDateString()} をスキップしました。" . PHP_EOL;
    }

    $completedCount = $index + 1;
}

echo PHP_EOL;
printf(
    '全日付の処理が完了しました。(成功=%d 失敗=%d 所要=%s)%s',
    $total - count($failedDates),
    count($failedDates),
    formatDuration(microtime(true) - $startedAt),
    PHP_EOL,
);

if ($failedDates !== []) {
    echo '失敗した日付: ' . implode(', ', $failedDates) . PHP_EOL;

    exit(1);
}
