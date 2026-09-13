<?php

declare(strict_types=1);

namespace App\Contract;

use App\DTO\LibreLinkUpSessionDTO;

interface LibreLinkAuthenticator
{
    public function login(string $email, string $password, ?string $patientId = null): LibreLinkUpSessionDTO;

    public function hasSession(): bool;

    /**
     * True when Abbott login is impossible until the dashboard posts credentials.
     * False when a cached session exists or env email/password can log in.
     */
    public function needsInteractiveLogin(): bool;
}
