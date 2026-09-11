#!/bin/php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database\EncryptedGlucoseRepository;
use App\Database\SQLiteConnection;
use App\Database\SQLiteGlucoseRepository;
use App\Security\PgpCrypto;
use App\Security\PgpKeyGenerator;
use App\Support\Config;
use App\Support\Env;
use App\Support\PassphrasePrompt;

$root = dirname(__DIR__);
Env::reset();
$config = Config::fromEnv($root);

if ($config->publicKeyPath === '' || $config->privateKeyPath === '') {
    fwrite(STDERR, "PGP_PUBLIC_KEY_PATH and PGP_PRIVATE_KEY_PATH are required.\n");
    exit(1);
}

$keysExist = is_readable($config->publicKeyPath) && is_readable($config->privateKeyPath);
$passphrase = $config->unlockPassphrase;
$wrotePassphrase = false;
$prompt = $passphrase === '' && PassphrasePrompt::terminalAvailable()
    ? PassphrasePrompt::interactive()
    : null;

if ($prompt !== null) {
    $passphrase = askForPassphrase($prompt, $keysExist);
} elseif ($passphrase === '') {
    fwrite(STDERR, "PGP_PASSPHRASE is not set and there is no terminal to prompt for it.\n");
    fwrite(STDERR, "Set PGP_PASSPHRASE in .env, or run php bin/init-pgp.php from an interactive shell.\n");
    exit(1);
}

$attempts = 0;
while (true) {
    try {
        PgpKeyGenerator::ensure($config->publicKeyPath, $config->privateKeyPath, $passphrase);
        break;
    } catch (RuntimeException $error) {
        if ($prompt === null || !$keysExist) {
            fwrite(STDERR, $error->getMessage() . "\n");
            if ($prompt === null && $keysExist) {
                fwrite(STDERR, "Check PGP_PASSPHRASE in .env, or clear it to be prompted for the passphrase.\n");
            }
            exit(1);
        }

        if (++$attempts >= PassphrasePrompt::MAX_ATTEMPTS) {
            fwrite(STDERR, $error->getMessage() . "\n");
            fwrite(STDERR, "Unable to unlock the existing PGP key.\n");
            exit(1);
        }

        fwrite(STDERR, "That passphrase did not unlock the private key.\n");
        $passphrase = askForPassphrase($prompt, true);
    }
}

if ($prompt !== null) {
    if (Env::roundTrips($passphrase)) {
        Env::write($root . '/.env', 'PGP_PASSPHRASE', $passphrase);
        $wrotePassphrase = true;
    } else {
        fwrite(STDERR, "This passphrase cannot be stored in .env, so PGP_PASSPHRASE must be set in the environment for the poller.\n");
    }
}

$crypto = new PgpCrypto($config->publicKeyPath, $config->privateKeyPath);

$repository = new EncryptedGlucoseRepository($config->dataPath, $crypto, $passphrase);
$sqliteCandidates = [];
if (str_ends_with($config->sqlitePath, '.sqlite') && is_file($config->sqlitePath)) {
    $sqliteCandidates[] = $config->sqlitePath;
}
$defaultSqlite = $root . '/data/glucose.sqlite';
if (is_file($defaultSqlite)) {
    $sqliteCandidates[] = $defaultSqlite;
}

$migrated = 0;
foreach (array_unique($sqliteCandidates) as $sqlitePath) {
    $pdo = SQLiteConnection::connect($sqlitePath);
    $pdo->exec('PRAGMA wal_checkpoint(PASSIVE)');
    $source = new SQLiteGlucoseRepository($pdo);
    $migrated += $repository->import($source->all());
}
$repository->flush();

echo "PGP keys: {$config->publicKeyPath}\n";
echo "Private key: {$config->privateKeyPath}\n";
echo "Encrypted history: {$config->dataPath}\n";
if ($wrotePassphrase) {
    echo "PGP_PASSPHRASE was written to .env (not printed here).\n";
}
if ($migrated > 0) {
    echo "Migrated {$migrated} SQLite readings into the encrypted store.\n";
} else {
    echo 'Encrypted store readings: ' . count($repository->all()) . "\n";
}
echo "Unlock the dashboard with the same public.asc, private.asc, and passphrase.\n";

function askForPassphrase(PassphrasePrompt $prompt, bool $keysExist): string
{
    try {
        return $keysExist ? $prompt->forExistingKey() : $prompt->forNewKey();
    } catch (RuntimeException $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
