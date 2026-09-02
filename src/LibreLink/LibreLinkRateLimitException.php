<?php

declare(strict_types=1);

namespace App\LibreLink;

final class LibreLinkRateLimitException extends LibreLinkException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds = 300,
        int $code = 429,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
