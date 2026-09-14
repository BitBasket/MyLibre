<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\App;
use App\Support\Logger;
use App\Tests\Support\ConfigFactory;
use App\Tests\Support\PgpKeyFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The published snapshots are PHI, so they must only ever be encrypted to the
 * user's public key. There is no server-key fallback and no plaintext mode.
 */
final class AppRecipientKeyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mylibre-recipient-' . uniqid('', true);
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            is_dir($file) ? @rmdir($file) : @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testRecipientCryptoRefusesWithoutAUserKey(): void
    {
        // The server keypair exists, but no user key has been enrolled.
        $server = PgpKeyFactory::make($this->directory . '/server', 'server-pass');
        $app = new App(ConfigFactory::make(
            root: $this->directory,
            publicKeyPath: $this->directory . '/server/public.asc',
            privateKeyPath: $this->directory . '/server/private.asc',
            unlockPassphrase: 'server-pass',
        ), new Logger(fopen('php://memory', 'ab')));

        $this->assertNotSame('', $server->publicFingerprint());

        // It must not quietly fall back to the server key, which the host can
        // read; a missing user key is a hard configuration error.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('user public key');
        $app->recipientCrypto();
    }

    public function testRecipientCryptoIsTheUserKeyAndCarriesNoPrivateKey(): void
    {
        $server = PgpKeyFactory::make($this->directory . '/server', 'server-pass');
        $user = PgpKeyFactory::make($this->directory . '/user', 'user-pass');
        copy($this->directory . '/user/public.asc', $this->directory . '/user-public.asc');

        $app = new App(ConfigFactory::make(
            root: $this->directory,
            publicKeyPath: $this->directory . '/server/public.asc',
            privateKeyPath: $this->directory . '/server/private.asc',
            unlockPassphrase: 'server-pass',
            userPublicKeyPath: $this->directory . '/user-public.asc',
        ), new Logger(fopen('php://memory', 'ab')));

        $recipient = $app->recipientCrypto();

        $this->assertSame($user->publicFingerprint(), $recipient->publicFingerprint());
        $this->assertNotSame($server->publicFingerprint(), $recipient->publicFingerprint());
        $this->assertFalse($recipient->hasPrivateKey(), 'the server must hold only the user public key');

        // And the writer it hands out publishes under that same key.
        $this->assertSame($recipient->publicFingerprint(), $app->bucketWriter()->recipientFingerprint());
    }
}
