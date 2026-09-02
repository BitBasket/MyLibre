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
use PDOException;
use Throwable;

final class GlucosePoller
{
    public function __construct(
        private readonly GlucoseProvider $provider,
        private readonly GlucoseRepository $repository,
        private readonly Logger $logger,
        private readonly int $intervalSeconds,
        private readonly bool $seedHistoryWhenEmpty = true,
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
            if ($this->seedHistoryWhenEmpty && $this->repository->latest() === null) {
                foreach ($this->provider->getHistory() as $reading) {
                    $this->repository->save($reading);
                }
                $this->logger->info('Seeded glucose history');
            }

            $reading = $this->provider->getCurrentReading();
            $this->repository->save($reading);
            $this->logger->info('Stored glucose reading ' . $reading->glucoseMgDl . ' mg/dL');
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
        } catch (PDOException $e) {
            $this->logger->error('Database insert failed');
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
}
