<?php

declare(strict_types=1);

namespace App\Poller;

use RuntimeException;

/**
 * Persists {@see PollState} as a tiny plaintext JSON file.
 *
 * The file contains only timestamps (numbers) and the earliest reading time.
 * It is deliberately NOT encrypted and deliberately NOT a glucose history: it
 * exists so the poller can be a write-only relay that never decrypts a reading.
 */
final class PollStateStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function load(): PollState
    {
        if (!is_file($this->path)) {
            return PollState::empty();
        }

        $raw = file_get_contents($this->path);
        if ($raw === false) {
            throw new RuntimeException('Unable to read the poller state file.');
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return PollState::empty();
        }

        $seen = [];
        foreach ($json['seen'] ?? [] as $timestamp) {
            if (is_int($timestamp) || (is_string($timestamp) && ctype_digit($timestamp))) {
                $seen[] = (int) $timestamp;
            }
        }

        return new PollState(
            $seen,
            isset($json['firstReadingAt']) ? (int) $json['firstReadingAt'] : null,
        );
    }

    public function save(PollState $state): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create the poller state directory.');
        }

        $payload = json_encode([
            'seen' => $state->seen,
            'firstReadingAt' => $state->firstReadingAt,
        ], JSON_THROW_ON_ERROR);

        $tmp = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $payload, LOCK_EX) === false || !rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to publish the poller state file.');
        }
        chmod($this->path, 0600);
    }
}
