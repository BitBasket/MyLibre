<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\AuthIntakeProxy;
use PHPUnit\Framework\TestCase;

final class AuthIntakeProxyTest extends TestCase
{
    public function testEmptyListenIsNotFound(): void
    {
        [$status, $headers, $body] = AuthIntakeProxy::forward('', 'GET', '/api/librelink/status', '');

        $this->assertSame(404, $status);
        $this->assertSame('application/json; charset=utf-8', $headers['Content-Type']);
        $this->assertStringContainsString('not found', $body);
    }

    public function testUnavailableListenIsBadGateway(): void
    {
        [$status, , $body] = AuthIntakeProxy::forward('127.0.0.1:1', 'GET', '/api/librelink/status', '');

        $this->assertSame(502, $status);
        $this->assertStringContainsString('login intake unavailable', $body);
    }
}
