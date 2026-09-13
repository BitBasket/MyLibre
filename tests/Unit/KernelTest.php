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
        $dir = sys_get_temp_dir() . '/mylibre-kernel-' . uniqid('', true);
        mkdir($dir);
        $kernel = new Kernel($dir, '');
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
        $dir = sys_get_temp_dir() . '/mylibre-kernel-' . uniqid('', true);
        mkdir($dir);
        $kernel = new Kernel($dir, '');
        $result = $kernel->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/librelink/status',
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_HOST' => 'vps.example',
        ], '');

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('not found', $result['body']);
    }

    public function testKeyEnrollmentPostWithoutHttpsIsForbidden(): void
    {
        $dir = sys_get_temp_dir() . '/mylibre-kernel-' . uniqid('', true);
        mkdir($dir);
        $kernel = new Kernel($dir, '', new KeyEnrollmentHandler($dir . '/user-public.asc'));
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
        $this->assertFileDoesNotExist($dir . '/user-public.asc');
    }

    public function testKeyStatusDoesNotRequireHttps(): void
    {
        $dir = sys_get_temp_dir() . '/mylibre-kernel-' . uniqid('', true);
        mkdir($dir);
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
        $dir = sys_get_temp_dir() . '/mylibre-kernel-' . uniqid('', true);
        mkdir($dir);
        $kernel = new Kernel($dir, '', new KeyEnrollmentHandler($dir . '/user-public.asc'));
        $result = $kernel->handle([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/keys',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => '127.0.0.1:8765',
            'CONTENT_TYPE' => 'application/json',
        ], '{"publicKey":"not-a-key"}');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('public key must be', $result['body']);
        $this->assertFileDoesNotExist($dir . '/user-public.asc');
    }

    public function testMissingSnapshotIsJson404(): void
    {
        $dir = sys_get_temp_dir() . '/mylibre-kernel-' . uniqid('', true);
        mkdir($dir);
        $kernel = new Kernel($dir, '');
        $result = $kernel->handle([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/current.json.asc',
        ], '');

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('not_found', $result['body']);
    }
}
