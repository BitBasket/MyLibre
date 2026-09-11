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
}
