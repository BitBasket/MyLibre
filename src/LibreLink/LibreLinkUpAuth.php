<?php

declare(strict_types=1);

namespace App\LibreLink;

use PHPExperts\RESTSpeaker\RESTAuth;

final class LibreLinkUpAuth extends RESTAuth
{
    public function __construct(
        private readonly string $token,
        private readonly ?string $accountId,
        private readonly string $clientVersion,
    ) {
        parent::__construct(self::AUTH_MODE_CUSTOM);
    }

    protected function generateCustomAuthOptions(): array
    {
        $headers = LibreLinkUpEndpoints::clientHeaders($this->clientVersion);
        $headers['Authorization'] = 'Bearer ' . $this->token;

        if ($this->accountId !== null && $this->accountId !== '') {
            $headers['Account-Id'] = hash('sha256', $this->accountId);
        }

        return [
            'http_errors' => false,
            'headers' => $headers,
        ];
    }
}
