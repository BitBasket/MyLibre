<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Public PHP API in front of the poller.
 *
 * The browser talks only to this process. LibreLinkUp login POSTs are
 * forwarded to AUTH_LISTEN on loopback. The poller never binds a public
 * socket. Credential POSTs (LibreLinkUp login and public-key enrollment)
 * are refused unless this is a direct loopback hit (local `composer start`)
 * or a trusted reverse proxy that already terminated TLS
 * (`X-Forwarded-Proto: https`).
 */
final class Kernel
{
    public function __construct(
        private readonly string $publicDir,
        private readonly string $authListen,
        private readonly ?KeyEnrollmentHandler $keys = null,
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @return array{passthrough: true}|array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(array $server, string $body): array
    {
        $path = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        if (str_starts_with($path, '/api/keys')) {
            if ($this->keys === null) {
                return self::json(404, ['ok' => false, 'error' => 'not found']);
            }
            if ($method === 'POST' && !self::allowsCredentialPost($server)) {
                return self::json(403, ['ok' => false, 'error' => 'https required']);
            }

            [$status, $payload] = $this->keys->handle($method, $path, $body);

            return self::json($status, $payload);
        }

        if (str_starts_with($path, '/api/librelink/')) {
            if (self::isLibreLinkLogin($method, $path) && !self::allowsCredentialPost($server)) {
                return self::json(403, ['ok' => false, 'error' => 'https required']);
            }

            [$status, $headers, $responseBody] = AuthIntakeProxy::forward(
                $this->authListen,
                $method,
                $path,
                $body,
                (string) ($server['CONTENT_TYPE'] ?? $server['HTTP_CONTENT_TYPE'] ?? ''),
            );

            return ['status' => $status, 'headers' => $headers, 'body' => $responseBody];
        }

        if ($path !== '/' && is_file($this->publicDir . $path)) {
            return ['passthrough' => true];
        }

        if (preg_match('#^/(current|status|b/\d+)\.json(\.asc)?$#', $path) === 1) {
            return self::json(404, ['error' => 'not_found']);
        }

        if ($path === '/' || $path === '/index.html') {
            return ['passthrough' => true];
        }

        return [
            'status' => 404,
            'headers' => ['Content-Type' => 'text/plain; charset=utf-8'],
            'body' => 'Not found',
        ];
    }

    /**
     * Direct loopback HTTP is allowed (the browser is on the same machine).
     * Anything that arrived through a reverse proxy must already be HTTPS.
     *
     * @param array<string, mixed> $server
     */
    public static function allowsCredentialPost(array $server): bool
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        $forwardedProto = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $forwardedFor = trim((string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        $proxied = $forwardedProto !== '' || $forwardedFor !== '';

        if ($proxied) {
            // Caddy/nginx on the Docker network is a private-IP hop, not
            // loopback. The API port must not be published; only a trusted
            // proxy may set these headers.
            if (!self::isTrustedProxy($remote)) {
                return false;
            }

            return $forwardedProto === 'https';
        }

        return self::isLoopbackAddress($remote) && self::isLoopbackHost((string) ($server['HTTP_HOST'] ?? ''));
    }

    public static function isTrustedProxy(string $address): bool
    {
        $address = strtolower(trim($address));
        if (str_starts_with($address, '::ffff:')) {
            $address = substr($address, 7);
        }
        if (self::isLoopbackAddress($address)) {
            return true;
        }
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    public static function isLibreLinkLogin(string $method, string $path): bool
    {
        if (strtoupper($method) !== 'POST') {
            return false;
        }

        $path = strtolower(rtrim(strtok($path, '?') ?: $path, '/'));

        return $path === '/api/librelink/login' || $path === '/login';
    }

    public static function isLoopbackAddress(string $address): bool
    {
        $address = strtolower(trim($address));
        if (str_starts_with($address, '::ffff:')) {
            $address = substr($address, 7);
        }

        return $address === '127.0.0.1' || $address === '::1';
    }

    public static function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            $host = $end === false ? $host : substr($host, 1, $end - 1);
        } else {
            $colon = strrpos($host, ':');
            if ($colon !== false && !str_contains($host, ']')) {
                $host = substr($host, 0, $colon);
            }
        }

        return $host === '127.0.0.1' || $host === 'localhost' || $host === '::1';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function json(int $status, array $payload): array
    {
        return [
            'status' => $status,
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
        ];
    }
}
