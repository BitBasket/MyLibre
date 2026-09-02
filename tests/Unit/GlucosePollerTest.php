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
}
