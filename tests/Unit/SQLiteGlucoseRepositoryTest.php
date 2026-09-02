<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Database\SQLiteConnection;
use App\Database\SQLiteGlucoseRepository;
use App\DTO\GlucoseReadingDTO;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class SQLiteGlucoseRepositoryTest extends TestCase
{
    private SQLiteGlucoseRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
    }

    public function testInsertLatestHistoryAndDedup(): void
    {
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

        $this->assertTrue($this->repository->save($first));
        $this->assertFalse($this->repository->save($first));
        $this->assertTrue($this->repository->save($second));

        $latest = $this->repository->latest();
        $this->assertNotNull($latest);
        $this->assertSame(176, $latest->glucoseMgDl);
        $this->assertSame('stable', $latest->trend);

        $history = $this->repository->since(Carbon::parse('2026-09-01T19:31:00Z'));
        $this->assertCount(2, $history);
        $this->assertSame(174, $history[0]->glucoseMgDl);
        $this->assertInstanceOf(GlucoseReadingDTO::class, $history[0]);

        $all = $this->repository->all();
        $this->assertCount(2, $all);
        $this->assertSame(174, $all[0]->glucoseMgDl);
        $this->assertSame(176, $all[1]->glucoseMgDl);
    }
}
