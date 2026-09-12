<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class SqliteHistoryImporter
{
    public static function resolve(string $path): string
    {
        if (is_dir($path)) {
            $path = rtrim($path, '/') . '/glucose.sqlite';
        }

        if (!is_file($path)) {
            throw new RuntimeException('SQLite database not found: ' . $path);
        }

        $real = realpath($path);

        return $real !== false ? $real : $path;
    }

    public static function snapshot(string $sqlitePath): string
    {
        $source = self::resolve($sqlitePath);
        $tmp = sys_get_temp_dir() . '/mylibre-sqlite-' . bin2hex(random_bytes(8)) . '.sqlite';

        $pdo = new PDO('sqlite:' . $source, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('VACUUM INTO ' . $pdo->quote($tmp));

        return $tmp;
    }

    public static function importFile(string $sqlitePath, EncryptedGlucoseRepository $destination): int
    {
        $snapshot = self::snapshot($sqlitePath);

        try {
            $source = new SQLiteGlucoseRepository(SQLiteConnection::connect($snapshot));

            return $destination->import($source->all());
        } finally {
            @unlink($snapshot);
            @unlink($snapshot . '-wal');
            @unlink($snapshot . '-shm');
        }
    }
}
