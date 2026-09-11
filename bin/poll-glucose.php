#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App\Support\App::boot(dirname(__DIR__));
$once = in_array('--once', $argv, true);

$snapshot = new App\Export\DashboardSnapshot(
    $app->repository(),
    $app->config,
    $app->config->root . '/public',
    $app->crypto(),
    $app->config->unlockPassphrase,
);

$poller = new App\Poller\GlucosePoller(
    $app->provider(),
    $app->repository(),
    $app->logger,
    $app->config->abbottPollSeconds,
    persistHistory: true,
    snapshot: $snapshot,
);

$poller->run($once);
