<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\PgpKeyFactory;
use PHPUnit\Framework\TestCase;

final class PgpCryptoTest extends TestCase
{
    public function testRoundTripAndRejectsUnsignedCiphertext(): void
    {
        $dir = sys_get_temp_dir() . '/mylibre-pgp-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $crypto = PgpKeyFactory::make($dir, 'correct horse');
        $crypto->validate('correct horse');

        $cipher = $crypto->encrypt('{"ok":true}', 'correct horse');
        $this->assertStringContainsString('BEGIN PGP MESSAGE', $cipher);
        $this->assertSame('{"ok":true}', $crypto->decrypt($cipher, 'correct horse'));

        $this->expectException(\RuntimeException::class);
        $crypto->decrypt($cipher, 'wrong');
    }
}
