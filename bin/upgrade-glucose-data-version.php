#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database\EncryptedGlucoseRepository;
use App\DTO\GlucoseReadingDTO;
use App\Export\DenseHistoryCsv;
use App\Support\App;

$root = dirname(__DIR__);
[$source, $output] = parseArgs($argv, $root);

if (!is_file($source)) {
    fwrite(STDERR, "No v2 JSON history store found at {$source}.\n");
    fwrite(STDERR, "Usage: php bin/upgrade-glucose-data-version.php [data/glucose.json.asc|history.json] [-o mylibre.history.csv]\n");
    exit(1);
}

$raw = file_get_contents($source);
if ($raw === false) {
    fwrite(STDERR, "Unable to read {$source}.\n");
    exit(1);
}

try {
    $readings = isPgp($raw)
        ? readingsFromEncrypted($root, $source)
        : readingsFromJson($raw);
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

if ($readings === []) {
    fwrite(STDOUT, "No readings found in {$source}.\n");
    exit(0);
}

$csv = DenseHistoryCsv::encode($readings);
$days = substr_count($csv, "\n");

if ($output === null) {
    fwrite(STDOUT, $csv);
    fwrite(STDERR, "Source: {$source}\n");
    fwrite(STDERR, 'Wrote ' . count($readings) . " readings into {$days} UTC day line(s) (dense 1440-slot CSV).\n");
    exit(0);
}

if (file_put_contents($output, $csv) === false) {
    fwrite(STDERR, "Unable to write {$output}.\n");
    exit(1);
}

echo "Source: {$source}\n";
echo "Wrote " . count($readings) . " readings into {$days} UTC day line(s) at {$output}.\n";
echo "The JSON store was not modified. Import the CSV in the dashboard.\n";

/**
 * @param list<string> $argv
 * @return array{0: string, 1: ?string}
 */
function parseArgs(array $argv, string $root): array
{
    $source = null;
    $output = null;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            fwrite(STDOUT, "Usage: php bin/upgrade-glucose-data-version.php [SOURCE] [-o FILE]\n");
            fwrite(STDOUT, "SOURCE is data/glucose.json.asc (default) or a plaintext JSON export of the same objects.\n");
            fwrite(STDOUT, "Writes dense one-day-per-line CSV to stdout, or to FILE with -o.\n");
            exit(0);
        }
        if (str_starts_with($arg, '--from=')) {
            $source = substr($arg, 7);
            continue;
        }
        if (str_starts_with($arg, '--output=')) {
            $output = substr($arg, 9);
            continue;
        }
        if ($arg === '-o' || $arg === '--output') {
            $output = '';
            continue;
        }
        if ($output === '') {
            $output = $arg;
            continue;
        }
        if ($source === null && !str_starts_with($arg, '-')) {
            $source = $arg;
        }
    }

    if ($output === '') {
        fwrite(STDERR, "-o requires a path.\n");
        exit(1);
    }

    $app = App::boot($root);
    $source ??= $app->config->dataPath;

    return [$source, $output];
}

function isPgp(string $raw): bool
{
    return str_contains($raw, 'BEGIN PGP MESSAGE');
}

/**
 * @return GlucoseReadingDTO[]
 */
function readingsFromEncrypted(string $root, string $path): array
{
    $app = App::boot($root);
    $repository = new EncryptedGlucoseRepository($path, $app->crypto(), $app->config->unlockPassphrase);

    return $repository->all();
}

/**
 * @return GlucoseReadingDTO[]
 */
function readingsFromJson(string $raw): array
{
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $rows = is_array($data) && array_is_list($data)
        ? $data
        : (is_array($data) && isset($data['readings']) && is_array($data['readings']) ? $data['readings'] : null);
    if ($rows === null) {
        throw new RuntimeException('JSON is not the v2 glucose object array (timestamp + glucoseMgDl).');
    }

    /** @var list<array<string, mixed>> $rows */
    return DenseHistoryCsv::readingsFromJsonRows($rows);
}
