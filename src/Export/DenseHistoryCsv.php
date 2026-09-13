<?php

declare(strict_types=1);

namespace App\Export;

use App\DTO\GlucoseReadingDTO;
use Carbon\Carbon;

/**
 * Dense on-device history: one UTC day per CSV line, 1440 minute slots.
 *
 *     YYYYMMDD,v0000,v0001,...,v1439
 *
 * Slot N is UTC minute hour*60+minute. An empty cell is a missed poll.
 */
final class DenseHistoryCsv
{
    public const SLOTS = 1440;

    /**
     * @param GlucoseReadingDTO[] $readings
     */
    public static function encode(array $readings): string
    {
        return self::encodeDays(self::overlay([], $readings));
    }

    /**
     * Overlay new readings onto an existing dense CSV. Later values win the
     * same UTC minute, matching dashboard import.
     *
     * @param GlucoseReadingDTO[] $readings
     */
    public static function merge(string $existing, array $readings): string
    {
        return self::encodeDays(self::overlay(self::daysFromCsv($existing), $readings));
    }

    /**
     * @return GlucoseReadingDTO[]
     */
    public static function decode(string $text): array
    {
        $readings = [];
        foreach (self::daysFromCsv($text) as $day => $slots) {
            $start = Carbon::createFromFormat('Ymd His', $day . ' 000000', 'UTC');
            if ($start === false) {
                continue;
            }
            ksort($slots);
            foreach ($slots as $slot => $mgdl) {
                $readings[] = new GlucoseReadingDTO([
                    'timestamp' => $start->copy()->addMinutes($slot),
                    'glucoseMgDl' => $mgdl,
                    'trend' => null,
                    'trendArrow' => null,
                    'source' => 'librelinkup',
                ]);
            }
        }

        return $readings;
    }

    /**
     * @param array<string, array<int, int>> $byDay
     * @param GlucoseReadingDTO[] $readings
     * @return array<string, array<int, int>>
     */
    private static function overlay(array $byDay, array $readings): array
    {
        foreach ($readings as $reading) {
            $utc = $reading->timestamp->copy()->utc();
            $slot = ($utc->hour * 60) + $utc->minute;
            if ($slot < 0 || $slot >= self::SLOTS) {
                continue;
            }
            $byDay[$utc->format('Ymd')][$slot] = $reading->glucoseMgDl;
        }

        return $byDay;
    }

    /**
     * @return array<string, array<int, int>>
     */
    private static function daysFromCsv(string $text): array
    {
        $byDay = [];
        if ($text === '') {
            return $byDay;
        }

        foreach (preg_split('/\r?\n/', $text) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/[,\t]/', $line);
            if ($parts === false || count($parts) < 2 || !preg_match('/^\d{8}$/', $parts[0])) {
                continue;
            }
            $day = $parts[0];
            $limit = min(self::SLOTS, count($parts) - 1);
            for ($i = 0; $i < $limit; $i++) {
                $raw = $parts[$i + 1];
                if ($raw === '' || !is_numeric($raw)) {
                    continue;
                }
                $value = (int) $raw;
                if ($value >= 1000000) {
                    continue;
                }
                $byDay[$day][$i] = $value;
            }
        }

        return $byDay;
    }

    /**
     * @param array<string, array<int, int>> $byDay
     */
    private static function encodeDays(array $byDay): string
    {
        ksort($byDay);

        $out = '';
        foreach ($byDay as $day => $slots) {
            $cells = array_fill(0, self::SLOTS, '');
            foreach ($slots as $slot => $mgdl) {
                $cells[$slot] = (string) $mgdl;
            }
            $out .= $day . ',' . implode(',', $cells) . "\n";
        }

        return $out;
    }

    /**
     * Rows from the v2 encrypted JSON store (EncryptedGlucoseRepository payload)
     * or a plaintext export of the same objects.
     *
     * @param list<array<string, mixed>> $rows
     * @return GlucoseReadingDTO[]
     */
    public static function readingsFromJsonRows(array $rows): array
    {
        $readings = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['timestamp'], $row['glucoseMgDl'])) {
                continue;
            }
            $readings[] = new GlucoseReadingDTO([
                'timestamp' => Carbon::parse((string) $row['timestamp'], 'UTC'),
                'glucoseMgDl' => (int) $row['glucoseMgDl'],
                'trend' => isset($row['trend']) ? (string) $row['trend'] : null,
                'trendArrow' => isset($row['trendArrow']) ? (string) $row['trendArrow'] : null,
                'source' => (string) ($row['source'] ?? 'librelinkup'),
            ]);
        }

        usort(
            $readings,
            static fn (GlucoseReadingDTO $a, GlucoseReadingDTO $b): int => $a->timestamp <=> $b->timestamp,
        );

        return $readings;
    }
}
