<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Security\PgpCrypto;
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

    public function testPublicKeyOnlyEncryptionIsUnsignedButStillDecryptable(): void
    {
        $dir = sys_get_temp_dir() . '/mylibre-pgp-pub-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $crypto = PgpKeyFactory::make($dir, 'correct horse');

        $publicOnly = new PgpCrypto($dir . '/public.asc');
        $this->assertFalse($publicOnly->hasPrivateKey());
        $publicOnly->validate();

        $cipher = $publicOnly->encrypt('{"ok":true}');
        $this->assertStringContainsString('BEGIN PGP MESSAGE', $cipher);

        // A public-key-only crypto has no private key to decrypt with.
        try {
            $publicOnly->decrypt($cipher, 'correct horse');
            $this->fail('Public-key-only crypto must not decrypt.');
        } catch (\RuntimeException $expected) {
            $this->assertStringContainsString('No private key', $expected->getMessage());
        }

        // The payload is unsigned, so the strict path refuses it by default...
        try {
            $crypto->decrypt($cipher, 'correct horse');
            $this->fail('The signed-decrypt path must reject unsigned payloads.');
        } catch (\RuntimeException $expected) {
            $this->assertStringContainsString('signature', $expected->getMessage());
        }

        // ...but the private-key holder can still read it when signatures are not required.
        $this->assertSame('{"ok":true}', $crypto->decrypt($cipher, 'correct horse', false));
    }
}
