<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BOA\API\Synchronizer;
use Carbon\CarbonImmutable as Carbon;

$version = $argv[1] ?? 'v1';

$startDate = Carbon::create(2026, 7, 3, 0, 0, 0, 'Asia/Tokyo');
$endDate = Carbon::create(2026, 7, 3, 0, 0, 0, 'Asia/Tokyo');

$date = $startDate;
while ($date->lte($endDate)) {
    echo "[{$date->format('Y-m-d')}] 処理開始..." . PHP_EOL;

    Synchronizer::sync($date, $version);

    $date = $date->addDay();
}

echo "全日付の処理が完了しました。" . PHP_EOL;
