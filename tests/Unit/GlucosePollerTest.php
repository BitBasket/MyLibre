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
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class GlucosePollerTest extends TestCase
{
    public function testStaleThenFreshResponseReportsSensorRestored(): void
    {
        Carbon::setTestNow('2026-09-04T09:10:00Z');
        try {
            $stale = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:02:00Z', 'glucoseMgDl' => 150, 'trend' => null, 'trendArrow' => null]);
            $fresh = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:10:00Z', 'glucoseMgDl' => 151, 'trend' => null, 'trendArrow' => null]);
            $provider = new class($stale, $fresh) implements GlucoseProvider {
                private int $calls = 0;
                public function __construct(private GlucoseReadingDTO $stale, private GlucoseReadingDTO $fresh) {}
                public function authenticate(): LibreLinkUpSessionDTO { return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']); }
                public function getCurrentReading(): GlucoseReadingDTO { return $this->calls++ === 0 ? $this->stale : $this->fresh; }
                public function getHistory(): array { return [$this->getCurrentReading()]; }
            };
            $stream = fopen('php://memory', 'w+b');
            $poller = new GlucosePoller($provider, new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:')), new Logger($stream), 60, false);
            $poller->poll();
            $poller->poll();
            rewind($stream);
            $log = stream_get_contents($stream);
            $this->assertStringContainsString('SENSOR LOST', $log);
            $this->assertStringContainsString('SENSOR RESTORED', $log);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testDuplicateIntermediateTimestampsCountOnce(): void
    {
        Carbon::setTestNow('2026-09-04T09:03:00Z');
        try {
            $old = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:41:00Z', 'glucoseMgDl' => 140, 'trend' => null, 'trendArrow' => null]);
            $mid = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:56:00Z', 'glucoseMgDl' => 145, 'trend' => null, 'trendArrow' => null]);
            $new = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:02:00Z', 'glucoseMgDl' => 150, 'trend' => null, 'trendArrow' => null]);
            $provider = new class($mid, $new) implements GlucoseProvider {
                public function __construct(private GlucoseReadingDTO $mid, private GlucoseReadingDTO $new) {}
                public function authenticate(): LibreLinkUpSessionDTO { return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']); }
                public function getCurrentReading(): GlucoseReadingDTO { return $this->new; }
                public function getHistory(): array { return [$this->mid, $this->mid, $this->new]; }
            };
            $repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
            $repository->save($old);
            $stream = fopen('php://memory', 'w+b');
            (new GlucosePoller($provider, $repository, new Logger($stream), 60))->poll();
            rewind($stream);
            $this->assertStringContainsString('expected 20 intermediate readings, supplied 1, newly saved 1, missing 19', stream_get_contents($stream));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testLongGapReportsExpectedAndMissingBackfill(): void
    {
        Carbon::setTestNow('2026-09-04T08:39:51Z');
        try {
            $old = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:00:00Z', 'glucoseMgDl' => 140, 'trend' => null, 'trendArrow' => null]);
            $midOne = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:10:00Z', 'glucoseMgDl' => 145, 'trend' => null, 'trendArrow' => null]);
            $midTwo = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:20:00Z', 'glucoseMgDl' => 146, 'trend' => null, 'trendArrow' => null]);
            $new = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:39:51Z', 'glucoseMgDl' => 150, 'trend' => null, 'trendArrow' => null]);
            $provider = new class($midOne, $midTwo, $new) implements GlucoseProvider {
                public function __construct(private GlucoseReadingDTO $midOne, private GlucoseReadingDTO $midTwo, private GlucoseReadingDTO $new) {}
                public function authenticate(): LibreLinkUpSessionDTO { return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']); }
                public function getCurrentReading(): GlucoseReadingDTO { return $this->new; }
                public function getHistory(): array { return [$this->midOne, $this->midTwo, $this->new]; }
            };
            $repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
            $repository->save($old);
            $stream = fopen('php://memory', 'w+b');
            (new GlucosePoller($provider, $repository, new Logger($stream), 60))->poll();
            rewind($stream);
            $log = stream_get_contents($stream);
            $this->assertStringContainsString('expected 39 intermediate readings, supplied 2, newly saved 2, missing 37', $log);
            $this->assertStringContainsString('BACKFILL INCOMPLETE', $log);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testStaleSuccessfulResponseLogsSensorLostAndSuppressesStoredMessage(): void
    {
        Carbon::setTestNow('2026-09-04T09:10:00Z');
        try {
            $reading = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:02:00Z', 'glucoseMgDl' => 150, 'trend' => null, 'trendArrow' => null]);
            $provider = new class($reading) implements GlucoseProvider {
                public function __construct(private GlucoseReadingDTO $reading) {}
                public function authenticate(): LibreLinkUpSessionDTO { return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']); }
                public function getCurrentReading(): GlucoseReadingDTO { return $this->reading; }
                public function getHistory(): array { return [$this->reading]; }
            };
            $stream = fopen('php://memory', 'w+b');
            $repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
            $repository->save(new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:41:00Z', 'glucoseMgDl' => 140, 'trend' => null, 'trendArrow' => null]));
            $poller = new GlucosePoller($provider, $repository, new Logger($stream), 60, true);
            $poller->poll();
            rewind($stream);
            $log = stream_get_contents($stream);
            $this->assertStringContainsString('SENSOR LOST', $log);
            $this->assertStringContainsString('8 minutes 0 seconds old', $log);
            $this->assertStringNotContainsString('Stored glucose reading', $log);
            $this->assertStringNotContainsString('SENSOR RESTORED', $log);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testSensorLostAgeUsesHoursMinutesAndSeconds(): void
    {
        Carbon::setTestNow('2026-09-04T09:10:00Z');
        try {
            $reading = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:08:55Z', 'glucoseMgDl' => 150, 'trend' => null, 'trendArrow' => null]);
            $provider = new class($reading) implements GlucoseProvider {
                public function __construct(private GlucoseReadingDTO $reading) {}
                public function authenticate(): LibreLinkUpSessionDTO { return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']); }
                public function getCurrentReading(): GlucoseReadingDTO { return $this->reading; }
                public function getHistory(): array { return [$this->reading]; }
            };
            $stream = fopen('php://memory', 'w+b');
            (new GlucosePoller($provider, new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:')), new Logger($stream), 60, false))->poll();
            rewind($stream);
            $this->assertStringContainsString('1 hour 1 minute 5 seconds old', stream_get_contents($stream));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testRecoveryLogsIntermediateBackfillCount(): void
    {
        Carbon::setTestNow('2026-09-04T09:03:00Z');
        try {
            $old = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:41:00Z', 'glucoseMgDl' => 140, 'trend' => null, 'trendArrow' => null]);
            $mid = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:56:00Z', 'glucoseMgDl' => 145, 'trend' => null, 'trendArrow' => null]);
            $new = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:02:00Z', 'glucoseMgDl' => 150, 'trend' => null, 'trendArrow' => null]);
            $provider = new class($mid, $new) implements GlucoseProvider {
                public function __construct(private GlucoseReadingDTO $mid, private GlucoseReadingDTO $new) {}
                public function authenticate(): LibreLinkUpSessionDTO { return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']); }
                public function getCurrentReading(): GlucoseReadingDTO { return $this->new; }
                public function getHistory(): array { return [$this->mid, $this->new]; }
            };
            $repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
            $repository->save($old);
            $stream = fopen('php://memory', 'w+b');
            (new GlucosePoller($provider, $repository, new Logger($stream), 60))->poll();
            rewind($stream);
            $log = stream_get_contents($stream);
            $this->assertStringContainsString('READING GAP', $log);
            $this->assertStringContainsString('expected 20 intermediate readings, supplied 1, newly saved 1, missing 19', $log);
            $this->assertStringNotContainsString('SENSOR RESTORED', $log);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testRecoveryWithoutIntermediateLogsIncompleteBackfill(): void
    {
        Carbon::setTestNow('2026-09-04T09:03:00Z');
        try {
            $old = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:41:00Z', 'glucoseMgDl' => 140, 'trend' => null, 'trendArrow' => null]);
            $new = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:02:00Z', 'glucoseMgDl' => 150, 'trend' => null, 'trendArrow' => null]);
            $provider = new class($new) implements GlucoseProvider {
                public function __construct(private GlucoseReadingDTO $new) {}
                public function authenticate(): LibreLinkUpSessionDTO { return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']); }
                public function getCurrentReading(): GlucoseReadingDTO { return $this->new; }
                public function getHistory(): array { return [$this->new]; }
            };
            $repository = new SQLiteGlucoseRepository(SQLiteConnection::connect(':memory:'));
            $repository->save($old);
            $stream = fopen('php://memory', 'w+b');
            (new GlucosePoller($provider, $repository, new Logger($stream), 60))->poll();
            rewind($stream);
            $this->assertStringContainsString('BACKFILL INCOMPLETE for gap', stream_get_contents($stream));
        } finally {
            Carbon::setTestNow();
        }
    }

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
