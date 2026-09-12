#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App\Support\App::boot(dirname(__DIR__));
$once = in_array('--once', $argv, true);

$poller = new App\Poller\GlucosePoller(
    $app->provider(),
    $app->pollState(),
    $app->bucketWriter(),
    $app->logger,
    $app->config->abbottPollSeconds,
    persistHistory: true,
);

$poller->run($once);
