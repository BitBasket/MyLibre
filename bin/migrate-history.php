#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database\EncryptedGlucoseRepository;
use App\Support\App;

$root = dirname(__DIR__);
$app = App::boot($root);
$config = $app->config;

$store = $config->dataPath;
if (!is_file($store)) {
    fwrite(STDERR, "No v2 encrypted history store found at {$store}.\n");
    fwrite(STDERR, "If your history only exists in v1 SQLite, run: php bin/migrate-sqlite.php\n");
    exit(1);
}

// Reading the old store needs the old private key; writing the new buckets uses
// the recipient key. Run this with the poller stopped.
try {
    $repository = new EncryptedGlucoseRepository($store, $app->crypto(), $config->unlockPassphrase);
    $readings = $repository->all();
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

if ($readings === []) {
    fwrite(STDOUT, "No readings found in {$store}.\n");
    exit(0);
}

$writer = $app->bucketWriter();

// Group by the reading's own timestamp bucket. Bucket files are immutable, so
// run this once, before the poller has written any buckets of its own.
$buckets = [];
foreach ($readings as $reading) {
    $bucket = intdiv($reading->timestamp->getTimestamp(), $config->bucketSeconds) * $config->bucketSeconds;
    $buckets[$bucket][] = $reading;
}
ksort($buckets);
$writer->writeBatches($buckets);

$timestamps = array_values(array_unique(array_map(
    static fn ($reading): int => $reading->timestamp->getTimestamp(),
    $readings,
)));
$state = $app->pollState()->load()->emitted($timestamps);
$app->pollState()->save($state);

// Refresh the pointer files so the dashboard can read everything immediately.
$writer->writeCurrent($readings[array_key_last($readings)]);
$writer->writeStatus($state->firstReadingAt, $state->latest());

echo "Source: {$store}\n";
echo 'Wrote ' . count($readings) . ' readings into ' . count($buckets) . " bucket(s) under public/b/.\n";
echo "The v2 encrypted store was not modified. The poller will continue from here.\n";
