<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Forwards /api/librelink/* from the static file server (php -S) to the
 * poller's loopback AUTH_LISTEN socket.
 */
final class AuthIntakeProxy
{
    /**
     * @return array{0: int, 1: array<string, string>, 2: string} status, headers, body
     */
    public static function forward(
        string $listen,
        string $method,
        string $path,
        string $body,
        string $contentType = '',
    ): array {
        $unavailable = [502, ['Content-Type' => 'application/json; charset=utf-8'], '{"ok":false,"error":"login intake unavailable"}'];
        if ($listen === '') {
            return [404, ['Content-Type' => 'application/json; charset=utf-8'], '{"ok":false,"error":"not found"}'];
        }

        $socket = @stream_socket_client('tcp://' . $listen, $errno, $errstr, 2);
        if ($socket === false) {
            return $unavailable;
        }

        stream_set_timeout($socket, 5);
        $path = $path === '' ? '/' : $path;
        $request = strtoupper($method) . ' ' . $path . " HTTP/1.1\r\n"
            . 'Host: ' . $listen . "\r\n"
            . "Connection: close\r\n";
        if ($body !== '') {
            $type = $contentType !== '' ? $contentType : 'application/json';
            $request .= 'Content-Type: ' . $type . "\r\n";
            $request .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
        $request .= "\r\n" . $body;
        fwrite($socket, $request);
        $raw = stream_get_contents($socket) ?: '';
        fclose($socket);

        if ($raw === '' || !str_contains($raw, "\r\n\r\n")) {
            return $unavailable;
        }

        [$headerBlock, $responseBody] = explode("\r\n\r\n", $raw, 2);
        $lines = explode("\r\n", $headerBlock);
        $statusLine = array_shift($lines) ?? '';
        if (preg_match('/\AHTTP\/\d\.\d (\d{3})/', $statusLine, $match) !== 1) {
            return $unavailable;
        }

        $headers = ['Content-Type' => 'application/json; charset=utf-8'];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            if ($name === 'content-type') {
                $headers['Content-Type'] = trim(substr($line, $colon + 1));
            }
        }

        return [(int) $match[1], $headers, $responseBody];
    }
}
