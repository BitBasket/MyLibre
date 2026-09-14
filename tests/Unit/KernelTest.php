<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Kernel;
use App\Http\KeyEnrollmentHandler;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    public function testDirectLoopbackLoginIsAllowed(): void
    {
        $this->assertTrue(Kernel::allowsCredentialPost([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => '127.0.0.1:8765',
        ]));
        $this->assertTrue(Kernel::allowsCredentialPost([
            'REMOTE_ADDR' => '::1',
            'HTTP_HOST' => 'localhost',
        ]));
    }

    public function testPublicHttpProxyLoginIsRefused(): void
    {
        $this->assertFalse(Kernel::allowsCredentialPost([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'example.com',
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]));
        $this->assertFalse(Kernel::allowsCredentialPost([
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_HOST' => 'example.com',
        ]));
    }

    public function testTlsTerminatedProxyLoginIsAllowed(): void
    {
        $this->assertTrue(Kernel::allowsCredentialPost([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'example.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]));
    }

    public function testDockerCaddyHttpsProxyIsAllowed(): void
    {
        $this->assertTrue(Kernel::allowsCredentialPost([
            'REMOTE_ADDR' => '172.18.0.2',
            'HTTP_HOST' => 'glucose.example.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]));
    }

    public function testSpoofedForwardedProtoFromPublicClientIsRefused(): void
    {
        $this->assertFalse(Kernel::allowsCredentialPost([
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_HOST' => 'glucose.example.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]));
    }

    public function testLoginPostWithoutHttpsIsForbidden(): void
    {
        $kernel = new Kernel($this->tempDir(), '');
        $result = $kernel->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/librelink/login',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'vps.example',
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
            'CONTENT_TYPE' => 'application/json',
        ], '{"email":"a@b.c","password":"s3cret"}');

        $this->assertSame(403, $result['status']);
        $this->assertStringContainsString('https required', $result['body']);
        $this->assertStringNotContainsString('s3cret', $result['body']);
    }

    public function testStatusDoesNotRequireHttps(): void
    {
        $kernel = new Kernel($this->tempDir(), '');
        $result = $kernel->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/librelink/status',
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_HOST' => 'vps.example',
        ], '');

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('not found', $result['body']);
    }

    public function testSelfHostDoesNotCreateTenants(): void
    {
        $kernel = new Kernel($this->tempDir(), '', new KeyEnrollmentHandler(''));
        $result = $kernel->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/tenants',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => '127.0.0.1:8765',
        ], '{}');

        $this->assertSame(404, $result['status']);
    }

    public function testKeysEndpointWithoutHandlerIs404(): void
    {
        $kernel = new Kernel($this->tempDir(), '');
        $result = $kernel->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/keys',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => '127.0.0.1:8765',
        ], '');

        $this->assertSame(404, $result['status']);
    }

    public function testKeyEnrollmentPostWithoutHttpsIsForbidden(): void
    {
        $dir = $this->tempDir();
        $keyPath = $dir . '/user-public.asc';
        $kernel = new Kernel($dir, '', new KeyEnrollmentHandler($keyPath));
        $result = $kernel->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/keys',
            'REMOTE_ADDR' => '172.18.0.2',
            'HTTP_HOST' => 'vps.example',
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
            'CONTENT_TYPE' => 'application/json',
        ], '{"publicKey":"-----BEGIN PGP PUBLIC KEY BLOCK-----"}');

        $this->assertSame(403, $result['status']);
        $this->assertStringContainsString('https required', $result['body']);
        $this->assertFileDoesNotExist($keyPath);
    }

    public function testKeyStatusDoesNotRequireHttps(): void
    {
        $dir = $this->tempDir();
        $kernel = new Kernel($dir, '', new KeyEnrollmentHandler($dir . '/user-public.asc'));
        $result = $kernel->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/keys',
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_HOST' => 'vps.example',
        ], '');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('"enrolled":false', $result['body']);
    }

    public function testLoopbackKeyEnrollmentReachesHandler(): void
    {
        $dir = $this->tempDir();
        $keyPath = $dir . '/user-public.asc';
        $kernel = new Kernel($dir, '', new KeyEnrollmentHandler($keyPath));
        $result = $kernel->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/keys',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => '127.0.0.1:8765',
            'CONTENT_TYPE' => 'application/json',
        ], '{"publicKey":"not-a-key"}');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('public key must be', $result['body']);
        $this->assertFileDoesNotExist($keyPath);
    }

    public function testMissingSnapshotIsJson404(): void
    {
        $kernel = new Kernel($this->tempDir(), '');
        $result = $kernel->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/current.json.asc',
        ], '');

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('not_found', $result['body']);
    }

    /**
     * A stale cleartext snapshot from an older deployment must not be served,
     * even though it sits in the public directory.
     */
    public function testStalePlaintextSnapshotIsNeverServed(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/current.json', '{"glucoseMgDl":174}');
        file_put_contents($dir . '/status.json', '{"ok":true}');
        file_put_contents($dir . '/history-20260902.json', '{"glucoseMgDl":174}');
        file_put_contents($dir . '/current.json.asc', "-----BEGIN PGP MESSAGE-----\n");

        $kernel = new Kernel($dir, '');

        foreach ([
            '/current.json',
            '/status.json',
            '/history-20260902.json',
            '/history.json',
            '/CURRENT.JSON',
            '/b/1788333000.json',
            '/./current.json',
            '/x/../status.json',
            '/b/../current.json',
        ] as $path) {
            $result = $kernel->handle([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $path,
            ], '');
            $this->assertSame(404, $result['status'], $path . ' must not be served');
            $this->assertStringContainsString('not_found', $result['body']);
            $this->assertArrayNotHasKey('passthrough', $result);
        }

        // The encrypted form still passes through to the static server.
        $encrypted = $kernel->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/current.json.asc',
        ], '');
        $this->assertTrue($encrypted['passthrough'] ?? false);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/mylibre-kernel-' . uniqid('', true);
        mkdir($dir, 0700, true);

        return $dir;
    }
}
