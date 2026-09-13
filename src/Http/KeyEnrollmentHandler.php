<?php

declare(strict_types=1);

namespace App\Http;

use App\Security\PgpCrypto;
use RuntimeException;

/**
 * Stores the user's OpenPGP public key for outbound snapshots.
 *
 * The private key is refused. First write wins; a later POST of the same
 * fingerprint is idempotent, a different fingerprint is a 409.
 */
final class KeyEnrollmentHandler
{
    private const MAX_KEY_BYTES = 65536;

    private const MAX_ATTEMPTS = 10;

    private const ATTEMPT_WINDOW_SECONDS = 300;

    /** @var list<int> */
    private array $attempts = [];

    public function __construct(
        private readonly string $userPublicKeyPath,
    ) {
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function handle(string $method, string $path, string $body): array
    {
        $path = strtolower(rtrim(strtok($path, '?') ?: $path, '/'));
        $method = strtoupper($method);

        if ($path !== '/api/keys' && $path !== '/api/keys/status') {
            return [404, ['ok' => false, 'error' => 'not found']];
        }

        if ($method === 'GET' || $method === 'HEAD') {
            return $this->status();
        }

        if ($path === '/api/keys' && $method === 'POST') {
            return $this->enroll($body);
        }

        return [405, ['ok' => false, 'error' => 'method not allowed']];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function status(): array
    {
        if ($this->userPublicKeyPath === '' || !is_readable($this->userPublicKeyPath)) {
            return [200, ['ok' => true, 'enrolled' => false]];
        }

        try {
            return [200, [
                'ok' => true,
                'enrolled' => true,
                'fingerprint' => (new PgpCrypto($this->userPublicKeyPath))->publicFingerprint(),
            ]];
        } catch (RuntimeException) {
            return [200, ['ok' => true, 'enrolled' => false]];
        }
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function enroll(string $body): array
    {
        if (!$this->allowAttempt()) {
            return [429, ['ok' => false, 'error' => 'too many attempts']];
        }

        if ($this->userPublicKeyPath === '') {
            return [503, ['ok' => false, 'error' => 'enrollment is not configured']];
        }

        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [400, ['ok' => false, 'error' => 'invalid json']];
        }

        if (!is_array($payload)) {
            return [400, ['ok' => false, 'error' => 'invalid json']];
        }

        if (isset($payload['privateKey']) || isset($payload['private'])) {
            return [400, ['ok' => false, 'error' => 'the private key must not be uploaded']];
        }

        $armored = trim((string) ($payload['publicKey'] ?? $payload['public'] ?? ''));
        if ($armored === '') {
            return [400, ['ok' => false, 'error' => 'public key is required']];
        }
        if (strlen($armored) > self::MAX_KEY_BYTES) {
            return [400, ['ok' => false, 'error' => 'public key is too large']];
        }
        if (str_contains($armored, 'BEGIN PGP PRIVATE KEY')) {
            return [400, ['ok' => false, 'error' => 'the private key must not be uploaded']];
        }
        if (!str_contains($armored, 'BEGIN PGP PUBLIC KEY BLOCK')) {
            return [400, ['ok' => false, 'error' => 'public key must be an ASCII-armored OpenPGP public key']];
        }

        $directory = dirname($this->userPublicKeyPath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            return [500, ['ok' => false, 'error' => 'unable to store key']];
        }

        $tmp = $this->userPublicKeyPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $armored . "\n", LOCK_EX) === false) {
            return [500, ['ok' => false, 'error' => 'unable to store key']];
        }
        chmod($tmp, 0644);

        try {
            $incoming = new PgpCrypto($tmp);
            $incoming->validate();
            $fingerprint = $incoming->publicFingerprint();
        } catch (RuntimeException) {
            @unlink($tmp);

            return [400, ['ok' => false, 'error' => 'invalid public key']];
        }

        if (is_readable($this->userPublicKeyPath)) {
            try {
                $existing = (new PgpCrypto($this->userPublicKeyPath))->publicFingerprint();
            } catch (RuntimeException) {
                $existing = '';
            }
            if ($existing !== '' && strcasecmp($existing, $fingerprint) !== 0) {
                @unlink($tmp);

                return [409, [
                    'ok' => false,
                    'error' => 'a different public key is already enrolled',
                    'fingerprint' => $existing,
                ]];
            }
            @unlink($tmp);

            return [200, [
                'ok' => true,
                'enrolled' => true,
                'fingerprint' => $existing !== '' ? $existing : $fingerprint,
            ]];
        }

        if (!@rename($tmp, $this->userPublicKeyPath)) {
            @unlink($tmp);

            return [500, ['ok' => false, 'error' => 'unable to store key']];
        }
        chmod($this->userPublicKeyPath, 0644);

        return [200, [
            'ok' => true,
            'enrolled' => true,
            'fingerprint' => $fingerprint,
        ]];
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
