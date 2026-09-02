<?php

declare(strict_types=1);

namespace App\LibreLink;

use App\DTO\LibreLinkUpSessionDTO;
use App\Support\Logger;

final class SessionStore
{
    public function __construct(
        private readonly string $path,
        private readonly Logger $logger,
    ) {
    }

    public function load(): ?LibreLinkUpSessionDTO
    {
        if (!is_readable($this->path)) {
            return null;
        }

        $raw = file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || empty($data['token']) || empty($data['baseUri'])) {
                return null;
            }

            return new LibreLinkUpSessionDTO([
                'token' => (string) $data['token'],
                'baseUri' => LibreLinkUpEndpoints::normalizeBaseUri((string) $data['baseUri']),
                'accountId' => isset($data['accountId']) ? (string) $data['accountId'] : null,
                'expiresAt' => $data['expiresAt'] ?? null,
                'patientId' => isset($data['patientId']) ? (string) $data['patientId'] : null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('LibreLinkUp session cache could not be loaded; a new login will be attempted.');
            return null;
        }
    }

    public function save(LibreLinkUpSessionDTO $session): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new LibreLinkException('Unable to create session directory.');
        }

        $payload = json_encode([
            'token' => $session->token,
            'baseUri' => $session->baseUri,
            'accountId' => $session->accountId,
            'expiresAt' => $session->expiresAt?->toIso8601String(),
            'patientId' => $session->patientId,
        ], JSON_THROW_ON_ERROR);

        $tmp = $this->path . '.tmp';
        if (file_put_contents($tmp, $payload) === false) {
            throw new LibreLinkException('Unable to write session cache.');
        }

        chmod($tmp, 0600);
        rename($tmp, $this->path);
        chmod($this->path, 0600);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
