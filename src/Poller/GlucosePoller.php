<?php

declare(strict_types=1);

namespace App\Poller;

use App\Contract\GlucoseProvider;
use App\Contract\LibreLinkAuthenticator;
use App\DTO\GlucoseReadingDTO;
use App\Export\BucketWriter;
use App\Export\CsvHistoryStore;
use App\LibreLink\LibreLinkAuthException;
use App\LibreLink\LibreLinkNetworkException;
use App\LibreLink\LibreLinkRateLimitException;
use App\LibreLink\LibreLinkResponseException;
use App\Support\Logger;
use Carbon\Carbon;
use Throwable;

/**
 * Collects readings and writes them out as immutable, time-bucketed batches.
 *
 * The poller is a write-only relay: it never reads glucose values back. Its
 * only persistent state is a {@see PollState} of timestamps, used to emit each
 * reading exactly once.
 */
final class GlucosePoller
{
    private const SENSOR_FRESHNESS_SECONDS = 180;
    private const DEFAULT_BUCKET_SECONDS = 300;
    /** When the latest sample did not advance, retry before the next minute is also missed. */
    private const UNCHANGED_RETRY_SECONDS = 15;

    private bool $sensorLostObserved = false;

    /** @var array<int, GlucoseReadingDTO> readings seen in the open bucket, keyed by epoch seconds */
    private array $pending = [];

    private ?int $openBucket = null;

    public function __construct(
        private readonly GlucoseProvider $provider,
        private readonly PollStateStore $state,
        private readonly BucketWriter $writer,
        private readonly Logger $logger,
        private readonly int $intervalSeconds,
        private readonly bool $persistHistory = true,
        private readonly int $bucketSeconds = self::DEFAULT_BUCKET_SECONDS,
        private readonly ?CsvHistoryStore $csv = null,
    ) {
    }

    /**
     * @param null|callable(int): bool $wait  Sleep $seconds; return true to
     *                                        poll immediately (e.g. a login
     *                                        arrived on the intake socket).
     */
    public function run(bool $once = false, ?callable $wait = null): void
    {
        $this->export(null, $this->state->load(), $this->needsInteractiveLogin());
        $delay = $this->intervalSeconds;
        $wait ??= static function (int $seconds): bool {
            sleep($seconds);

            return false;
        };

        while (true) {
            if ($this->needsInteractiveLogin()) {
                $this->logger->error('LibreLinkUp login required');
                $this->exportLoginRequired();
                if ($once) {
                    return;
                }
                if ($wait($delay)) {
                    $delay = $this->intervalSeconds;
                }

                continue;
            }

            $started = time();
            $delay = $this->poll($delay);
            if ($once) {
                return;
            }
            // Sleep the remainder of the delay so PGP/network work does not
            // stretch a 60s interval into 62s and skip a one-minute sample.
            $sleep = max(0, $delay - (time() - $started));
            if ($wait($sleep)) {
                $delay = $this->intervalSeconds;
            }
        }
    }

    public function poll(?int $currentDelay = null): int
    {
        $delay = $currentDelay ?? $this->intervalSeconds;

        try {
            $state = $this->state->load();
            // Open-bucket samples are not in PollState until the 5-minute
            // window closes. Gap detection must still treat them as known,
            // or every live 1-minute poll looks like a growing hole.
            $watermark = $this->latestCollected($state);

            $readings = $this->persistHistory
                ? $this->provider->getHistory()
                : [$this->provider->getCurrentReading()];

            if ($readings === []) {
                $readings[] = $this->provider->getCurrentReading();
            }

            $fetchedLatest = null;
            foreach ($readings as $reading) {
                if ($fetchedLatest === null || $reading->timestamp->greaterThan($fetchedLatest->timestamp)) {
                    $fetchedLatest = $reading;
                }
            }

            // Collect readings not already emitted. Keyed by timestamp so
            // re-seen readings within a bucket collapse to one.
            $added = 0;
            $fresh = [];
            foreach ($readings as $reading) {
                $ts = $reading->timestamp->getTimestamp();
                if ($state->hasSeen($ts)) {
                    continue;
                }
                if (!isset($this->pending[$ts])) {
                    $this->pending[$ts] = $reading;
                    $fresh[] = $reading;
                    $added++;
                }
            }
            if ($fresh !== []) {
                $this->csv?->merge($fresh);
            }

            $nowBucket = $this->bucketOf(Carbon::now('UTC')->getTimestamp());
            $finalised = $this->flushClosedPending($nowBucket);
            if ($finalised !== []) {
                $state = $state->emitted($finalised);
                $this->state->save($state);
            }

            $this->openBucket = $nowBucket;
            // Keep the (possibly partial) open bucket current for the dashboard.
            $this->writer->writeBatch($this->openBucket, array_values($this->pending));

            $this->reportSensorState($fetchedLatest);
            $this->reportGap($watermark, $readings, $fetchedLatest);

            if ($fetchedLatest !== null) {
                $age = (int) max(0, $fetchedLatest->timestamp->diffInSeconds(Carbon::now()));
                if ($age <= self::SENSOR_FRESHNESS_SECONDS) {
                    $this->logger->info('Stored glucose reading ' . $fetchedLatest->glucoseMgDl . ' mg/dL');
                }
            }
            if ($added > 0) {
                $this->logger->info('Collected ' . $added . ' new glucose readings');
            }

            $this->export($fetchedLatest, $state);

            return $this->nextDelay($fetchedLatest, $added);
        } catch (LibreLinkAuthException $e) {
            $this->logger->error($e->getMessage());
            $this->exportLoginRequired();

            return $this->backoff($delay, 30, 900);
        } catch (LibreLinkRateLimitException $e) {
            $this->logger->error($e->getMessage());

            return max($e->retryAfterSeconds, $this->backoff($delay, 60, 900));
        } catch (LibreLinkNetworkException $e) {
            $this->logger->error($e->getMessage());

            return $this->backoff($delay, 15, 300);
        } catch (LibreLinkResponseException $e) {
            $this->logger->error($e->getMessage());

            return $this->intervalSeconds;
        } catch (Throwable $e) {
            $this->logger->error('Poller failed: ' . $e->getMessage());

            return $this->backoff($delay, 30, 300);
        }
    }

    private function bucketOf(int $epochSeconds): int
    {
        return intdiv($epochSeconds, $this->bucketSeconds) * $this->bucketSeconds;
    }

    /**
     * Newest sensor timestamp this process has already collected, including
     * the still-open bucket that PollState has not finalised yet.
     */
    private function latestCollected(PollState $state): ?int
    {
        $latest = $state->latest();
        if ($this->pending === []) {
            return $latest;
        }

        $pendingLatest = (int) max(array_keys($this->pending));

        return $latest === null ? $pendingLatest : max($latest, $pendingLatest);
    }

    /**
     * LibreLinkUp only returns the latest measurement plus a ~15-minute graph.
     * One-minute history exists only if we capture each current sample. When
     * a poll sees the same timestamp (the next sample is not published yet),
     * wait a short remainder instead of another full interval.
     */
    private function nextDelay(?GlucoseReadingDTO $fetchedLatest, int $added): int
    {
        if ($added > 0 || $fetchedLatest === null) {
            return $this->intervalSeconds;
        }

        $age = (int) max(0, $fetchedLatest->timestamp->diffInSeconds(Carbon::now()));
        if ($age > self::SENSOR_FRESHNESS_SECONDS) {
            return $this->intervalSeconds;
        }

        return max(self::UNCHANGED_RETRY_SECONDS, $this->intervalSeconds - $age);
    }

    /**
     * Writes unseen readings whose sensor time already falls in a closed clock
     * window. A paused process that wakes after a long sleep must not dump the
     * whole graph into the stale open-bucket file: the browser addresses
     * history as `b/<floor(sensorTime / bucketSeconds)>`.
     *
     * Existing closed files are left alone (writeBatch overwrites and cannot
     * merge). Those late points stay in `$pending` and go out with the current
     * open bucket; the dashboard dedupes by timestamp.
     *
     * @return list<int>
     */
    private function flushClosedPending(int $nowBucket): array
    {
        if ($this->openBucket === null) {
            $this->openBucket = $nowBucket;
        }

        $closed = [];
        $keep = [];
        foreach ($this->pending as $ts => $reading) {
            $bucket = $this->bucketOf($ts);
            if ($bucket < $nowBucket) {
                $closed[$bucket][$ts] = $reading;
            } else {
                $keep[$ts] = $reading;
            }
        }

        $emitted = [];
        foreach ($closed as $bucket => $readings) {
            $closingOurOpen = $this->openBucket === $bucket;
            if ($closingOurOpen || !$this->writer->exists($bucket)) {
                $this->writer->writeBatch($bucket, array_values($readings));
                foreach (array_keys($readings) as $ts) {
                    $emitted[] = $ts;
                }
            } else {
                foreach ($readings as $ts => $reading) {
                    $keep[$ts] = $reading;
                }
            }
        }

        $this->pending = $keep;

        return $emitted;
    }

    private function reportSensorState(?GlucoseReadingDTO $fetchedLatest): void
    {
        if ($fetchedLatest === null) {
            return;
        }

        $age = (int) max(0, $fetchedLatest->timestamp->diffInSeconds(Carbon::now()));
        if ($age > self::SENSOR_FRESHNESS_SECONDS) {
            $this->sensorLostObserved = true;
            $this->logger->error(sprintf(
                'SENSOR LOST: latest reading timestamp %s is %s old',
                $fetchedLatest->timestamp->toIso8601String(),
                $this->formatAge($age),
            ));
        } elseif ($this->sensorLostObserved) {
            $this->logger->info('SENSOR RESTORED: latest reading is fresh');
            $this->sensorLostObserved = false;
        }
    }

    /**
     * @param GlucoseReadingDTO[] $readings
     */
    private function reportGap(?int $watermark, array $readings, ?GlucoseReadingDTO $fetchedLatest): void
    {
        if ($watermark === null || $fetchedLatest === null) {
            return;
        }

        $latestTs = $fetchedLatest->timestamp->getTimestamp();
        $gapSeconds = $latestTs - $watermark;
        if ($gapSeconds <= self::SENSOR_FRESHNESS_SECONDS) {
            return;
        }

        $supplied = [];
        foreach ($readings as $reading) {
            $ts = $reading->timestamp->getTimestamp();
            if ($ts > $watermark && $ts < $latestTs) {
                $supplied[$reading->timestamp->toIso8601String()] = true;
            }
        }

        $from = Carbon::createFromTimestamp($watermark, 'UTC')->toIso8601String();
        $to = $fetchedLatest->timestamp->toIso8601String();
        $expectedIntermediate = intdiv(max(0, $gapSeconds - 1), max(1, $this->intervalSeconds));
        $suppliedCount = count($supplied);
        $missingCount = max(0, $expectedIntermediate - $suppliedCount);

        $this->logger->info(sprintf(
            'READING GAP from %s to %s (%d seconds): expected %d intermediate readings, supplied %d, missing %d',
            $from,
            $to,
            $gapSeconds,
            $expectedIntermediate,
            $suppliedCount,
            $missingCount,
        ));

        if ($suppliedCount < $expectedIntermediate) {
            $this->logger->error(sprintf(
                'BACKFILL INCOMPLETE for gap %s to %s: expected %d, supplied %d, missing %d',
                $from,
                $to,
                $expectedIntermediate,
                $suppliedCount,
                $missingCount,
            ));
        }
    }

    private function needsInteractiveLogin(): bool
    {
        return $this->provider instanceof LibreLinkAuthenticator
            && $this->provider->needsInteractiveLogin();
    }

    private function exportLoginRequired(): void
    {
        try {
            $state = $this->state->load();
            $this->writer->writeStatus($state->firstReadingAt, $state->latest(), loginRequired: true);
        } catch (Throwable $e) {
            $this->logger->error('Dashboard snapshot failed: ' . $e->getMessage());
        }
    }

    private function export(?GlucoseReadingDTO $latest, PollState $state, bool $loginRequired = false): void
    {
        try {
            $this->writer->writeCurrent($latest);
            $this->writer->writeStatus($state->firstReadingAt, $this->latestCollected($state), $loginRequired);
        } catch (Throwable $e) {
            $this->logger->error('Dashboard snapshot failed: ' . $e->getMessage());
        }
    }

    private function backoff(int $current, int $floor, int $cap): int
    {
        $next = max($floor, $current * 2);

        return min($cap, $next);
    }

    private function formatAge(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;
        $parts = [];

        if ($hours > 0) {
            $parts[] = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
            $parts[] = $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
        } elseif ($minutes > 0) {
            $parts[] = $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
        }

        $parts[] = $remainingSeconds . ' ' . ($remainingSeconds === 1 ? 'second' : 'seconds');

        return implode(' ', $parts);
    }
}
