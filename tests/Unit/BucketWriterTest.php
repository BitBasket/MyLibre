<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\DTO\GlucoseReadingDTO;
use App\Export\BucketWriter;
use App\Tests\Support\ConfigFactory;
use App\Tests\Support\PgpKeyFactory;
use PHPUnit\Framework\TestCase;

final class BucketWriterTest extends TestCase
{
    private const PASSPHRASE = 'test-passphrase';

    private string $directory;

    /** @var array{crypto: \App\Security\PgpCrypto, recipient: \App\Security\PgpCrypto} */
    private array $keys;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mylibre-bucket-' . uniqid('', true);
        mkdir($this->directory, 0700, true);
        $this->keys = PgpKeyFactory::shared(self::PASSPHRASE);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->directory);
    }

    /**
     * The writer always encrypts to the recipient, so assertions decrypt first.
     *
     * @return array<string, mixed>
     */
    private function decrypt(string $relativePath): array
    {
        $path = $this->directory . '/' . $relativePath;
        $this->assertFileExists($path, $path . ' should have been published');
        $cipher = (string) file_get_contents($path);
        $this->assertStringContainsString('BEGIN PGP MESSAGE', $cipher);

        // Recipient-only payloads are unsigned by design.
        return json_decode($this->keys['crypto']->decrypt($cipher, self::PASSPHRASE, false), true);
    }

    private function writer(): BucketWriter
    {
        return new BucketWriter(ConfigFactory::make(provider: 'mock'), $this->directory, $this->keys['recipient']);
    }

    public function testWritesBatchCurrentAndStatus(): void
    {
        $writer = $this->writer();
        $reading = new GlucoseReadingDTO([
            'timestamp' => '2026-09-04T09:10:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
        ]);

        $writer->writeBatch(1756979400, [$reading]);
        $writer->writeCurrent($reading);
        $writer->writeStatus(1756978800, 1756979400);

        $batch = $this->decrypt('b/1756979400.json.asc');
        $this->assertSame(1, $batch['schemaVersion']);
        $this->assertSame(1756979400, $batch['bucket']);
        $this->assertCount(1, $batch['readings']);
        $this->assertSame(174, $batch['readings'][0]['glucoseMgDl']);
        $this->assertSame('2026-09-04T09:10:00Z', $batch['readings'][0]['timestamp']);

        $current = $this->decrypt('current.json.asc');
        $this->assertSame(174, $current['glucoseMgDl']);

        $status = $this->decrypt('status.json.asc');
        $this->assertSame(2, $status['schemaVersion']);
        $this->assertTrue($status['encrypted']);
        $this->assertSame(300, $status['bucketSeconds']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1756978800), $status['earliestReadingAt']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1756979400), $status['latestReadingAt']);
        $this->assertFalse($status['loginRequired']);

        // Nothing plaintext ever reaches the served directory.
        $this->assertFileDoesNotExist($this->directory . '/b/1756979400.json');
        $this->assertFileDoesNotExist($this->directory . '/current.json');
        $this->assertFileDoesNotExist($this->directory . '/status.json');
    }

    public function testEmptyBatchWritesNothing(): void
    {
        $writer = $this->writer();
        $writer->writeBatch(1756979400, []);

        $this->assertFileDoesNotExist($this->directory . '/b/1756979400.json.asc');
        $this->assertFalse($writer->exists(1756979400));
    }

    public function testExistsReportsWrittenBatches(): void
    {
        $writer = $this->writer();
        $this->assertFalse($writer->exists(1756979400));

        $writer->writeBatch(1756979400, [new GlucoseReadingDTO([
            'timestamp' => '2026-09-04T09:10:00Z',
            'glucoseMgDl' => 174,
            'trend' => null,
            'trendArrow' => null,
        ])]);

        $this->assertTrue($writer->exists(1756979400));
        $this->assertFalse($writer->exists(1756979700));
    }

    public function testWriteBatchesBulkEncryptsEveryBucket(): void
    {
        $crypto = PgpKeyFactory::make($this->directory . '/keys', 'correct horse');
        $writer = new BucketWriter(ConfigFactory::make(provider: 'mock'), $this->directory, $crypto, 'correct horse');
        $reading = static fn (string $iso, int $value): GlucoseReadingDTO => new GlucoseReadingDTO([
            'timestamp' => $iso,
            'glucoseMgDl' => $value,
            'trend' => null,
            'trendArrow' => null,
        ]);

        $writer->writeBatches([
            1756979400 => [$reading('2026-09-04T09:10:00Z', 174)],
            1756979700 => [$reading('2026-09-04T09:15:00Z', 180), $reading('2026-09-04T09:16:00Z', 181)],
        ]);

        foreach ([1756979400 => 1, 1756979700 => 2] as $bucket => $count) {
            $path = $this->directory . '/b/' . $bucket . '.json.asc';
            $this->assertFileExists($path);
            $this->assertFileDoesNotExist($this->directory . '/b/' . $bucket . '.json');

            // Bulk batches are recipient-only (unsigned) by design.
            $payload = json_decode($crypto->decrypt((string) file_get_contents($path), 'correct horse', false), true);
            $this->assertSame($bucket, $payload['bucket']);
            $this->assertCount($count, $payload['readings']);
        }
    }

    public function testEncryptedBatchIsWrittenAsArmoredCiphertext(): void
    {
        $crypto = PgpKeyFactory::make($this->directory . '/keys', 'correct horse');
        $writer = new BucketWriter(ConfigFactory::make(provider: 'mock'), $this->directory, $crypto, 'correct horse');
        $reading = new GlucoseReadingDTO([
            'timestamp' => '2026-09-04T09:10:00Z',
            'glucoseMgDl' => 174,
            'trend' => null,
            'trendArrow' => null,
        ]);

        $writer->writeBatch(1756979400, [$reading]);

        $path = $this->directory . '/b/1756979400.json.asc';
        $this->assertFileExists($path);
        $cipher = (string) file_get_contents($path);
        $this->assertStringContainsString('BEGIN PGP MESSAGE', $cipher);

        $payload = json_decode($crypto->decrypt($cipher, 'correct horse'), true);
        $this->assertSame(1756979400, $payload['bucket']);
        $this->assertSame(174, $payload['readings'][0]['glucoseMgDl']);

        $this->assertFileDoesNotExist($this->directory . '/b/1756979400.json');
    }
}
