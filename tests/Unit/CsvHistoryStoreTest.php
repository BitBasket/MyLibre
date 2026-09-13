<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\DTO\GlucoseReadingDTO;
use App\Export\CsvHistoryStore;
use App\Export\DenseHistoryCsv;
use PHPUnit\Framework\TestCase;

final class CsvHistoryStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/mylibre-csv-' . uniqid('', true) . '.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testMergeCreatesThenUpdatesTheDashboardCsv(): void
    {
        $store = new CsvHistoryStore($this->path);
        $store->merge([
            new GlucoseReadingDTO([
                'timestamp' => '2026-09-08T00:00:00Z',
                'glucoseMgDl' => 100,
                'trend' => null,
                'trendArrow' => null,
            ]),
        ]);
        $store->merge([
            new GlucoseReadingDTO([
                'timestamp' => '2026-09-08T00:01:00Z',
                'glucoseMgDl' => 101,
                'trend' => null,
                'trendArrow' => null,
            ]),
        ]);

        $decoded = DenseHistoryCsv::decode((string) file_get_contents($this->path));
        $this->assertCount(2, $decoded);
        $this->assertSame(100, $decoded[0]->glucoseMgDl);
        $this->assertSame(101, $decoded[1]->glucoseMgDl);
        $this->assertStringEndsWith("\n", (string) file_get_contents($this->path));
    }
}
