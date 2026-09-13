<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Kernel;
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
