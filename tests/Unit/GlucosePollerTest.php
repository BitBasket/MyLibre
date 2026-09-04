<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Contract\GlucoseProvider;
use App\Database\SQLiteConnection;
use App\Database\SQLiteGlucoseRepository;
use App\DTO\GlucoseReadingDTO;
use App\DTO\LibreLinkUpSessionDTO;
use App\Export\DashboardSnapshot;
use App\LibreLink\LibreLinkAuthException;
use App\LibreLink\LibreLinkRateLimitException;
use App\Poller\GlucosePoller;
use App\Support\Logger;
use App\Tests\Support\ConfigFactory;
use PHPUnit\Framework\TestCase;

final class GlucosePollerTest extends TestCase
{
    public function testDuplicateTimestampIsSuccess(): void
    {
        $reading = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
        ]);
        $provider = new class($reading) implements GlucoseProvider {
            public function __construct(private GlucoseReadingDTO $reading)
            {
            }

            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO([
                    'token' => 'x',
                    'baseUri' => 'https://api.libreview.io/',
                ]);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                return $this->reading;
            }

            public function getHistory(): array
            {
                return [$this->reading];
            }
        };

        $repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
        $poller = new GlucosePoller($provider, $repository, new Logger(fopen('php://memory', 'ab')), 60, false);

        $this->assertSame(60, $poller->poll());
        $this->assertSame(60, $poller->poll());
        $this->assertSame(174, $repository->latest()?->glucoseMgDl);
    }

    public function testAuthFailureBacksOff(): void
    {
        $provider = new class implements GlucoseProvider {
            public function authenticate(): LibreLinkUpSessionDTO
            {
                throw new LibreLinkAuthException('LibreLinkUp authentication failed: HTTP 401');
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                throw new LibreLinkAuthException('LibreLinkUp authentication failed: HTTP 401');
            }

            public function getHistory(): array
            {
                throw new LibreLinkAuthException('LibreLinkUp authentication failed: HTTP 401');
            }
        };

        $repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
        $poller = new GlucosePoller($provider, $repository, new Logger(fopen('php://memory', 'ab')), 60, false);

        $this->assertSame(60, $poller->poll(30));
        $this->assertSame(120, $poller->poll(60));
    }

    public function testRateLimitUsesRetryAfter(): void
    {
        $provider = new class implements GlucoseProvider {
            public function authenticate(): LibreLinkUpSessionDTO
            {
                throw new LibreLinkRateLimitException('LibreLinkUp request rate-limited: HTTP 429', 300);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                throw new LibreLinkRateLimitException('LibreLinkUp request rate-limited: HTTP 429', 300);
            }

            public function getHistory(): array
            {
                throw new LibreLinkRateLimitException('LibreLinkUp request rate-limited: HTTP 429', 300);
            }
        };

        $repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
        $poller = new GlucosePoller($provider, $repository, new Logger(fopen('php://memory', 'ab')), 60, false);

        $this->assertSame(300, $poller->poll(60));
    }

    public function testSuccessfulPollWritesDashboardSnapshot(): void
    {
        $reading = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
        ]);
        $provider = new class($reading) implements GlucoseProvider {
            public function __construct(private GlucoseReadingDTO $reading)
            {
            }

            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO([
                    'token' => 'x',
                    'baseUri' => 'https://api.libreview.io/',
                ]);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                return $this->reading;
            }

            public function getHistory(): array
            {
                return [];
            }
        };

        $directory = sys_get_temp_dir() . '/mylibre-poller-snapshot-' . uniqid('', true);
        mkdir($directory);
        $repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
        $snapshot = new DashboardSnapshot($repository, ConfigFactory::make(provider: 'mock'), $directory);
        $poller = new GlucosePoller(
            $provider,
            $repository,
            new Logger(fopen('php://memory', 'ab')),
            60,
            false,
            $snapshot,
        );

        $this->assertSame(60, $poller->poll());
        $current = json_decode((string) file_get_contents($directory . '/current.json'), true);
        $this->assertSame(174, $current['glucoseMgDl']);
    }

    public function testBackfillsGapFromGraphHistory(): void
    {
        $gap = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:15:00Z',
            'glucoseMgDl' => 160,
            'trend' => 'stable',
            'trendArrow' => '→',
        ]);
        $current = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
        ]);
        $provider = new class($gap, $current) implements GlucoseProvider {
            public function __construct(
                private GlucoseReadingDTO $gap,
                private GlucoseReadingDTO $current,
            ) {
            }

            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO([
                    'token' => 'x',
                    'baseUri' => 'https://api.libreview.io/',
                ]);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                return $this->current;
            }

            public function getHistory(): array
            {
                return [$this->gap, $this->current];
            }
        };

        $directory = sys_get_temp_dir() . '/mylibre-poller-backfill-' . uniqid('', true);
        mkdir($directory);
        $repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
        $repository->save(new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:00:00Z',
            'glucoseMgDl' => 150,
            'trend' => 'stable',
            'trendArrow' => '→',
        ]));
        $snapshot = new DashboardSnapshot($repository, ConfigFactory::make(provider: 'mock'), $directory);
        $poller = new GlucosePoller(
            $provider,
            $repository,
            new Logger(fopen('php://memory', 'ab')),
            60,
            true,
            $snapshot,
        );

        $this->assertSame(60, $poller->poll());
        $this->assertSame(174, $repository->latest()?->glucoseMgDl);
        $history = $repository->all();
        $this->assertCount(3, $history);
        $this->assertSame(160, $history[1]->glucoseMgDl);

        $snapshotHistory = json_decode((string) file_get_contents($directory . '/history-20260901.json'), true);
        $this->assertCount(3, $snapshotHistory['readings']);
        $this->assertSame(160, $snapshotHistory['readings'][1]['glucoseMgDl']);
        $this->assertSame('2026-09-01T19:15:00Z', $snapshotHistory['readings'][1]['timestamp']);

        $this->assertSame(60, $poller->poll());
        $this->assertCount(3, $repository->all());
    }

    public function testHistoryRecoveryAfterTransientOutagePersistsBacklogAndDeduplicates(): void
    {
        $backlog = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:15:00Z',
            'glucoseMgDl' => 160,
            'trend' => 'stable',
            'trendArrow' => '→',
        ]);
        $current = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
        ]);
        $provider = new class($backlog, $current) implements GlucoseProvider {
            private int $calls = 0;

            public function __construct(
                private GlucoseReadingDTO $backlog,
                private GlucoseReadingDTO $current,
            ) {
            }

            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                return $this->current;
            }

            public function getHistory(): array
            {
                if ($this->calls++ === 0) {
                    throw new \App\LibreLink\LibreLinkNetworkException('temporary outage');
                }

                return [$this->backlog, $this->current];
            }
        };

        $repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
        $poller = new GlucosePoller($provider, $repository, new Logger(fopen('php://memory', 'ab')), 60);

        $this->assertSame(120, $poller->poll(60));
        $this->assertSame(60, $poller->poll(120));
        $this->assertSame(60, $poller->poll());

        $history = $repository->all();
        $this->assertCount(2, $history);
        $this->assertSame(160, $history[0]->glucoseMgDl);
        $this->assertSame(174, $history[1]->glucoseMgDl);
    }

    public function testHistoryRecoveryAfterRestartUsesExistingRepository(): void
    {
        $backlog = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:15:00Z',
            'glucoseMgDl' => 160,
            'trend' => 'stable',
            'trendArrow' => '→',
        ]);
        $current = new GlucoseReadingDTO([
            'timestamp' => '2026-09-01T19:31:00Z',
            'glucoseMgDl' => 174,
            'trend' => 'falling',
            'trendArrow' => '↘',
        ]);
        $failingProvider = new class implements GlucoseProvider {
            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                throw new \App\LibreLink\LibreLinkNetworkException('temporary outage');
            }

            public function getHistory(): array
            {
                throw new \App\LibreLink\LibreLinkNetworkException('temporary outage');
            }
        };
        $recoveredProvider = new class($backlog, $current) implements GlucoseProvider {
            public function __construct(
                private GlucoseReadingDTO $backlog,
                private GlucoseReadingDTO $current,
            ) {
            }

            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                return $this->current;
            }

            public function getHistory(): array
            {
                return [$this->backlog, $this->current];
            }
        };

        $repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
        $logger = new Logger(fopen('php://memory', 'ab'));
        $firstPoller = new GlucosePoller($failingProvider, $repository, $logger, 60);
        $this->assertSame(120, $firstPoller->poll(60));

        $restartedPoller = new GlucosePoller($recoveredProvider, $repository, $logger, 60);
        $this->assertSame(60, $restartedPoller->poll());
        $this->assertSame(60, $restartedPoller->poll());

        $history = $repository->all();
        $this->assertCount(2, $history);
        $this->assertSame(160, $history[0]->glucoseMgDl);
        $this->assertSame(174, $history[1]->glucoseMgDl);
    }
}
