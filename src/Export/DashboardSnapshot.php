<?php

declare(strict_types=1);

namespace App\Export;

use App\API\ReadingPresenter;
use App\Contract\GlucoseRepository;
use App\Support\Config;
use Carbon\Carbon;
use RuntimeException;

final class DashboardSnapshot
{
    public function __construct(
        private readonly GlucoseRepository $repository,
        private readonly Config $config,
        private readonly string $directory,
    ) {
    }

    public function write(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create dashboard snapshot directory.');
        }

        $latest = $this->repository->latest();
        $now = Carbon::now('UTC');

        $this->atomicWrite('current.json', ReadingPresenter::stored($latest));
        $this->writeDailyHistory($now);
        $this->removeLegacyHistory();
        $this->atomicWrite('status.json', [
            'ok' => true,
            'provider' => $this->config->glucoseProvider,
            'latestReadingAt' => $latest !== null ? ReadingPresenter::iso($latest->timestamp) : null,
            'browserPollSeconds' => $this->config->browserPollSeconds,
        ]);
    }

    private function writeDailyHistory(Carbon $now): void
    {
        $from = $now->copy()->startOfDay()->subDay();
        $history = $this->repository->since($from);
        $byDay = [];

        foreach ($history as $reading) {
            $day = $reading->timestamp->copy()->utc()->format('Ymd');
            $byDay[$day][] = $reading;
        }

        $today = $now->format('Ymd');
        if (!isset($byDay[$today])) {
            $byDay[$today] = [];
        }

        foreach ($byDay as $day => $readings) {
            $this->atomicWrite('history-' . $day . '.json', [
                'readings' => ReadingPresenter::history($readings),
            ]);
        }
    }

    private function removeLegacyHistory(): void
    {
        $legacy = $this->directory . '/history.json';
        if (is_file($legacy) && !unlink($legacy)) {
            throw new RuntimeException('Unable to remove legacy history.json snapshot.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function atomicWrite(string $filename, array $payload): void
    {
        $path = $this->directory . '/' . $filename;
        $tmp = $path . '.tmp';
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write dashboard snapshot: ' . $filename);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to publish dashboard snapshot: ' . $filename);
        }

        chmod($path, 0644);
    }
}
