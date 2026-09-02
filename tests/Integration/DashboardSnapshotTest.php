<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Database\SQLiteConnection;
use App\Database\SQLiteGlucoseRepository;
use App\DTO\GlucoseReadingDTO;
use App\Export\DashboardSnapshot;
use App\Tests\Support\ConfigFactory;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class DashboardSnapshotTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    public function testWritesCurrentHistoryAndStatusWithoutSecrets(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01T20:00:00Z'));

        $directory = sys_get_temp_dir() . '/mylibre-snapshot-' . uniqid('', true);
        mkdir($directory);

        $repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
        $repository->save(new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
            'source' => 'librelinkup',
        ]));

        $snapshot = new DashboardSnapshot(
            $repository,
            ConfigFactory::make(provider: 'mock'),
            $directory,
        );
        $snapshot->write();

        $current = json_decode((string) file_get_contents($directory . '/current.json'), true);
        $history = json_decode((string) file_get_contents($directory . '/history-20260901.json'), true);
        $status = json_decode((string) file_get_contents($directory . '/status.json'), true);

        $this->assertSame(174, $current['glucoseMgDl']);
        $this->assertSame('2026-09-01T19:31:00Z', $current['timestamp']);
        $this->assertArrayNotHasKey('ageSeconds', $current);
        $this->assertArrayNotHasKey('token', $current);
        $this->assertCount(1, $history['readings']);
        $this->assertFileDoesNotExist($directory . '/history.json');
        $this->assertTrue($status['ok']);
        $this->assertSame('mock', $status['provider']);
        $this->assertSame('2026-09-01T19:31:00Z', $status['latestReadingAt']);
        $this->assertSame(5, $status['browserPollSeconds']);
        $this->assertArrayNotHasKey('password', $status);
    }

    public function testSplitsHistoryByUtcDayAndRemovesLegacyFile(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02T10:00:00Z'));

        $directory = sys_get_temp_dir() . '/mylibre-snapshot-' . uniqid('', true);
        mkdir($directory);
        file_put_contents($directory . '/history.json', '{"readings":[]}');

        $repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
        $repository->save(new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T23:50:00Z',
            'glucoseMgDl' => 120,
            'trend' => 'stable',
            'trendArrow' => '→',
            'source' => 'librelinkup',
        ]));
        $repository->save(new GlucoseReadingDTO([
            'timestamp' => '2026-09-02T00:10:00Z',
            'glucoseMgDl' => 125,
            'trend' => 'rising',
            'trendArrow' => '↗',
            'source' => 'librelinkup',
        ]));

        $snapshot = new DashboardSnapshot(
            $repository,
            ConfigFactory::make(provider: 'mock'),
            $directory,
        );
        $snapshot->write();

        $yesterday = json_decode((string) file_get_contents($directory . '/history-20260901.json'), true);
        $today = json_decode((string) file_get_contents($directory . '/history-20260902.json'), true);

        $this->assertCount(1, $yesterday['readings']);
        $this->assertSame('2026-09-01T23:50:00Z', $yesterday['readings'][0]['timestamp']);
        $this->assertCount(1, $today['readings']);
        $this->assertSame('2026-09-02T00:10:00Z', $today['readings'][0]['timestamp']);
        $this->assertFileDoesNotExist($directory . '/history.json');
    }

    public function testEmptyRepositoryWritesNullCurrent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02T10:00:00Z'));

        $directory = sys_get_temp_dir() . '/mylibre-snapshot-' . uniqid('', true);
        mkdir($directory);

        $snapshot = new DashboardSnapshot(
            new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:')),
            ConfigFactory::make(provider: 'mock'),
            $directory,
        );
        $snapshot->write();

        $current = json_decode((string) file_get_contents($directory . '/current.json'), true);
        $history = json_decode((string) file_get_contents($directory . '/history-20260902.json'), true);
        $status = json_decode((string) file_get_contents($directory . '/status.json'), true);

        $this->assertNull($current['glucoseMgDl']);
        $this->assertNull($current['timestamp']);
        $this->assertSame([], $history['readings']);
        $this->assertNull($status['latestReadingAt']);
        $this->assertFileDoesNotExist($directory . '/history.json');
    }
}
