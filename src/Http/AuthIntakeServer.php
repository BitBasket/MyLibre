<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Logger;
use InvalidArgumentException;
use RuntimeException;

/**
 * Tiny loopback HTTP server. The poller process is already running; nginx
 * reverse-proxies /api/librelink/ here. This is not a public socket and not
 * a WebSocket — the browser talks HTTPS to nginx, same as a future Lambda
 * function URL.
 */
final class AuthIntakeServer
{
    private const MAX_HEADER_BYTES = 8192;

    private const MAX_BODY_BYTES = 8192;

    public function __construct(
        private mixed $socket,
        private readonly AuthIntakeHandler $handler,
        private readonly Logger $logger,
        private readonly string $listen,
    ) {
    }

    public static function bind(string $listen, AuthIntakeHandler $handler, Logger $logger): self
    {
        self::assertLoopback($listen);
        $socket = @stream_socket_server('tcp://' . $listen, $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException('Unable to bind AUTH_LISTEN ' . $listen . ': ' . $errstr);
        }
        stream_set_blocking($socket, false);
        $bound = stream_socket_get_name($socket, false) ?: $listen;
        $logger->info('LibreLink login intake listening on ' . $bound);

        return new self($socket, $handler, $logger, $bound);
    }

    public function listen(): string
    {
        return $this->listen;
    }

    public function port(): int
    {
        $parts = explode(':', $this->listen);

        return (int) end($parts);
    }

    /**
     * Sleep up to $seconds, handling login requests. Returns true if a login
     * succeeded and the poller should run immediately.
     */
    public function wait(int $seconds): bool
    {
        $deadline = microtime(true) + max(0, $seconds);
        $loggedIn = false;
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return $loggedIn;
            }
            if ($this->serveOne($remaining)) {
                $loggedIn = true;
            }
        }
    }

    /**
     * Accept and handle at most one connection. Returns true if that
     * connection stored a new LibreLink session.
     */
    public function serveOne(float $timeoutSeconds): bool
    {
        $read = [$this->socket];
        $write = [];
        $except = [];
        $sec = (int) $timeoutSeconds;
        $usec = (int) max(0, ($timeoutSeconds - $sec) * 1_000_000);
        $ready = @stream_select($read, $write, $except, $sec, $usec);
        if ($ready === false || $ready === 0) {
            return false;
        }

        $client = @stream_socket_accept($this->socket, 0);
        if (!is_resource($client)) {
            return false;
        }

        stream_set_timeout($client, 10);
        try {
            $request = $this->readRequest($client);
            if ($request === null) {
                $this->writeJson($client, 400, ['ok' => false, 'error' => 'bad request']);

                return false;
            }
            [$status, $payload, $loggedIn] = $this->handler->handle(
                $request['method'],
                $request['path'],
                $request['body'],
            );
            $this->writeJson($client, $status, $payload);

            return $loggedIn;
        } catch (\Throwable $e) {
            $this->logger->error('Auth intake connection failed: ' . $e->getMessage());
            $this->writeJson($client, 500, ['ok' => false, 'error' => 'unavailable']);

            return false;
        } finally {
            fclose($client);
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /**
     * @return array{method: string, path: string, body: string}|null
     */
    private function readRequest($client): ?array
    {
        $raw = '';
        while (!str_contains($raw, "\r\n\r\n")) {
            $chunk = fread($client, 1024);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $raw .= $chunk;
            if (strlen($raw) > self::MAX_HEADER_BYTES) {
                return null;
            }
        }

        [$headerBlock, $rest] = explode("\r\n\r\n", $raw, 2);
        $lines = explode("\r\n", $headerBlock);
        $requestLine = array_shift($lines) ?? '';
        if (preg_match('/\A([A-Z]+) (\S+) HTTP\//', $requestLine, $match) !== 1) {
            return null;
        }

        $headers = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $headers[$name] = trim(substr($line, $colon + 1));
        }

        $length = isset($headers['content-length']) && ctype_digit($headers['content-length'])
            ? (int) $headers['content-length']
            : 0;
        if ($length > self::MAX_BODY_BYTES) {
            return null;
        }

        $body = $rest;
        while (strlen($body) < $length) {
            $chunk = fread($client, $length - strlen($body));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $body .= $chunk;
        }

        return [
            'method' => $match[1],
            'path' => $match[2],
            'body' => substr($body, 0, $length),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson($client, int $status, array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $reason = match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            429 => 'Too Many Requests',
            502 => 'Bad Gateway',
            default => 'Error',
        };
        $out = "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: application/json; charset=utf-8\r\n"
            . "Cache-Control: no-store\r\n"
            . "Connection: close\r\n"
            . 'Content-Length: ' . strlen($json) . "\r\n\r\n"
            . $json;
        fwrite($client, $out);
    }

    public static function assertLoopback(string $listen): void
    {
        if (preg_match('/\A(127\.0\.0\.1|\[::1\]):([0-9]{1,5})\z/', $listen, $match) !== 1) {
            throw new InvalidArgumentException('AUTH_LISTEN must be loopback, e.g. 127.0.0.1:8766');
        }
        if ((int) $match[2] > 65535) {
            throw new InvalidArgumentException('AUTH_LISTEN port is invalid.');
        }
    }
}
