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

$root = dirname(__DIR__);
Env::reset();
$config = Config::fromEnv($root);
$passphrase = $config->unlockPassphrase;
$wrotePassphrase = false;

if ($passphrase === '') {
    $passphrase = bin2hex(random_bytes(24));
    writePassphrase($root . '/.env', $passphrase);
    $wrotePassphrase = true;
    putenv('PGP_PASSPHRASE=' . $passphrase);
    $_ENV['PGP_PASSPHRASE'] = $passphrase;
    Env::reset();
    $config = Config::fromEnv($root);
}

if ($config->publicKeyPath === '' || $config->privateKeyPath === '') {
    fwrite(STDERR, "PGP_PUBLIC_KEY_PATH and PGP_PRIVATE_KEY_PATH are required.\n");
    exit(1);
}

PgpKeyGenerator::ensure($config->publicKeyPath, $config->privateKeyPath, $passphrase);
$crypto = new PgpCrypto($config->publicKeyPath, $config->privateKeyPath);
$crypto->validate($passphrase);

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

function writePassphrase(string $envPath, string $passphrase): void
{
    $line = 'PGP_PASSPHRASE=' . $passphrase;
    if (!is_file($envPath)) {
        file_put_contents($envPath, $line . "\n");
        chmod($envPath, 0600);
        return;
    }

    $contents = (string) file_get_contents($envPath);
    if (preg_match('/^PGP_PASSPHRASE=.*$/m', $contents) === 1) {
        $contents = preg_replace('/^PGP_PASSPHRASE=.*$/m', $line, $contents, 1) ?? $contents;
    } else {
        $contents = rtrim($contents) . "\n" . $line . "\n";
    }
    file_put_contents($envPath, $contents);
    chmod($envPath, 0600);
}
