<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final class SQLiteConnection
{
    public static function connect(string $path): PDO
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create SQLite directory.');
            }
        }

        $pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        self::migrate($pdo);

        return $pdo;
    }

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec(
            <<<SQL
            CREATE TABLE IF NOT EXISTS glucose_readings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp INTEGER NOT NULL UNIQUE,
                glucose_mg_dl INTEGER NOT NULL,
                trend TEXT,
                trend_arrow TEXT,
                source TEXT NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_glucose_timestamp
                ON glucose_readings(timestamp);
            SQL
        );
    }
}
