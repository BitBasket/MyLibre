<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\PgpCrypto;
use App\Security\PgpKeyGenerator;

final class PgpKeyFactory
{
    public static function make(string $directory, string $passphrase = 'test-passphrase'): PgpCrypto
    {
        $public = $directory . '/public.asc';
        $private = $directory . '/private.asc';
        PgpKeyGenerator::generate($public, $private, $passphrase);

        return new PgpCrypto($public, $private);
    }
}
