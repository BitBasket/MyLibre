<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\API\ReadingPresenter;
use App\DTO\GlucoseReadingDTO;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class ReadingPresenterTest extends TestCase
{
    public function testDerivesAgeAndStaleFlags(): void
    {
        $now = Carbon::parse('2026-09-01T19:32:00Z');
        $reading = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
        ]);

        $payload = ReadingPresenter::current($reading, $now);
        $this->assertSame(60, $payload['ageSeconds']);
        $this->assertFalse($payload['stale']);
        $this->assertSame('fresh', $payload['staleLevel']);
        $this->assertSame('2026-09-01T19:31:00Z', $payload['timestamp']);
    }

    public function testMarksDisconnectedReadings(): void
    {
        $now = Carbon::parse('2026-09-01T19:45:00Z');
        $reading = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
        ]);

        $payload = ReadingPresenter::current($reading, $now);
        $this->assertTrue($payload['stale']);
        $this->assertSame('disconnected', $payload['staleLevel']);
    }

    public function testStoredOmitsAgeFlags(): void
    {
        $reading = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
        ]);

        $payload = ReadingPresenter::stored($reading);
        $this->assertSame(174, $payload['glucoseMgDl']);
        $this->assertSame('2026-09-01T19:31:00Z', $payload['timestamp']);
        $this->assertArrayNotHasKey('ageSeconds', $payload);
        $this->assertArrayNotHasKey('staleLevel', $payload);

        $empty = ReadingPresenter::stored(null);
        $this->assertNull($empty['glucoseMgDl']);
        $this->assertNull($empty['timestamp']);
    }

    public function testHistoryOmitsTrendFields(): void
    {
        $reading = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
        ]);

        $payload = ReadingPresenter::history([$reading])[0];
        $this->assertSame(174, $payload['glucoseMgDl']);
        $this->assertSame('2026-09-01T19:31:00Z', $payload['timestamp']);
        $this->assertArrayNotHasKey('trend', $payload);
        $this->assertArrayNotHasKey('trendArrow', $payload);
    }
}
