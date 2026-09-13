<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Contract\LibreLinkAuthenticator;
use App\DTO\LibreLinkUpSessionDTO;
use App\LibreLink\LibreLinkAuthException;

final class FakeLibreLinkAuthenticator implements LibreLinkAuthenticator
{
    public bool $has = false;

    public ?string $email = null;

    public ?string $password = null;

    public function login(string $email, string $password, ?string $patientId = null): LibreLinkUpSessionDTO
    {
        if ($password === 'bad') {
            throw new LibreLinkAuthException('LibreLinkUp authentication failed: HTTP 401');
        }
        $this->has = true;
        $this->email = $email;
        $this->password = $password;

        return new LibreLinkUpSessionDTO([
            'token' => 'tok',
            'baseUri' => 'https://api.libreview.io/',
            'accountId' => 'acc',
            'patientId' => $patientId,
        ]);
    }

    public function hasSession(): bool
    {
        return $this->has;
    }

    public function needsInteractiveLogin(): bool
    {
        return !$this->has;
    }
}
