<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\PgpCrypto;
use App\Security\PgpKeyGenerator;

final class PgpKeyFactory
{
    /**
     * @var array<string, array{crypto: PgpCrypto, recipient: PgpCrypto}>
     */
    private static array $shared = [];

    public static function make(string $directory, string $passphrase = 'test-passphrase'): PgpCrypto
    {
        $public = $directory . '/public.asc';
        $private = $directory . '/private.asc';
        PgpKeyGenerator::generate($public, $private, $passphrase);

        return new PgpCrypto($public, $private);
    }

    /**
     * A process-wide keypair for tests that only need "some recipient key".
     * GnuPG key generation is slow, so it happens once per passphrase and is
     * reused across test cases.
     *
     * `recipient` is public-key-only — the same shape as
     * {@see \App\Support\App::recipientCrypto()} — and `crypto` holds the
     * private half so a test can decrypt what the writer published.
     *
     * @return array{crypto: PgpCrypto, recipient: PgpCrypto}
     */
    public static function shared(string $passphrase = 'test-passphrase'): array
    {
        if (isset(self::$shared[$passphrase])) {
            return self::$shared[$passphrase];
        }

        $directory = sys_get_temp_dir() . '/mylibre-shared-key-' . substr(hash('sha256', $passphrase), 0, 16);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the shared test key directory.');
        }

        PgpKeyGenerator::ensure($directory . '/public.asc', $directory . '/private.asc', $passphrase);

        return self::$shared[$passphrase] = [
            'crypto' => new PgpCrypto($directory . '/public.asc', $directory . '/private.asc'),
            'recipient' => new PgpCrypto($directory . '/public.asc'),
        ];
    }
}
