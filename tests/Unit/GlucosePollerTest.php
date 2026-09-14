<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Contract\GlucoseProvider;
use App\Contract\LibreLinkAuthenticator;
use App\DTO\GlucoseReadingDTO;
use App\DTO\LibreLinkUpSessionDTO;
use App\Export\BucketWriter;
use App\Export\CsvHistoryStore;
use App\Export\DenseHistoryCsv;
use App\LibreLink\LibreLinkAuthException;
use App\LibreLink\LibreLinkNetworkException;
use App\LibreLink\LibreLinkRateLimitException;
use App\Poller\GlucosePoller;
use App\Poller\PollState;
use App\Poller\PollStateStore;
use App\Support\Logger;
use App\Tests\Support\ConfigFactory;
use App\Tests\Support\PgpKeyFactory;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class GlucosePollerTest extends TestCase
{
    private const PASSPHRASE = 'test-passphrase';

    private string $directory;

    /** @var array{crypto: \App\Security\PgpCrypto, recipient: \App\Security\PgpCrypto} */
    private array $keys;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mylibre-poller-' . uniqid('', true);
        mkdir($this->directory, 0700, true);
        $this->keys = PgpKeyFactory::shared(self::PASSPHRASE);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/b/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory . '/b');
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        Carbon::setTestNow();
    }

    private function poller(
        GlucoseProvider $provider,
        $stream,
        ?PollStateStore $state = null,
        bool $persistHistory = true,
        ?CsvHistoryStore $csv = null,
    ): GlucosePoller {
        return new GlucosePoller(
            $provider,
            $state ?? new PollStateStore($this->directory . '/state.json'),
            new BucketWriter(ConfigFactory::make(provider: 'mock'), $this->directory, $this->keys['recipient']),
            new Logger($stream),
            60,
            $persistHistory,
            csv: $csv,
        );
    }

    private function seedWatermark(string $iso): PollStateStore
    {
        $state = new PollStateStore($this->directory . '/state.json');
        $state->save(new PollState([Carbon::parse($iso, 'UTC')->getTimestamp()]));

        return $state;
    }

    /**
     * Every published payload is encrypted to the recipient, so tests decrypt
     * first. Ciphertext is recipient-only (unsigned), matching production.
     *
     * @return array<string, mixed>
     */
    private function decryptJson(string $relativePath): array
    {
        $path = $this->directory . '/' . $relativePath;
        $this->assertFileExists($path, $path . ' should have been published');

        return json_decode(
            $this->keys['crypto']->decrypt((string) file_get_contents($path), self::PASSPHRASE, false),
            true,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedReadings(): array
    {
        $readings = [];
        foreach (glob($this->directory . '/b/*.json.asc') ?: [] as $file) {
            $payload = json_decode(
                $this->keys['crypto']->decrypt((string) file_get_contents($file), self::PASSPHRASE, false),
                true,
            );
            foreach ($payload['readings'] ?? [] as $reading) {
                $readings[$reading['timestamp']] = $reading;
            }
        }
        ksort($readings);

        return array_values($readings);
    }

    public function testWaitReturningTruePollsAgainWhenNotOnce(): void
    {
        $calls = 0;
        $provider = new class implements GlucoseProvider {
            public int $historyCalls = 0;

            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO([
                    'token' => 't',
                    'baseUri' => 'https://api.libreview.io/',
                ]);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                return new GlucoseReadingDTO([
                    'timestamp' => Carbon::now('UTC'),
                    'glucoseMgDl' => 100,
                    'trend' => 'stable',
                    'trendArrow' => '→',
                    'source' => 'mock',
                ]);
            }

            public function getHistory(): array
            {
                $this->historyCalls++;

                return [$this->getCurrentReading()];
            }
        };

        $poller = $this->poller($provider, fopen('php://memory', 'ab'));
        try {
            $poller->run(false, function () use (&$calls): bool {
                $calls++;
                if ($calls >= 2) {
                    throw new \RuntimeException('stop');
                }

                return true;
            });
            $this->fail('expected stop');
        } catch (\RuntimeException $e) {
            $this->assertSame('stop', $e->getMessage());
        }
        $this->assertSame(2, $calls);
        $this->assertSame(2, $provider->historyCalls);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bucketReadings(int $bucket): array
    {
        $path = $this->directory . '/b/' . $bucket . '.json.asc';
        if (!is_file($path)) {
            return [];
        }
        $payload = json_decode(
            $this->keys['crypto']->decrypt((string) file_get_contents($path), self::PASSPHRASE, false),
            true,
        );

        return $payload['readings'] ?? [];
    }

    private function bucketOfIso(string $iso): int
    {
        $ts = Carbon::parse($iso, 'UTC')->getTimestamp();

        return intdiv($ts, 300) * 300;
    }

    private function log($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private function replayProvider(array $readings, ?GlucoseReadingDTO $current = null): GlucoseProvider
    {
        return new class($readings, $current) implements GlucoseProvider {
            public function __construct(private array $readings, private ?GlucoseReadingDTO $current)
            {
            }

            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                return $this->current ?? $this->readings[array_key_last($this->readings)];
            }

            public function getHistory(): array
            {
                return $this->readings;
            }
        };
    }

    public function testStaleThenFreshResponseReportsSensorRestored(): void
    {
        $stale = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:02:00Z', 'glucoseMgDl' => 150, 'trend' => null, 'trendArrow' => null]);
        $fresh = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:10:00Z', 'glucoseMgDl' => 151, 'trend' => null, 'trendArrow' => null]);
        $stream = fopen('php://memory', 'w+b');
        $provider = new class($stale, $fresh) implements GlucoseProvider {
            private int $calls = 0;

            public function __construct(private GlucoseReadingDTO $stale, private GlucoseReadingDTO $fresh)
            {
            }

            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                return $this->fresh;
            }

            public function getHistory(): array
            {
                return $this->calls++ === 0 ? [$this->stale] : [$this->fresh];
            }
        };

        Carbon::setTestNow('2026-09-04T09:10:00Z');
        $poller = $this->poller($provider, $stream);
        $poller->poll();
        $poller->poll();

        $log = $this->log($stream);
        $this->assertStringContainsString('SENSOR LOST', $log);
        $this->assertStringContainsString('SENSOR RESTORED', $log);
        $this->assertStringContainsString('Stored glucose reading 151 mg/dL', $log);
    }

    public function testDuplicateIntermediateTimestampsCountOnce(): void
    {
        $mid = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:56:00Z', 'glucoseMgDl' => 145, 'trend' => null, 'trendArrow' => null]);
        $new = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:02:00Z', 'glucoseMgDl' => 150, 'trend' => null, 'trendArrow' => null]);
        $provider = $this->replayProvider([$mid, $mid, $new], $new);

        Carbon::setTestNow('2026-09-04T09:03:00Z');
        $stream = fopen('php://memory', 'w+b');
        $this->poller($provider, $stream, $this->seedWatermark('2026-09-04T08:41:00Z'))->poll();

        $this->assertStringContainsString(
            'expected 20 intermediate readings, supplied 1, missing 19',
            $this->log($stream),
        );
    }

    public function testLongGapReportsExpectedAndMissingBackfill(): void
    {
        $midOne = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:10:00Z', 'glucoseMgDl' => 145, 'trend' => null, 'trendArrow' => null]);
        $midTwo = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:20:00Z', 'glucoseMgDl' => 146, 'trend' => null, 'trendArrow' => null]);
        $new = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:39:51Z', 'glucoseMgDl' => 150, 'trend' => null, 'trendArrow' => null]);
        $provider = $this->replayProvider([$midOne, $midTwo, $new], $new);

        Carbon::setTestNow('2026-09-04T08:39:51Z');
        $stream = fopen('php://memory', 'w+b');
        $this->poller($provider, $stream, $this->seedWatermark('2026-09-04T08:00:00Z'))->poll();

        $log = $this->log($stream);
        $this->assertStringContainsString('expected 39 intermediate readings, supplied 2, missing 37', $log);
        $this->assertStringContainsString('BACKFILL INCOMPLETE', $log);
    }

    public function testStaleSuccessfulResponseLogsSensorLostAndSuppressesStoredMessage(): void
    {
        $reading = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:02:00Z', 'glucoseMgDl' => 150, 'trend' => null, 'trendArrow' => null]);
        $stream = fopen('php://memory', 'w+b');

        Carbon::setTestNow('2026-09-04T09:10:00Z');
        $this->poller($this->replayProvider([$reading], $reading), $stream)->poll();

        $log = $this->log($stream);
        $this->assertStringContainsString('SENSOR LOST', $log);
        $this->assertStringContainsString('8 minutes 0 seconds old', $log);
        $this->assertStringNotContainsString('Stored glucose reading', $log);
        $this->assertStringNotContainsString('SENSOR RESTORED', $log);
    }

    public function testSensorLostAgeUsesHoursMinutesAndSeconds(): void
    {
        $reading = new GlucoseReadingDTO(['timestamp' => '2026-09-04T08:08:55Z', 'glucoseMgDl' => 150, 'trend' => null, 'trendArrow' => null]);
        $stream = fopen('php://memory', 'w+b');

        Carbon::setTestNow('2026-09-04T09:10:00Z');
        $this->poller($this->replayProvider([$reading], $reading), $stream)->poll();

        $this->assertStringContainsString('1 hour 1 minute 5 seconds old', $this->log($stream));
    }

    public function testRecoveryWithoutIntermediateLogsIncompleteBackfill(): void
    {
        $new = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:02:00Z', 'glucoseMgDl' => 150, 'trend' => null, 'trendArrow' => null]);
        $stream = fopen('php://memory', 'w+b');

        Carbon::setTestNow('2026-09-04T09:03:00Z');
        $this->poller($this->replayProvider([$new], $new), $stream, $this->seedWatermark('2026-09-04T08:41:00Z'))->poll();

        $this->assertStringContainsString('BACKFILL INCOMPLETE for gap', $this->log($stream));
    }

    public function testLiveOneMinutePollsDoNotReportAGapAgainstUnfinalisedPending(): void
    {
        $first = new GlucoseReadingDTO(['timestamp' => '2026-09-13T12:41:25Z', 'glucoseMgDl' => 177, 'trend' => null, 'trendArrow' => null]);
        $second = new GlucoseReadingDTO(['timestamp' => '2026-09-13T12:42:24Z', 'glucoseMgDl' => 176, 'trend' => null, 'trendArrow' => null]);
        $provider = new class($first, $second) implements GlucoseProvider {
            private int $calls = 0;

            public function __construct(private GlucoseReadingDTO $first, private GlucoseReadingDTO $second)
            {
            }

            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                return $this->calls === 0 ? $this->first : $this->second;
            }

            public function getHistory(): array
            {
                return [$this->calls++ === 0 ? $this->first : $this->second];
            }
        };

        $stream = fopen('php://memory', 'w+b');
        $poller = $this->poller($provider, $stream, $this->seedWatermark('2026-09-13T12:39:23Z'));

        Carbon::setTestNow('2026-09-13T12:41:30Z');
        $this->assertSame(60, $poller->poll());
        Carbon::setTestNow('2026-09-13T12:42:32Z');
        $this->assertSame(60, $poller->poll());

        $log = $this->log($stream);
        $this->assertStringNotContainsString('READING GAP', $log);
        $this->assertStringNotContainsString('BACKFILL INCOMPLETE', $log);
        $this->assertCount(2, $this->storedReadings());
    }

    public function testUnchangedCurrentRetriesBeforeTheNextMinuteIsMissed(): void
    {
        $reading = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:10:00Z', 'glucoseMgDl' => 174, 'trend' => null, 'trendArrow' => null]);
        $poller = $this->poller($this->replayProvider([$reading], $reading), fopen('php://memory', 'w+b'));

        Carbon::setTestNow('2026-09-04T09:10:00Z');
        $this->assertSame(60, $poller->poll());
        Carbon::setTestNow('2026-09-04T09:11:10Z');
        $this->assertSame(15, $poller->poll());
    }

    public function testStatusLatestIncludesOpenBucketPending(): void
    {
        $reading = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:10:00Z', 'glucoseMgDl' => 174, 'trend' => null, 'trendArrow' => null]);

        Carbon::setTestNow('2026-09-04T09:10:00Z');
        $this->poller($this->replayProvider([$reading], $reading), fopen('php://memory', 'ab'))->poll();

        $status = $this->decryptJson('status.json.asc');
        $this->assertSame('2026-09-04T09:10:00Z', $status['latestReadingAt']);
    }

    public function testSuccessfulPollLogsGlucoseMgDl(): void
    {
        $reading = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:10:00Z', 'glucoseMgDl' => 174, 'trend' => 'falling', 'trendArrow' => '↘']);
        $stream = fopen('php://memory', 'w+b');

        Carbon::setTestNow('2026-09-04T09:10:00Z');
        $this->poller($this->replayProvider([$reading], $reading), $stream)->poll();

        $this->assertStringContainsString('Stored glucose reading 174 mg/dL', $this->log($stream));
    }

    public function testNewReadingsAreMergedIntoTheImportableCsv(): void
    {
        $first = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:10:00Z', 'glucoseMgDl' => 174, 'trend' => null, 'trendArrow' => null]);
        $second = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:11:00Z', 'glucoseMgDl' => 175, 'trend' => null, 'trendArrow' => null]);
        $provider = new class($first, $second) implements GlucoseProvider {
            private int $calls = 0;

            public function __construct(private GlucoseReadingDTO $first, private GlucoseReadingDTO $second)
            {
            }

            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                return $this->second;
            }

            public function getHistory(): array
            {
                return $this->calls++ === 0 ? [$this->first] : [$this->first, $this->second];
            }
        };

        $csvPath = $this->directory . '/mylibre.history.csv';
        Carbon::setTestNow('2026-09-04T09:10:00Z');
        $poller = $this->poller($provider, fopen('php://memory', 'ab'), csv: new CsvHistoryStore($csvPath));
        $poller->poll();
        Carbon::setTestNow('2026-09-04T09:11:00Z');
        $poller->poll();

        $decoded = DenseHistoryCsv::decode((string) file_get_contents($csvPath));
        $this->assertCount(2, $decoded);
        $this->assertSame(174, $decoded[0]->glucoseMgDl);
        $this->assertSame(175, $decoded[1]->glucoseMgDl);
        $this->assertSame('20260904', explode(',', (string) file_get_contents($csvPath))[0]);
        $this->assertCount(1 + DenseHistoryCsv::SLOTS, explode(',', rtrim((string) file_get_contents($csvPath), "\n")));
    }

    public function testNewReadingsAreEmittedOnceIntoTheOpenBucket(): void
    {
        $reading = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:10:00Z', 'glucoseMgDl' => 174, 'trend' => 'falling', 'trendArrow' => '↘']);
        $stream = fopen('php://memory', 'w+b');

        Carbon::setTestNow('2026-09-04T09:10:00Z');
        $poller = $this->poller($this->replayProvider([$reading], $reading), $stream);
        $this->assertSame(60, $poller->poll());
        $this->assertSame(60, $poller->poll());

        $stored = $this->storedReadings();
        $this->assertCount(1, $stored);
        $this->assertSame(174, $stored[0]['glucoseMgDl']);
        $this->assertSame('2026-09-04T09:10:00Z', $stored[0]['timestamp']);
    }

    public function testOpenBucketIsFinalisedWhenTheClockLeavesIt(): void
    {
        $first = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:10:00Z', 'glucoseMgDl' => 174, 'trend' => null, 'trendArrow' => null]);
        $second = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:16:00Z', 'glucoseMgDl' => 180, 'trend' => null, 'trendArrow' => null]);
        $provider = new class($first, $second) implements GlucoseProvider {
            private int $calls = 0;

            public function __construct(private GlucoseReadingDTO $first, private GlucoseReadingDTO $second)
            {
            }

            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                return $this->second;
            }

            public function getHistory(): array
            {
                return $this->calls++ === 0 ? [$this->first] : [$this->first, $this->second];
            }
        };

        $state = new PollStateStore($this->directory . '/state.json');
        $poller = $this->poller($provider, fopen('php://memory', 'w+b'), $state);

        Carbon::setTestNow('2026-09-04T09:12:00Z');
        $poller->poll();
        $this->assertSame([], $state->load()->seen);

        Carbon::setTestNow('2026-09-04T09:16:00Z');
        $poller->poll();

        $loaded = $state->load();
        $this->assertContains(Carbon::parse('2026-09-04T09:10:00Z')->getTimestamp(), $loaded->seen);
        $this->assertNotContains(Carbon::parse('2026-09-04T09:16:00Z')->getTimestamp(), $loaded->seen);
        $this->assertSame(['2026-09-04T09:10:00Z'], array_column($this->bucketReadings($this->bucketOfIso('2026-09-04T09:10:00Z')), 'timestamp'));
        $this->assertSame(['2026-09-04T09:16:00Z'], array_column($this->bucketReadings($this->bucketOfIso('2026-09-04T09:16:00Z')), 'timestamp'));
        $this->assertCount(2, $this->storedReadings());
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

        $poller = $this->poller($provider, fopen('php://memory', 'ab'), null, false);
        $this->assertSame(60, $poller->poll(30));
        $this->assertSame(120, $poller->poll(60));
        $status = $this->decryptJson('status.json.asc');
        $this->assertTrue($status['loginRequired']);
    }

    public function testOnceStopsWhenInteractiveLoginIsRequired(): void
    {
        $provider = new class implements GlucoseProvider, LibreLinkAuthenticator {
            public int $historyCalls = 0;

            public function login(string $email, string $password, ?string $patientId = null): LibreLinkUpSessionDTO
            {
                throw new LibreLinkAuthException('LibreLinkUp login required');
            }

            public function hasSession(): bool
            {
                return false;
            }

            public function needsInteractiveLogin(): bool
            {
                return true;
            }

            public function authenticate(): LibreLinkUpSessionDTO
            {
                throw new LibreLinkAuthException('LibreLinkUp login required');
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                throw new LibreLinkAuthException('LibreLinkUp login required');
            }

            public function getHistory(): array
            {
                $this->historyCalls++;

                throw new LibreLinkAuthException('LibreLinkUp login required');
            }
        };

        $this->poller($provider, fopen('php://memory', 'ab'), null, false)->run(once: true);

        $this->assertSame(0, $provider->historyCalls);
        $status = $this->decryptJson('status.json.asc');
        $this->assertTrue($status['loginRequired']);
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

        $poller = $this->poller($provider, fopen('php://memory', 'ab'), null, false);
        $this->assertSame(300, $poller->poll(60));
    }

    public function testSuccessfulPollWritesCurrentSnapshot(): void
    {
        $reading = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:10:00Z', 'glucoseMgDl' => 174, 'trend' => 'falling', 'trendArrow' => '↘']);
        $provider = new class($reading) implements GlucoseProvider {
            public function __construct(private GlucoseReadingDTO $reading)
            {
            }

            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']);
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

        Carbon::setTestNow('2026-09-04T09:10:00Z');
        $this->poller($provider, fopen('php://memory', 'ab'))->poll();

        $current = $this->decryptJson('current.json.asc');
        $status = $this->decryptJson('status.json.asc');
        $this->assertSame(174, $current['glucoseMgDl']);
        $this->assertSame(300, $status['bucketSeconds']);
        $this->assertFalse($status['loginRequired']);
    }

    public function testClockJumpWritesCatchUpIntoSensorTimeBuckets(): void
    {
        $before = new GlucoseReadingDTO(['timestamp' => '2026-09-12T10:51:00Z', 'glucoseMgDl' => 150, 'trend' => null, 'trendArrow' => null]);
        $midOne = new GlucoseReadingDTO(['timestamp' => '2026-09-12T11:10:29Z', 'glucoseMgDl' => 160, 'trend' => null, 'trendArrow' => null]);
        $midTwo = new GlucoseReadingDTO(['timestamp' => '2026-09-12T11:25:36Z', 'glucoseMgDl' => 162, 'trend' => null, 'trendArrow' => null]);
        $after = new GlucoseReadingDTO(['timestamp' => '2026-09-12T12:10:44Z', 'glucoseMgDl' => 174, 'trend' => null, 'trendArrow' => null]);
        $provider = new class($before, $midOne, $midTwo, $after) implements GlucoseProvider {
            private int $calls = 0;

            public function __construct(
                private GlucoseReadingDTO $before,
                private GlucoseReadingDTO $midOne,
                private GlucoseReadingDTO $midTwo,
                private GlucoseReadingDTO $after,
            ) {
            }

            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                return $this->calls === 0 ? $this->before : $this->after;
            }

            public function getHistory(): array
            {
                if ($this->calls++ === 0) {
                    return [$this->before];
                }

                return [$this->before, $this->midOne, $this->midTwo, $this->after];
            }
        };

        $poller = $this->poller($provider, fopen('php://memory', 'ab'));

        Carbon::setTestNow('2026-09-12T10:52:00Z');
        $poller->poll();

        Carbon::setTestNow('2026-09-12T12:11:00Z');
        $poller->poll();

        $staleTimestamps = array_column($this->bucketReadings($this->bucketOfIso('2026-09-12T10:51:00Z')), 'timestamp');
        $this->assertSame(['2026-09-12T10:51:00Z'], $staleTimestamps);
        $this->assertSame(
            ['2026-09-12T11:10:29Z'],
            array_column($this->bucketReadings($this->bucketOfIso('2026-09-12T11:10:29Z')), 'timestamp'),
        );
        $this->assertSame(
            ['2026-09-12T11:25:36Z'],
            array_column($this->bucketReadings($this->bucketOfIso('2026-09-12T11:25:36Z')), 'timestamp'),
        );
        $this->assertSame(
            ['2026-09-12T12:10:44Z'],
            array_column($this->bucketReadings($this->bucketOfIso('2026-09-12T12:10:44Z')), 'timestamp'),
        );
    }

    public function testLatePointForExistingClosedBucketGoesToOpenBucket(): void
    {
        $first = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:10:00Z', 'glucoseMgDl' => 170, 'trend' => null, 'trendArrow' => null]);
        $late = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:11:00Z', 'glucoseMgDl' => 171, 'trend' => null, 'trendArrow' => null]);
        $second = new GlucoseReadingDTO(['timestamp' => '2026-09-04T09:16:00Z', 'glucoseMgDl' => 180, 'trend' => null, 'trendArrow' => null]);
        $provider = new class($first, $late, $second) implements GlucoseProvider {
            private int $calls = 0;

            public function __construct(
                private GlucoseReadingDTO $first,
                private GlucoseReadingDTO $late,
                private GlucoseReadingDTO $second,
            ) {
            }

            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                return $this->second;
            }

            public function getHistory(): array
            {
                $this->calls++;
                if ($this->calls === 1) {
                    return [$this->first];
                }
                if ($this->calls === 2) {
                    return [$this->first, $this->second];
                }

                return [$this->first, $this->late, $this->second];
            }
        };

        $poller = $this->poller($provider, fopen('php://memory', 'ab'));

        Carbon::setTestNow('2026-09-04T09:12:00Z');
        $poller->poll();
        Carbon::setTestNow('2026-09-04T09:16:00Z');
        $poller->poll();
        Carbon::setTestNow('2026-09-04T09:17:00Z');
        $poller->poll();

        $closed = array_column($this->bucketReadings($this->bucketOfIso('2026-09-04T09:10:00Z')), 'timestamp');
        $this->assertSame(['2026-09-04T09:10:00Z'], $closed);
        $open = array_column($this->bucketReadings($this->bucketOfIso('2026-09-04T09:16:00Z')), 'timestamp');
        $this->assertContains('2026-09-04T09:11:00Z', $open);
        $this->assertContains('2026-09-04T09:16:00Z', $open);
    }

    public function testBackfillsGapFromGraphHistory(): void
    {
        $gap = new GlucoseReadingDTO(['timestamp' => '2026-09-01T19:15:00Z', 'glucoseMgDl' => 160, 'trend' => 'stable', 'trendArrow' => '→']);
        $current = new GlucoseReadingDTO(['timestamp' => '2026-09-01T19:31:00Z', 'glucoseMgDl' => 174, 'trend' => 'falling', 'trendArrow' => '↘']);

        Carbon::setTestNow('2026-09-01T19:32:00Z');
        $this->poller(
            $this->replayProvider([$gap, $current], $current),
            fopen('php://memory', 'ab'),
            $this->seedWatermark('2026-09-01T19:00:00Z'),
        )->poll();

        $stored = $this->storedReadings();
        $this->assertCount(2, $stored);
        $this->assertSame(160, $stored[0]['glucoseMgDl']);
        $this->assertSame('2026-09-01T19:15:00Z', $stored[0]['timestamp']);
        $this->assertSame(174, $stored[1]['glucoseMgDl']);
    }

    public function testTransientOutageBacklogIsEmittedAfterRecovery(): void
    {
        $backlog = new GlucoseReadingDTO(['timestamp' => '2026-09-01T19:15:00Z', 'glucoseMgDl' => 160, 'trend' => null, 'trendArrow' => null]);
        $current = new GlucoseReadingDTO(['timestamp' => '2026-09-01T19:31:00Z', 'glucoseMgDl' => 174, 'trend' => null, 'trendArrow' => null]);
        $provider = new class($backlog, $current) implements GlucoseProvider {
            private int $calls = 0;

            public function __construct(private GlucoseReadingDTO $backlog, private GlucoseReadingDTO $current)
            {
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
                    throw new LibreLinkNetworkException('temporary outage');
                }

                return [$this->backlog, $this->current];
            }
        };

        Carbon::setTestNow('2026-09-01T19:32:00Z');
        $poller = $this->poller($provider, fopen('php://memory', 'ab'));
        $this->assertSame(120, $poller->poll(60));
        $this->assertSame(60, $poller->poll(120));
        // Same samples again: retry soon rather than sleeping another full minute.
        $this->assertSame(15, $poller->poll());

        $stored = $this->storedReadings();
        $this->assertCount(2, $stored);
        $this->assertSame(160, $stored[0]['glucoseMgDl']);
        $this->assertSame(174, $stored[1]['glucoseMgDl']);
    }

    public function testRestartResumesFromPersistedWatermark(): void
    {
        $backlog = new GlucoseReadingDTO(['timestamp' => '2026-09-01T19:15:00Z', 'glucoseMgDl' => 160, 'trend' => null, 'trendArrow' => null]);
        $current = new GlucoseReadingDTO(['timestamp' => '2026-09-01T19:31:00Z', 'glucoseMgDl' => 174, 'trend' => null, 'trendArrow' => null]);
        $failing = new class implements GlucoseProvider {
            public function authenticate(): LibreLinkUpSessionDTO
            {
                return new LibreLinkUpSessionDTO(['token' => 'x', 'baseUri' => 'https://api.libreview.io/']);
            }

            public function getCurrentReading(): GlucoseReadingDTO
            {
                throw new LibreLinkNetworkException('temporary outage');
            }

            public function getHistory(): array
            {
                throw new LibreLinkNetworkException('temporary outage');
            }
        };

        Carbon::setTestNow('2026-09-01T19:32:00Z');
        $state = new PollStateStore($this->directory . '/state.json');
        $first = new GlucosePoller(
            $failing,
            $state,
            new BucketWriter(ConfigFactory::make(provider: 'mock'), $this->directory, $this->keys['recipient']),
            new Logger(fopen('php://memory', 'ab')),
            60,
        );
        $this->assertSame(120, $first->poll(60));

        $restarted = new GlucosePoller(
            $this->replayProvider([$backlog, $current], $current),
            $state,
            new BucketWriter(ConfigFactory::make(provider: 'mock'), $this->directory, $this->keys['recipient']),
            new Logger(fopen('php://memory', 'ab')),
            60,
        );
        $this->assertSame(60, $restarted->poll());

        $stored = $this->storedReadings();
        $this->assertCount(2, $stored);
        $this->assertSame(160, $stored[0]['glucoseMgDl']);
        $this->assertSame(174, $stored[1]['glucoseMgDl']);
    }
}
