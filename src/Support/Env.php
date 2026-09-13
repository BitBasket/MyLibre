<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class Env
{
    /** @var array<string, string> */
    private static array $loaded = [];

    public static function reset(): void
    {
        self::$loaded = [];
    }

    public static function load(string $root): void
    {
        $path = $root . '/.env';
        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            $value = self::plainValue(substr($line, $eq + 1));
            if ($key === '') {
                continue;
            }

            self::$loaded[$key] = $value;
            if (getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return self::$loaded[$key] ?? $default;
        }

        return (string) $value;
    }

    /**
     * Value as written in the loaded .env file, ignoring process environment.
     */
    public static function fromDotEnv(string $key): ?string
    {
        return self::$loaded[$key] ?? null;
    }

    /**
     * Whether a value written as "KEY=value" is read back unchanged.
     */
    public static function roundTrips(string $value): bool
    {
        if ($value === '' || preg_match('/\R/', $value) === 1) {
            return false;
        }

        return self::plainValue($value) === $value;
    }

    /**
     * Writes KEY=value into an .env file, replacing an existing line for that key
     * and creating the file with mode 0600 when it does not exist yet.
     *
     * The value is written verbatim, so only values accepted by roundTrips() survive.
     */
    public static function write(string $path, string $key, string $value): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key) !== 1) {
            throw new InvalidArgumentException('Invalid .env key: ' . $key);
        }

        $line = $key . '=' . $value;
        if (!is_file($path)) {
            file_put_contents($path, $line . "\n");
            chmod($path, 0600);

            return;
        }

        $contents = (string) file_get_contents($path);
        if (preg_match('/^' . $key . '=.*$/m', $contents) === 1) {
            // A callback, not $line as a replacement: $1 or \1 in a value would
            // otherwise be treated as a backreference.
            $contents = preg_replace_callback(
                '/^' . $key . '=.*$/m',
                static fn (): string => $line,
                $contents,
                1,
            ) ?? $contents;
        } else {
            $contents = rtrim($contents) . "\n" . $line . "\n";
        }

        file_put_contents($path, $contents);
        chmod($path, 0600);
    }

    private static function plainValue(string $raw): string
    {
        $value = trim($raw);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }
}
