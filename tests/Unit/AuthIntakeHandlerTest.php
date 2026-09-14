<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Contract\LibreLinkAuthenticator;
use App\Http\AuthIntakeHandler;
use App\Support\Logger;
use App\Tests\Support\FakeLibreLinkAuthenticator;
use PHPUnit\Framework\TestCase;

final class AuthIntakeHandlerTest extends TestCase
{
    public function testStatusReportsUnauthenticated(): void
    {
        $handler = $this->handler();
        [$status, $payload, $loggedIn] = $handler->handle('GET', '/api/librelink/status', '');

        $this->assertSame(200, $status);
        $this->assertFalse($payload['authenticated']);
        $this->assertFalse($loggedIn);
    }

    public function testLoginStoresSessionAndDoesNotEchoPassword(): void
    {
        $auth = new FakeLibreLinkAuthenticator();
        $handler = $this->handler($auth);
        [$status, $payload, $loggedIn] = $handler->handle(
            'POST',
            '/api/librelink/login',
            json_encode(['email' => 'user@example.com', 'password' => 's3cret'], JSON_THROW_ON_ERROR),
        );

        $this->assertSame(200, $status);
        $this->assertTrue($payload['authenticated']);
        $this->assertTrue($loggedIn);
        $this->assertSame('user@example.com', $auth->email);
        $this->assertSame('s3cret', $auth->password);
        $this->assertFalse(json_encode($payload, JSON_THROW_ON_ERROR) !== false && str_contains(json_encode($payload), 's3cret'));
        $this->assertTrue($handler->handle('GET', '/api/librelink/status', '')[1]['authenticated']);
    }

    public function testInvalidLoginIsGeneric(): void
    {
        $handler = $this->handler();
        [$status, $payload, $loggedIn] = $handler->handle(
            'POST',
            '/api/librelink/login',
            json_encode(['email' => 'user@example.com', 'password' => 'bad'], JSON_THROW_ON_ERROR),
        );

        $this->assertSame(401, $status);
        $this->assertSame('authentication failed', $payload['error']);
        $this->assertFalse($loggedIn);
    }

    public function testMissingFields(): void
    {
        $handler = $this->handler();
        [$status] = $handler->handle('POST', '/api/librelink/login', '{"email":"user@example.com"}');
        $this->assertSame(400, $status);
    }

    public function testRateLimit(): void
    {
        $handler = $this->handler();
        $body = json_encode(['email' => 'user@example.com', 'password' => 'bad'], JSON_THROW_ON_ERROR);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $handler->handle('POST', '/api/librelink/login', $body)[0]);
        }
        $this->assertSame(429, $handler->handle('POST', '/api/librelink/login', $body)[0]);
    }

    public function testUnknownPath(): void
    {
        [$status] = $this->handler()->handle('GET', '/api/other', '');
        $this->assertSame(404, $status);
    }

    public function testTenantScopedLoginIsNotRouted(): void
    {
        $handler = $this->handler();

        [$status] = $handler->handle('POST', '/t/6QKnU3XheQMk3E6Vq1B4l6/api/librelink/login', '{}');
        $this->assertSame(404, $status);
    }

    private function handler(?LibreLinkAuthenticator $auth = null): AuthIntakeHandler
    {
        return new AuthIntakeHandler(
            $auth ?? new FakeLibreLinkAuthenticator(),
            new Logger(fopen('php://memory', 'ab')),
        );
    }
}
