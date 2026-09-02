<?php

declare(strict_types=1);

namespace App\API;

use App\DTO\GlucoseReadingDTO;
use Carbon\Carbon;

final class ReadingPresenter
{
    public const FRESH_SECONDS = 180;
    public const STALE_SECONDS = 600;

    /**
     * Fields persisted for the static dashboard. Age and stale flags are derived in the browser.
     *
     * @return array{glucoseMgDl: ?int, trend: ?string, trendArrow: ?string, timestamp: ?string}
     */
    public static function stored(?GlucoseReadingDTO $reading): array
    {
        if ($reading === null) {
            return [
                'glucoseMgDl' => null,
                'trend' => null,
                'trendArrow' => null,
                'timestamp' => null,
            ];
        }

        return [
            'glucoseMgDl' => $reading->glucoseMgDl,
            'trend' => $reading->trend,
            'trendArrow' => $reading->trendArrow,
            'timestamp' => self::iso($reading->timestamp),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function current(?GlucoseReadingDTO $reading, ?Carbon $now = null): array
    {
        if ($reading === null) {
            return [
                'glucoseMgDl' => null,
                'trend' => null,
                'trendArrow' => null,
                'timestamp' => null,
                'ageSeconds' => null,
                'stale' => true,
                'staleLevel' => 'missing',
            ];
        }

        $now ??= Carbon::now('UTC');
        $age = max(0, $now->getTimestamp() - $reading->timestamp->getTimestamp());
        $level = self::staleLevel($age);

        return [
            'glucoseMgDl' => $reading->glucoseMgDl,
            'trend' => $reading->trend,
            'trendArrow' => $reading->trendArrow,
            'timestamp' => self::iso($reading->timestamp),
            'ageSeconds' => $age,
            'stale' => $level !== 'fresh',
            'staleLevel' => $level,
        ];
    }

    /**
     * @param GlucoseReadingDTO[] $readings
     * @return list<array<string, mixed>>
     */
    public static function history(array $readings): array
    {
        return array_map(static function (GlucoseReadingDTO $reading): array {
            return [
                'glucoseMgDl' => $reading->glucoseMgDl,
                'trend' => $reading->trend,
                'trendArrow' => $reading->trendArrow,
                'timestamp' => self::iso($reading->timestamp),
            ];
        }, $readings);
    }

    public static function staleLevel(int $ageSeconds): string
    {
        if ($ageSeconds < self::FRESH_SECONDS) {
            return 'fresh';
        }
        if ($ageSeconds < self::STALE_SECONDS) {
            return 'stale';
        }

        return 'disconnected';
    }

    public static function iso(Carbon $timestamp): string
    {
        return $timestamp->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
