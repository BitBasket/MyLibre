<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\DTO\GlucoseReadingDTO;
use Carbon\Carbon;
use Error;
use PHPExperts\DataTypeValidator\InvalidDataTypeException;
use PHPUnit\Framework\TestCase;

final class GlucoseReadingDTOTest extends TestCase
{
    public function testConstructsFromValidInput(): void
    {
        $reading = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
            'source' => 'librelinkup',
        ]);

        $this->assertSame(174, $reading->glucoseMgDl);
        $this->assertInstanceOf(Carbon::class, $reading->timestamp);
        $this->assertSame('falling', $reading->trend);
        $this->assertSame('↘', $reading->trendArrow);
        $this->assertSame('librelinkup', $reading->source);
        $this->assertSame('2026-09-01 19:31:00', $reading->timestamp->utc()->format('Y-m-d H:i:s'));
    }

    public function testRejectsInvalidTypes(): void
    {
        $this->expectException(InvalidDataTypeException::class);
        new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 'high',
            'trend' => 'falling',
            'trendArrow' => '↘',
        ]);
    }

    public function testSerializesToArrayAndJson(): void
    {
        $reading = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
        ]);

        $array = $reading->toArray();
        $this->assertSame(174, $array['glucoseMgDl']);
        $this->assertInstanceOf(Carbon::class, $array['timestamp']);

        $json = json_decode(json_encode($reading, JSON_THROW_ON_ERROR), true);
        $this->assertSame(174, $json['glucoseMgDl']);
        $this->assertNotFalse(strtotime($json['timestamp']));
    }

    public function testIsImmutable(): void
    {
        $reading = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => null,
            'trendArrow' => null,
        ]);

        $this->expectException(Error::class);
        $reading->glucoseMgDl = 99;
    }
}
