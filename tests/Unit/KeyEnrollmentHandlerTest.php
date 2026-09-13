<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\KeyEnrollmentHandler;
use App\Tests\Support\PgpKeyFactory;
use PHPUnit\Framework\TestCase;

final class KeyEnrollmentHandlerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mylibre-enroll-' . uniqid('', true);
        mkdir($this->dir . '/keys', 0700, true);
    }

    public function testStatusWhenMissing(): void
    {
        $handler = new KeyEnrollmentHandler($this->dir . '/keys/user-public.asc');
        [$status, $payload] = $handler->handle('GET', '/api/keys', '');

        $this->assertSame(200, $status);
        $this->assertFalse($payload['enrolled']);
    }

    public function testRejectsPrivateKeyWithoutWriting(): void
    {
        $path = $this->dir . '/keys/user-public.asc';
        $handler = new KeyEnrollmentHandler($path);
        [$status, $payload] = $handler->handle(
            'POST',
            '/api/keys',
            json_encode([
                'publicKey' => "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\n-----END PGP PUBLIC KEY BLOCK-----",
                'privateKey' => "-----BEGIN PGP PRIVATE KEY BLOCK-----\n\n-----END PGP PRIVATE KEY BLOCK-----",
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertSame(400, $status);
        $this->assertSame('the private key must not be uploaded', $payload['error']);
        $this->assertFileDoesNotExist($path);
    }

    public function testRejectsPrivateKeySmuggledInPublicField(): void
    {
        $path = $this->dir . '/keys/user-public.asc';
        $handler = new KeyEnrollmentHandler($path);
        [$status, $payload] = $handler->handle(
            'POST',
            '/api/keys',
            json_encode([
                'publicKey' => "-----BEGIN PGP PRIVATE KEY BLOCK-----\n\n-----END PGP PRIVATE KEY BLOCK-----",
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertSame(400, $status);
        $this->assertSame('the private key must not be uploaded', $payload['error']);
        $this->assertFileDoesNotExist($path);
    }

    public function testEnrollsPublicKeyAndIsIdempotent(): void
    {
        PgpKeyFactory::make($this->dir, 'enroll-pass-12');
        $public = (string) file_get_contents($this->dir . '/public.asc');
        $path = $this->dir . '/keys/user-public.asc';
        $handler = new KeyEnrollmentHandler($path);

        [$status, $payload] = $handler->handle(
            'POST',
            '/api/keys',
            json_encode(['publicKey' => $public], JSON_THROW_ON_ERROR),
        );

        $this->assertSame(200, $status);
        $this->assertTrue($payload['enrolled']);
        $this->assertNotSame('', $payload['fingerprint']);
        $this->assertFileExists($path);
        $this->assertStringContainsString('BEGIN PGP PUBLIC KEY BLOCK', (string) file_get_contents($path));
        $this->assertStringNotContainsString('BEGIN PGP PRIVATE KEY', (string) file_get_contents($path));

        [$againStatus, $again] = $handler->handle(
            'POST',
            '/api/keys',
            json_encode(['publicKey' => $public], JSON_THROW_ON_ERROR),
        );
        $this->assertSame(200, $againStatus);
        $this->assertSame($payload['fingerprint'], $again['fingerprint']);

        [$getStatus, $get] = $handler->handle('GET', '/api/keys/status', '');
        $this->assertSame(200, $getStatus);
        $this->assertTrue($get['enrolled']);
        $this->assertSame($payload['fingerprint'], $get['fingerprint']);
    }

    public function testConflictOnDifferentKey(): void
    {
        PgpKeyFactory::make($this->dir, 'enroll-pass-12');
        $first = (string) file_get_contents($this->dir . '/public.asc');
        $otherDir = $this->dir . '/other';
        mkdir($otherDir, 0700, true);
        PgpKeyFactory::make($otherDir, 'enroll-pass-12');
        $second = (string) file_get_contents($otherDir . '/public.asc');

        $path = $this->dir . '/keys/user-public.asc';
        $handler = new KeyEnrollmentHandler($path);
        $this->assertSame(200, $handler->handle('POST', '/api/keys', json_encode(['publicKey' => $first], JSON_THROW_ON_ERROR))[0]);

        [$status, $payload] = $handler->handle(
            'POST',
            '/api/keys',
            json_encode(['publicKey' => $second], JSON_THROW_ON_ERROR),
        );
        $this->assertSame(409, $status);
        $this->assertSame('a different public key is already enrolled', $payload['error']);
        $this->assertSame($first, file_get_contents($path));
    }
}
