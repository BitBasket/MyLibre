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
        $byDay = [];
        foreach ($readings as $reading) {
            $utc = $reading->timestamp->copy()->utc();
            $day = $utc->format('Ymd');
            $slot = ($utc->hour * 60) + $utc->minute;
            if ($slot < 0 || $slot >= self::SLOTS) {
                continue;
            }
            $byDay[$day][$slot] = $reading->glucoseMgDl;
        }
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
