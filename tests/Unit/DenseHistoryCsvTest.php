<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\DTO\GlucoseReadingDTO;
use App\Export\DenseHistoryCsv;
use PHPUnit\Framework\TestCase;

final class DenseHistoryCsvTest extends TestCase
{
    public function testEncodesUtcMinuteSlotsAndSkipsHoles(): void
    {
        $csv = DenseHistoryCsv::encode([
            self::reading('2026-09-08T00:00:00Z', 223),
            self::reading('2026-09-08T00:01:10Z', 222),
            self::reading('2026-09-08T00:03:00Z', 220),
        ]);

        $line = rtrim($csv, "\n");
        $parts = explode(',', $line);
        $this->assertSame('20260908', $parts[0]);
        $this->assertCount(1 + DenseHistoryCsv::SLOTS, $parts);
        $this->assertSame('223', $parts[1]);
        $this->assertSame('222', $parts[2]);
        $this->assertSame('', $parts[3]);
        $this->assertSame('220', $parts[4]);
        $this->assertSame("\n", substr($csv, -1));
    }

    public function testLaterValueWinsTheSameUtcMinute(): void
    {
        $csv = DenseHistoryCsv::encode([
            self::reading('2026-09-08T00:00:10Z', 100),
            self::reading('2026-09-08T00:00:50Z', 101),
        ]);
        $parts = explode(',', rtrim($csv, "\n"));
        $this->assertSame('101', $parts[1]);
    }

    public function testGroupsDaysAndParsesJsonStoreRows(): void
    {
        $readings = DenseHistoryCsv::readingsFromJsonRows([
            [
                'timestamp' => '2026-09-07T23:59:00Z',
                'glucoseMgDl' => 189,
                'trend' => 'stable',
                'trendArrow' => '→',
                'source' => 'librelinkup',
            ],
            [
                'timestamp' => '2026-09-08T00:00:00Z',
                'glucoseMgDl' => 223,
            ],
        ]);
        $this->assertCount(2, $readings);

        $lines = explode("\n", trim(DenseHistoryCsv::encode($readings)));
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('20260907,', $lines[0]);
        $this->assertStringStartsWith('20260908,', $lines[1]);
        $day7 = explode(',', $lines[0]);
        $this->assertSame('189', $day7[1440]);
        $day8 = explode(',', $lines[1]);
        $this->assertSame('223', $day8[1]);
    }

    public function testMergeOverlaysExistingCsvAndLaterValueWinsTheMinute(): void
    {
        $existing = DenseHistoryCsv::encode([
            self::reading('2026-09-08T00:00:00Z', 100),
            self::reading('2026-09-08T00:02:00Z', 102),
        ]);
        $merged = DenseHistoryCsv::merge($existing, [
            self::reading('2026-09-08T00:00:40Z', 101),
            self::reading('2026-09-08T00:01:00Z', 110),
            self::reading('2026-09-07T23:59:00Z', 189),
        ]);

        $decoded = DenseHistoryCsv::decode($merged);
        $this->assertCount(4, $decoded);
        $this->assertSame('2026-09-07 23:59:00', $decoded[0]->timestamp->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(189, $decoded[0]->glucoseMgDl);
        $this->assertSame(101, $decoded[1]->glucoseMgDl);
        $this->assertSame(110, $decoded[2]->glucoseMgDl);
        $this->assertSame(102, $decoded[3]->glucoseMgDl);
    }

    public function testDecodeSkipsCommentsBlankLinesAndNonNumericCells(): void
    {
        $line = DenseHistoryCsv::encode([self::reading('2026-09-08T00:00:00Z', 223)]);
        $text = "# exported\n\n" . $line;
        $decoded = DenseHistoryCsv::decode($text);
        $this->assertCount(1, $decoded);
        $this->assertSame(223, $decoded[0]->glucoseMgDl);
    }

    private static function reading(string $iso, int $mgdl): GlucoseReadingDTO
    {
        return new GlucoseReadingDTO([
            'timestamp' => $iso,
            'glucoseMgDl' => $mgdl,
            'trend' => null,
            'trendArrow' => null,
        ]);
    }
}
