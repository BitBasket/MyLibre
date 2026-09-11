<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Database\EncryptedGlucoseRepository;
use App\DTO\GlucoseReadingDTO;
use App\Tests\Support\PgpKeyFactory;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class EncryptedGlucoseRepositoryTest extends TestCase
{
    public function testInsertLatestHistoryAndDedup(): void
    {
        $dir = sys_get_temp_dir() . '/mylibre-enc-repo-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $crypto = PgpKeyFactory::make($dir);
        $path = $dir . '/glucose.json.asc';
        $repository = new EncryptedGlucoseRepository($path, $crypto, 'test-passphrase');

        $first = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
            'source' => 'librelinkup',
        ]);
        $second = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:32:00Z',
            'glucoseMgDl' => 176,
            'trend' => 'stable',
            'trendArrow' => '→',
            'source' => 'librelinkup',
        ]);

        $this->assertTrue($repository->save($first));
        $this->assertFalse($repository->save($first));
        $this->assertTrue($repository->save($second));
        $repository->flush();

        $this->assertFileExists($path);
        $this->assertStringContainsString('BEGIN PGP MESSAGE', (string) file_get_contents($path));

        $reloaded = new EncryptedGlucoseRepository($path, $crypto, 'test-passphrase');
        $this->assertSame(176, $reloaded->latest()?->glucoseMgDl);
        $this->assertCount(2, $reloaded->all());
        $this->assertCount(1, $reloaded->since(Carbon::parse('2026-09-01T19:32:00Z')));
    }

    public function testImportDeduplicatesByTimestamp(): void
    {
        $dir = sys_get_temp_dir() . '/mylibre-enc-import-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $crypto = PgpKeyFactory::make($dir);
        $repository = new EncryptedGlucoseRepository($dir . '/glucose.json.asc', $crypto, 'test-passphrase');

        $first = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
            'source' => 'librelinkup',
        ]);
        $second = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:32:00Z',
            'glucoseMgDl' => 176,
            'trend' => 'stable',
            'trendArrow' => '→',
            'source' => 'librelinkup',
        ]);

        $this->assertSame(2, $repository->import([$first, $second]));
        $this->assertSame(0, $repository->import([$first, $second]));
        $this->assertCount(2, $repository->all());
    }

    public function testImportMergesUnsortedBatchOnceAndKeepsFirstDuplicate(): void
    {
        $dir = sys_get_temp_dir() . '/mylibre-enc-import-batch-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $crypto = PgpKeyFactory::make($dir);
        $repository = new EncryptedGlucoseRepository($dir . '/glucose.json.asc', $crypto, 'test-passphrase');
        $existing = $this->reading('2026-09-01T19:32:00Z', 176);
        $this->assertSame(1, $repository->import([$existing]));

        $added = $repository->import([
            $this->reading('2026-09-01T19:33:00Z', 180),
            $this->reading('2026-09-01T19:31:00Z', 174),
            $this->reading('2026-09-01T19:32:00Z', 999),
            $this->reading('2026-09-01T19:33:00Z', 181),
        ]);

        $this->assertSame(2, $added);
        $history = $repository->all();
        $this->assertSame(
            [174, 176, 180],
            array_map(static fn (GlucoseReadingDTO $row): int => $row->glucoseMgDl, $history),
        );
        $this->assertSame(
            [
                '2026-09-01T19:31:00+00:00',
                '2026-09-01T19:32:00+00:00',
                '2026-09-01T19:33:00+00:00',
            ],
            array_map(static fn (GlucoseReadingDTO $row): string => $row->timestamp->toIso8601String(), $history),
        );
        $this->assertSame(180, $repository->latest()?->glucoseMgDl);
    }

    private function reading(string $timestamp, int $mgDl): GlucoseReadingDTO
    {
        return new GlucoseReadingDTO([
            'timestamp' => $timestamp,
            'glucoseMgDl' => $mgDl,
            'trend' => 'stable',
            'trendArrow' => '→',
            'source' => 'librelinkup',
        ]);
    }
}
