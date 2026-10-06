#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Long-running config-sync refresh worker for Supervisor/systemd.
 *
 * Usage:
 *   FLAGMINT_SDK_KEY=fm_... php bin/config-sync-worker.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Flagmint\Client;
use Flagmint\ConfigSync\ConfigSyncWorker;

$apiKey = getenv('FLAGMINT_SDK_KEY') ?: getenv('FLAGMINT_API_KEY') ?: '';
if ($apiKey === '') {
    fwrite(STDERR, "FLAGMINT_SDK_KEY (or FLAGMINT_API_KEY) is required\n");
    exit(1);
}

$interval = (int) (getenv('FLAGMINT_REFRESH_INTERVAL') ?: 30);
$client = new Client([
    'apiKey' => $apiKey,
    'env' => getenv('FLAGMINT_ENV') ?: 'production',
]);
$client->ready();

$worker = new ConfigSyncWorker($client, $interval);
$worker->run();
