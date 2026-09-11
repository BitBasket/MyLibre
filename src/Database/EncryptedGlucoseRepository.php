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

    /** @var array<int, true> */
    private array $timestamps = [];

    private bool $dirty = false;

    private bool $sorted = true;

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
        return $this->merge([$reading]) === 1;
    }

    /**
     * @param GlucoseReadingDTO[] $readings
     */
    public function import(array $readings): int
    {
        $added = $this->merge($readings);
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
        $this->load();
        $this->sort();

        return $this->rows ?? [];
    }

    public function flush(): void
    {
        if (!$this->dirty || $this->rows === null) {
            return;
        }

        $this->sort();
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

    /**
     * @param GlucoseReadingDTO[] $readings
     */
    private function merge(array $readings): int
    {
        $this->load();
        $added = 0;

        foreach ($readings as $reading) {
            $timestamp = $reading->timestamp->getTimestamp();
            if (isset($this->timestamps[$timestamp])) {
                continue;
            }

            $this->timestamps[$timestamp] = true;
            $this->rows[] = $reading;
            $this->dirty = true;
            $this->sorted = false;
            $added++;
        }

        return $added;
    }

    private function load(): void
    {
        if ($this->rows !== null) {
            return;
        }

        if (!is_file($this->path)) {
            $this->rows = [];
            $this->timestamps = [];
            $this->sorted = true;

            return;
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
        $this->timestamps = [];
        foreach ($this->rows as $row) {
            $this->timestamps[$row->timestamp->getTimestamp()] = true;
        }
        $this->sorted = true;
    }

    private function sort(): void
    {
        if ($this->sorted || $this->rows === null) {
            return;
        }

        usort(
            $this->rows,
            static fn (GlucoseReadingDTO $a, GlucoseReadingDTO $b): int => $a->timestamp <=> $b->timestamp,
        );
        $this->sorted = true;
    }
}
