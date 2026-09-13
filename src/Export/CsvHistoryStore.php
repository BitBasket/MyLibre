<?php

declare(strict_types=1);

namespace App\Export;

use App\DTO\GlucoseReadingDTO;
use RuntimeException;

/**
 * Portable dense history file the dashboard Import button accepts
 * (`mylibre.history.csv`: one UTC day per line, 1440 minute slots).
 */
final class CsvHistoryStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param GlucoseReadingDTO[] $readings
     */
    public function merge(array $readings): void
    {
        if ($readings === []) {
            return;
        }

        $existing = '';
        if (is_file($this->path)) {
            $raw = file_get_contents($this->path);
            if ($raw === false) {
                throw new RuntimeException('Unable to read history CSV: ' . $this->path);
            }
            $existing = $raw;
        }

        $csv = DenseHistoryCsv::merge($existing, $readings);
        $this->publish($csv);
    }

    private function publish(string $csv): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create history CSV directory.');
        }

        $tmp = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $csv, LOCK_EX) === false || !rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to write history CSV: ' . $this->path);
        }
        chmod($this->path, 0600);
    }
}
