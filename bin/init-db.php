#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App\Support\App::boot(dirname(__DIR__));
$app->pdo();

$snapshot = new App\Export\DashboardSnapshot(
    $app->repository(),
    $app->config,
    $app->config->root . '/public',
);
$snapshot->write();

fwrite(STDOUT, "SQLite database ready at {$app->config->sqlitePath}\n");
fwrite(STDOUT, "Dashboard snapshot written to {$app->config->root}/public\n");
