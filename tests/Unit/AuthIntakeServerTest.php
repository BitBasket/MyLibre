<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\AuthIntakeHandler;
use App\Http\AuthIntakeServer;
use App\Support\Logger;
use App\Tests\Support\FakeLibreLinkAuthenticator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuthIntakeServerTest extends TestCase
{
    public function testRejectsNonLoopbackBind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AuthIntakeServer::assertLoopback('0.0.0.0:8766');
    }

    public function testServesStatusAndLogin(): void
    {
        $handler = new AuthIntakeHandler(
            new FakeLibreLinkAuthenticator(),
            new Logger(fopen('php://memory', 'ab')),
        );
        $server = AuthIntakeServer::bind('127.0.0.1:0', $handler, new Logger(fopen('php://memory', 'ab')));
        try {
            $port = $server->port();
            $this->assertGreaterThan(0, $port);

            $status = $this->http($port, "GET /api/librelink/status HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n", $server, expectLogin: false);
            $this->assertStringContainsString('HTTP/1.1 200', $status);
            $this->assertStringContainsString('"authenticated":false', $status);

            $body = json_encode(['email' => 'user@example.com', 'password' => 's3cret'], JSON_THROW_ON_ERROR);
            $login = $this->http(
                $port,
                "POST /api/librelink/login HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: application/json\r\nContent-Length: "
                    . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body,
                $server,
                expectLogin: true,
            );
            $this->assertStringContainsString('HTTP/1.1 200', $login);
            $this->assertStringContainsString('"authenticated":true', $login);
            $this->assertStringNotContainsString('s3cret', $login);
        } finally {
            $server->close();
        }
    }

    private function http(int $port, string $request, AuthIntakeServer $server, bool $expectLogin): string
    {
        $client = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 2);
        $this->assertIsResource($client, $errstr);
        stream_set_timeout($client, 2);
        fwrite($client, $request);
        $this->assertSame($expectLogin, $server->serveOne(2));
        $response = stream_get_contents($client) ?: '';
        fclose($client);

        return $response;
    }
}
