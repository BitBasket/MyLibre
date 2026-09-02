<?php

declare(strict_types=1);

namespace App\Mock;

use App\Contract\GlucoseProvider;
use App\DTO\GlucoseReadingDTO;
use App\DTO\LibreLinkUpSessionDTO;
use Carbon\Carbon;

final class MockGlucoseProvider implements GlucoseProvider
{
    public function authenticate(): LibreLinkUpSessionDTO
    {
        return new LibreLinkUpSessionDTO([
            'token' => 'mock-session',
            'baseUri' => 'https://api.libreview.io/',
            'accountId' => 'mock-account',
            'expiresAt' => Carbon::now('UTC')->addDay(),
            'patientId' => 'mock-patient',
        ]);
    }

    public function getCurrentReading(): GlucoseReadingDTO
    {
        $now = Carbon::now('UTC')->startOfMinute();

        return $this->readingAt($now);
    }

    public function getHistory(): array
    {
        $end = Carbon::now('UTC')->startOfMinute();
        $start = $end->copy()->subHours(24);
        $readings = [];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addMinutes(5)) {
            $readings[] = $this->readingAt($cursor->copy());
        }

        return $readings;
    }

    private function readingAt(Carbon $timestamp): GlucoseReadingDTO
    {
        $wave = sin($timestamp->timestamp / 1800);
        $mgDl = (int) round(140 + (35 * $wave));
        $delta = (int) round(8 * cos($timestamp->timestamp / 1800));

        if ($delta <= -6) {
            [$trend, $arrow] = ['falling', '↘'];
        } elseif ($delta >= 6) {
            [$trend, $arrow] = ['rising', '↗'];
        } else {
            [$trend, $arrow] = ['stable', '→'];
        }

        return new GlucoseReadingDTO([
            'timestamp' => $timestamp,
            'glucoseMgDl' => $mgDl,
            'trend' => $trend,
            'trendArrow' => $arrow,
            'source' => 'mock',
        ]);
    }
}
