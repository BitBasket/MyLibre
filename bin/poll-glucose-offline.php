#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Export\CsvHistoryStore;
use App\LibreLink\LibreLinkUpProvider;
use App\Poller\GlucosePoller;
use App\Support\Env;

$root = dirname(__DIR__);
$app = App\Support\App::boot($root);
$once = false;
$csvPath = $root . '/data/mylibre.history.csv';

$takeOutput = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($takeOutput) {
        $csvPath = $arg;
        $takeOutput = false;
        continue;
    }
    if ($arg === '--once') {
        $once = true;
        continue;
    }
    if ($arg === '-o' || $arg === '--output') {
        $takeOutput = true;
        continue;
    }
    if (str_starts_with($arg, '--output=')) {
        $csvPath = substr($arg, 9);
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php bin/poll-glucose-offline.php [--once] [-o FILE]\n");
        fwrite(STDOUT, "Polls LibreLinkUp with LIBRELINK_EMAIL and LIBRELINK_PASSWORD from .env\n");
        fwrite(STDOUT, "and merges readings into a dense 1440-slot CSV the dashboard Import button\n");
        fwrite(STDOUT, "accepts (mylibre.history.csv). Does not wait for the dashboard login form.\n");
        fwrite(STDOUT, "Default file: data/mylibre.history.csv\n");
        exit(0);
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(1);
}

if ($takeOutput) {
    fwrite(STDERR, "-o requires a path.\n");
    exit(1);
}

if (!str_starts_with($csvPath, '/')) {
    $csvPath = $root . '/' . $csvPath;
}

$csv = new CsvHistoryStore($csvPath);
$app->logger->info('Writing importable history CSV to ' . $csv->path());

$provider = $app->provider();
if ($provider instanceof LibreLinkUpProvider) {
    $email = Env::fromDotEnv('LIBRELINK_EMAIL') ?: $app->config->libreLinkEmail;
    $password = Env::fromDotEnv('LIBRELINK_PASSWORD') ?: $app->config->libreLinkPassword;
    if ($email === '' || $password === '') {
        $app->logger->error('Set LIBRELINK_EMAIL and LIBRELINK_PASSWORD in .env');
        exit(1);
    }
    $patientId = $app->config->libreLinkPatientId !== '' ? $app->config->libreLinkPatientId : null;
    $app->logger->info('LibreLinkUp login from .env as ' . $email);
    $provider->login($email, $password, $patientId);
}

$poller = new GlucosePoller(
    $provider,
    $app->pollState(),
    $app->bucketWriter(),
    $app->logger,
    $app->config->abbottPollSeconds,
    persistHistory: true,
    csv: $csv,
);

$poller->run($once);
