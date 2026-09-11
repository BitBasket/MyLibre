<?php

declare(strict_types=1);

namespace App\Database;

use App\Contract\GlucoseRepository;
use App\DTO\GlucoseReadingDTO;
use App\Security\PgpCrypto;
use Carbon\Carbon;
use RuntimeException;

final class EncryptedGlucoseRepository implements GlucoseRepository
{
    /** @var GlucoseReadingDTO[]|null */
    private ?array $rows = null;

    private bool $dirty = false;

    public function __construct(
        private readonly string $path,
        private readonly PgpCrypto $crypto,
        private readonly string $passphrase,
    ) {
        if ($passphrase === '') {
            throw new RuntimeException('Encrypted repository requires an unlock passphrase.');
        }
    }

    public function save(GlucoseReadingDTO $reading): bool
    {
        $rows = $this->all();
        foreach ($rows as $row) {
            if ($row->timestamp->getTimestamp() === $reading->timestamp->getTimestamp()) {
                return false;
            }
        }

        $rows[] = $reading;
        usort($rows, static fn (GlucoseReadingDTO $a, GlucoseReadingDTO $b): int => $a->timestamp <=> $b->timestamp);
        $this->rows = $rows;
        $this->dirty = true;

        return true;
    }

    /**
     * @param GlucoseReadingDTO[] $readings
     */
    public function import(array $readings): int
    {
        $added = 0;
        foreach ($readings as $reading) {
            if ($this->save($reading)) {
                $added++;
            }
        }
        $this->flush();

        return $added;
    }

    public function latest(): ?GlucoseReadingDTO
    {
        $this->flush();
        $rows = $this->all();

        return $rows === [] ? null : $rows[array_key_last($rows)];
    }

    public function since(Carbon $timestamp): array
    {
        $this->flush();

        return array_values(array_filter(
            $this->all(),
            static fn (GlucoseReadingDTO $row): bool => $row->timestamp->greaterThanOrEqualTo($timestamp),
        ));
    }

    public function all(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        if (!is_file($this->path)) {
            $this->rows = [];

            return $this->rows;
        }

        $raw = file_get_contents($this->path);
        if ($raw === false) {
            throw new RuntimeException('Unable to read encrypted glucose history.');
        }

        $json = json_decode($this->crypto->decrypt($raw, $this->passphrase), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($json)) {
            throw new RuntimeException('Encrypted glucose history is invalid.');
        }

        $this->rows = array_map(static fn (array $row): GlucoseReadingDTO => new GlucoseReadingDTO([
            'timestamp' => Carbon::parse($row['timestamp'], 'UTC'),
            'glucoseMgDl' => (int) $row['glucoseMgDl'],
            'trend' => $row['trend'] ?? null,
            'trendArrow' => $row['trendArrow'] ?? null,
            'source' => (string) ($row['source'] ?? 'librelinkup'),
        ]), $json);

        return $this->rows;
    }

    public function flush(): void
    {
        if (!$this->dirty || $this->rows === null) {
            return;
        }

        $this->write($this->rows);
        $this->dirty = false;
    }

    /**
     * @param GlucoseReadingDTO[] $rows
     */
    private function write(array $rows): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create encrypted data directory.');
        }

        $payload = array_map(static fn (GlucoseReadingDTO $row): array => [
            'timestamp' => $row->timestamp->toIso8601String(),
            'glucoseMgDl' => $row->glucoseMgDl,
            'trend' => $row->trend,
            'trendArrow' => $row->trendArrow,
            'source' => $row->source,
        ], $rows);

        $tmp = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $encrypted = $this->crypto->encrypt(json_encode($payload, JSON_THROW_ON_ERROR), $this->passphrase);
        if (file_put_contents($tmp, $encrypted, LOCK_EX) === false || !rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to publish encrypted glucose history.');
        }
        chmod($this->path, 0600);
    }
}
