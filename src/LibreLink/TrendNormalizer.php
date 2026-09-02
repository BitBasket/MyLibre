<?php

declare(strict_types=1);

namespace App\LibreLink;

final class TrendNormalizer
{
    /**
     * LibreLinkUp TrendArrow values observed in current unofficial clients:
     * 1 down, 2 forty-five down, 3 flat, 4 forty-five up, 5 up.
     *
     * @return array{0:?string, 1:?string}
     */
    public static function fromArrow(mixed $trendArrow): array
    {
        if ($trendArrow === null || $trendArrow === '') {
            return [null, null];
        }

        $code = (int) $trendArrow;

        return match ($code) {
            1 => ['rapidly falling', '↓'],
            2 => ['falling', '↘'],
            3 => ['stable', '→'],
            4 => ['rising', '↗'],
            5 => ['rapidly rising', '↑'],
            default => [null, null],
        };
    }
}
