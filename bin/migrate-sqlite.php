#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database\SQLiteConnection;
use App\Database\SQLiteGlucoseRepository;
use App\Database\SqliteHistoryImporter;
use App\Support\App;
use App\Support\Config;

$root = dirname(__DIR__);
$app = App::boot($root);
$config = $app->config;

$from = parseFromArgument($argv);
if ($from === null) {
    $from = firstExistingSqlite($config, $root);
}

if ($from === null) {
    fwrite(STDERR, "No v1 SQLite database found.\n");
    fwrite(STDERR, "Usage: php bin/migrate-sqlite.php [../data/glucose.sqlite]\n");
    exit(1);
}

try {
    $source = SqliteHistoryImporter::resolve($from);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$snapshot = SqliteHistoryImporter::snapshot($source);
try {
    $readings = (new SQLiteGlucoseRepository(SQLiteConnection::connect($snapshot)))->all();
} finally {
    @unlink($snapshot);
    @unlink($snapshot . '-wal');
    @unlink($snapshot . '-shm');
}

if ($readings === []) {
    fwrite(STDOUT, "No readings found in {$source}.\n");
    exit(0);
}

try {
    $writer = $app->bucketWriter();
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

// Group by the reading's own timestamp bucket. Bucket files are immutable, so
// run this with the poller stopped to avoid a partial bucket being rewritten.
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
$app->pollState()->save($app->pollState()->load()->emitted($timestamps));

echo "Source: {$source}\n";
echo 'Imported ' . count($readings) . ' readings into ' . count($buckets) . " bucket(s) under public/b/.\n";
echo "v1 SQLite was not modified. Re-run this command to catch readings collected since this import.\n";

/**
 * @param list<string> $argv
 */
function parseFromArgument(array $argv): ?string
{
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            fwrite(STDOUT, "Usage: php bin/migrate-sqlite.php [--from=]PATH\n");
            fwrite(STDOUT, "PATH may be a glucose.sqlite file or a directory containing one.\n");
            exit(0);
        }
        if (str_starts_with($arg, '--from=')) {
            return substr($arg, 7);
        }
        if ($arg !== '' && $arg[0] !== '-') {
            return $arg;
        }
    }

    return null;
}

function firstExistingSqlite(Config $config, string $root): ?string
{
    $candidates = [];
    if (str_ends_with($config->sqlitePath, '.sqlite')) {
        $candidates[] = $config->sqlitePath;
    }
    $candidates[] = $root . '/../data/glucose.sqlite';
    $candidates[] = $root . '/data/glucose.sqlite';

    foreach (array_unique($candidates) as $path) {
        if (is_file($path) || is_dir($path)) {
            try {
                return SqliteHistoryImporter::resolve($path);
            } catch (RuntimeException) {
                continue;
            }
        }
    }

    return null;
}
