<?php

declare(strict_types=1);

namespace App\Http;

use App\Contract\LibreLinkAuthenticator;
use App\LibreLink\LibreLinkAuthException;
use App\LibreLink\LibreLinkException;
use App\Support\Logger;

/**
 * HTTP adapter for a one-shot LibreLinkUp login.
 *
 * Same request shape a Lambda function URL can accept later: POST JSON
 * {email, password, patientId?} and persist only the encrypted session token.
 * The password is never logged and never written to disk.
 */
final class AuthIntakeHandler
{
    private const MAX_ATTEMPTS = 5;

    private const ATTEMPT_WINDOW_SECONDS = 300;

    /** @var list<int> */
    private array $attempts = [];

    public function __construct(
        private readonly LibreLinkAuthenticator $authenticator,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{0: int, 1: array<string, mixed>, 2: bool} status, JSON payload, login succeeded
     */
    public function handle(string $method, string $path, string $body): array
    {
        $path = strtolower(rtrim(strtok($path, '?') ?: $path, '/'));
        $method = strtoupper($method);

        if ($path === '/api/librelink/status' || $path === '/status') {
            if ($method !== 'GET' && $method !== 'HEAD') {
                return [405, ['ok' => false, 'error' => 'method not allowed'], false];
            }

            return [200, [
                'ok' => true,
                'authenticated' => $this->authenticator->hasSession(),
            ], false];
        }

        if ($path === '/api/librelink/login' || $path === '/login') {
            if ($method !== 'POST') {
                return [405, ['ok' => false, 'error' => 'method not allowed'], false];
            }

            return $this->login($body);
        }

        return [404, ['ok' => false, 'error' => 'not found'], false];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>, 2: bool}
     */
    private function login(string $body): array
    {
        if (!$this->allowAttempt()) {
            $this->logger->warning('LibreLink login intake rate-limited');

            return [429, ['ok' => false, 'error' => 'too many attempts'], false];
        }

        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [400, ['ok' => false, 'error' => 'invalid json'], false];
        }

        if (!is_array($payload)) {
            return [400, ['ok' => false, 'error' => 'invalid json'], false];
        }

        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $patientId = trim((string) ($payload['patientId'] ?? ''));

        if ($email === '' || $password === '') {
            return [400, ['ok' => false, 'error' => 'email and password are required'], false];
        }
        if (strlen($email) > 320 || strlen($password) > 256 || strlen($patientId) > 128) {
            return [400, ['ok' => false, 'error' => 'invalid credentials'], false];
        }

        try {
            $this->authenticator->login($email, $password, $patientId !== '' ? $patientId : null);
        } catch (LibreLinkAuthException) {
            $this->logger->warning('LibreLink login intake rejected by Abbott');

            return [401, ['ok' => false, 'error' => 'authentication failed'], false];
        } catch (LibreLinkException $e) {
            $this->logger->error('LibreLink login intake failed: ' . $e->getMessage());

            return [502, ['ok' => false, 'error' => 'authentication failed'], false];
        } catch (\Throwable $e) {
            $this->logger->error('LibreLink login intake failed: ' . $e->getMessage());

            return [500, ['ok' => false, 'error' => 'unavailable'], false];
        }

        $this->logger->info('LibreLink login intake stored a session');

        return [200, ['ok' => true, 'authenticated' => true], true];
    }

    private function allowAttempt(): bool
    {
        $now = time();
        $this->attempts = array_values(array_filter(
            $this->attempts,
            static fn (int $at): bool => $at > $now - self::ATTEMPT_WINDOW_SECONDS,
        ));
        if (count($this->attempts) >= self::MAX_ATTEMPTS) {
            return false;
        }
        $this->attempts[] = $now;

        return true;
    }
}
