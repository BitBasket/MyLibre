#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Poller\GlucosePoller;
use App\Support\App;

$app = App::boot(dirname(__DIR__));
$once = in_array('--once', $argv, true);
$retrySeconds = 5;

// Bring the loopback login intake up first, so a fresh install can still reach
// /api/keys through the PHP API and enroll the user public key the writer needs.
$intake = $once ? null : $app->authIntake();
$wait = $intake === null
    ? null
    : static function (int $seconds) use ($intake): bool {
        return $intake->wait($seconds);
    };

$hasRecipient = static function () use ($app): bool {
    $path = $app->config->userPublicKeyPath;

    return $path !== '' && is_readable($path);
};

try {
    if ($once) {
        try {
            $writer = $app->bucketWriter();
        } catch (\Throwable $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            exit(1);
        }
    } else {
        // No usable user key yet: publish nothing, but keep the process (and
        // the intake socket) alive so the dashboard can POST its public key and
        // so serve.php does not tear the whole API down. A missing key is the
        // normal first-run state; a key that exists but will not load is
        // reported once and retried, not fatal.
        $writer = null;
        $reported = null;
        while ($writer === null) {
            try {
                if (!$hasRecipient()) {
                    throw new \RuntimeException('No user public key yet; waiting for the dashboard to enroll one at /api/keys.');
                }
                $writer = $app->bucketWriter();
            } catch (\Throwable $error) {
                if ($error->getMessage() !== $reported) {
                    fwrite(STDERR, $error->getMessage() . "\n");
                    $reported = $error->getMessage();
                }
                if ($wait !== null) {
                    $wait($retrySeconds);
                } else {
                    sleep($retrySeconds);
                }
            }
        }
    }

    $poller = new GlucosePoller(
        $app->provider(),
        $app->pollState(),
        $writer,
        $app->logger,
        $app->config->abbottPollSeconds,
        persistHistory: true,
        beforePoll: static function () use ($app, $writer): void {
            $writer->useRecipient($app->recipientCrypto());
        },
    );

    $poller->run($once, $wait);
} finally {
    $intake?->close();
}
