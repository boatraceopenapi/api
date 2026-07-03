<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BOA\API\Synchronizer;
use Carbon\CarbonImmutable as Carbon;

$version = $argv[1] ?? 'v1';

Synchronizer::syncUpcoming(Carbon::today('Asia/Tokyo'), $version);
