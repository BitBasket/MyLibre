<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Database\EncryptedGlucoseRepository;
use App\Database\SQLiteConnection;
use App\Database\SQLiteGlucoseRepository;
use App\Database\SqliteHistoryImporter;
use App\DTO\GlucoseReadingDTO;
use App\Tests\Support\PgpKeyFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SqliteHistoryImporterTest extends TestCase
{
    public function testSnapshotsLiveSqliteAndImportsNewReadingsOnly(): void
    {
        $dir = sys_get_temp_dir() . '/mylibre-sqlite-import-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $sqlitePath = $dir . '/glucose.sqlite';
        $source = new SQLiteGlucoseRepository(SQLiteConnection::connect($sqlitePath));
        $source->save(new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
            'source' => 'librelinkup',
        ]));
        $source->save(new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:32:00Z',
            'glucoseMgDl' => 176,
            'trend' => 'stable',
            'trendArrow' => '→',
            'source' => 'librelinkup',
        ]));

        $crypto = PgpKeyFactory::make($dir);
        $destination = new EncryptedGlucoseRepository($dir . '/glucose.json.asc', $crypto, 'test-passphrase');
        $destination->save(new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
            'source' => 'librelinkup',
        ]));
        $destination->flush();

        $added = SqliteHistoryImporter::importFile($sqlitePath, $destination);

        $this->assertSame(1, $added);
        $this->assertCount(2, $destination->all());
        $this->assertSame(0, SqliteHistoryImporter::importFile($dir, $destination));
        $this->assertFileExists($sqlitePath);
        $this->assertSame(2, count($source->all()));
    }

    public function testResolveRejectsMissingDatabase(): void
    {
        $this->expectException(RuntimeException::class);
        SqliteHistoryImporter::resolve('/tmp/mylibre-missing-' . uniqid('', true) . '.sqlite');
    }
}
