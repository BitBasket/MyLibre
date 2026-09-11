<?php

declare(strict_types=1);

namespace App\Poller;

use App\Contract\GlucoseProvider;
use App\Contract\GlucoseRepository;
use App\Export\DashboardSnapshot;
use App\LibreLink\LibreLinkAuthException;
use App\LibreLink\LibreLinkNetworkException;
use App\LibreLink\LibreLinkRateLimitException;
use App\LibreLink\LibreLinkResponseException;
use App\Support\Logger;
use Carbon\Carbon;
use Throwable;

final class GlucosePoller
{
    private const SENSOR_FRESHNESS_SECONDS = 180;
    private bool $sensorLostObserved = false;

    public function __construct(
        private readonly GlucoseProvider $provider,
        private readonly GlucoseRepository $repository,
        private readonly Logger $logger,
        private readonly int $intervalSeconds,
        private readonly bool $persistHistory = true,
        private readonly ?DashboardSnapshot $snapshot = null,
    ) {
    }

    public function run(bool $once = false): void
    {
        $this->export();
        $delay = $this->intervalSeconds;

        while (true) {
            $delay = $this->poll($delay);
            if ($once) {
                return;
            }
            sleep($delay);
        }
    }

    public function poll(?int $currentDelay = null): int
    {
        $delay = $currentDelay ?? $this->intervalSeconds;

        try {
            // Capture the repository watermark before fetching: this is the
            // reference point for detecting a recovery after a missed interval.
            $previousLatest = $this->repository->latest();
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

            $inserted = 0;
            $insertedByTimestamp = [];
            foreach ($readings as $reading) {
                if ($this->repository->save($reading)) {
                    $inserted++;
                    $insertedByTimestamp[$reading->timestamp->toIso8601String()] = true;
                }
            }

            $latest = $this->repository->latest();
            $fetchedAgeSeconds = null;
            $fetchedIsStale = false;
            if ($fetchedLatest !== null) {
                $fetchedAgeSeconds = (int) max(0, $fetchedLatest->timestamp->diffInSeconds(Carbon::now()));
                $fetchedIsStale = $fetchedAgeSeconds > self::SENSOR_FRESHNESS_SECONDS;
                if ($fetchedIsStale) {
                    $this->sensorLostObserved = true;
                    $this->logger->error(sprintf(
                        'SENSOR LOST: latest reading timestamp %s is %s old',
                        $fetchedLatest->timestamp->toIso8601String(),
                        $this->formatAge($fetchedAgeSeconds),
                    ));
                } elseif ($this->sensorLostObserved) {
                    $this->logger->info('SENSOR RESTORED: latest reading is fresh');
                    $this->sensorLostObserved = false;
                }
            }

            if ($previousLatest !== null && $fetchedLatest !== null
                && $fetchedLatest->timestamp->greaterThan($previousLatest->timestamp)) {
                $gapSeconds = (int) $previousLatest->timestamp->diffInSeconds($fetchedLatest->timestamp);
                if ($gapSeconds > self::SENSOR_FRESHNESS_SECONDS) {
                    $suppliedIntermediate = [];
                    $savedIntermediate = [];
                    foreach ($readings as $reading) {
                        if ($reading->timestamp->greaterThan($previousLatest->timestamp)
                            && $reading->timestamp->lessThan($fetchedLatest->timestamp)) {
                            $key = $reading->timestamp->toIso8601String();
                            $suppliedIntermediate[$key] = true;
                            if (isset($insertedByTimestamp[$key])) {
                                $savedIntermediate[$key] = true;
                            }
                        }
                    }
                    $from = $previousLatest->timestamp->toIso8601String();
                    $to = $fetchedLatest->timestamp->toIso8601String();
                    $expectedIntermediate = intdiv(max(0, $gapSeconds - 1), max(1, $this->intervalSeconds));
                    $suppliedCount = count($suppliedIntermediate);
                    $savedCount = count($savedIntermediate);
                    $missingCount = max(0, $expectedIntermediate - $suppliedCount);
                    $this->logger->info(sprintf(
                        'READING GAP from %s to %s (%d seconds): expected %d intermediate readings, supplied %d, newly saved %d, missing %d',
                        $from,
                        $to,
                        $gapSeconds,
                        $expectedIntermediate,
                        $suppliedCount,
                        $savedCount,
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
            }

            if ($latest !== null && !$fetchedIsStale) {
                $this->logger->info('Stored glucose reading ' . $latest->glucoseMgDl . ' mg/dL');
            }
            if ($inserted > 1) {
                $this->logger->info('Saved ' . $inserted . ' new glucose readings');
            }
            $this->export();

            return $this->intervalSeconds;
        } catch (LibreLinkAuthException $e) {
            $this->logger->error($e->getMessage());
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

    private function export(): void
    {
        if ($this->snapshot === null) {
            return;
        }

        try {
            $this->snapshot->write();
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
