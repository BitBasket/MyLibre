<?php

declare(strict_types=1);

namespace App\Support;

final class Logger
{
    /** @param resource|null $stream */
    public function __construct(
        private mixed $stream = null,
    ) {
    }

    public function info(string $message): void
    {
        $this->write('info', $message);
    }

    public function warning(string $message): void
    {
        $this->write('warning', $message);
    }

    public function error(string $message): void
    {
        $this->write('error', $message);
    }

    private function write(string $level, string $message): void
    {
        $line = sprintf("[%s] %s: %s\n", gmdate('Y-m-d\TH:i:s\Z'), $level, $this->redact($message));
        $stream = $this->stream ?? fopen('php://stderr', 'ab');
        if (is_resource($stream)) {
            fwrite($stream, $line);
        }
    }

    private function redact(string $message): string
    {
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._\-+=\/]+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/("?(?:password|token|authorization)"?\s*[:=]\s*")[^"]+"/i', '$1[redacted]"', $message) ?? $message;

        return $message;
    }
}
