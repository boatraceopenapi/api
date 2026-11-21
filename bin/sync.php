<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BOA\API\Synchronizer;
use Carbon\CarbonImmutable as Carbon;

$version = $argv[1] ?? 'v1';

// 前日分が失敗しても当日分は作る（当日分が無いと today.json が前日のまま 1 日残る）。
// どちらかが失敗したらジョブは失敗にするが、成功した方の保存はコミットされる
$failed = false;

foreach ([Carbon::yesterday('Asia/Tokyo'), Carbon::today('Asia/Tokyo')] as $date) {
    try {
        Synchronizer::sync($date, $version);
    } catch (Throwable) {
        // sync() 自身がエラーをログに出している
        $failed = true;
    }
}

exit($failed ? 1 : 0);
