<?php

declare(strict_types=1);

namespace App\Export;

use App\API\ReadingPresenter;
use App\Contract\GlucoseRepository;
use App\DTO\GlucoseReadingDTO;
use App\Support\Config;
use App\Security\PgpCrypto;
use Carbon\Carbon;
use RuntimeException;

final class DashboardSnapshot
{
    public function __construct(
        private readonly GlucoseRepository $repository,
        private readonly Config $config,
        private readonly string $directory,
        private readonly ?PgpCrypto $crypto = null,
        private readonly string $passphrase = '',
    ) {
    }

    public function write(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create dashboard snapshot directory.');
        }

        $history = $this->repository->all();
        $latest = $history === [] ? null : $history[array_key_last($history)];
        $earliest = $history[0] ?? null;
        $now = Carbon::now('UTC');
        [$days, $revisions] = $this->writeDailyHistory($now, $history);

        $this->atomicWrite('current.json.asc', ReadingPresenter::stored($latest));
        $this->atomicWrite('status.json.asc', [
            'ok' => true,
            'provider' => $this->config->glucoseProvider,
            'latestReadingAt' => $latest !== null ? ReadingPresenter::iso($latest->timestamp) : null,
            'earliestReadingAt' => $earliest !== null ? ReadingPresenter::iso($earliest->timestamp) : null,
            'historyDays' => $days,
            'historyRevisions' => $revisions,
            'browserPollSeconds' => $this->config->browserPollSeconds,
        ]);
    }

    /**
     * @param GlucoseReadingDTO[] $history
     * @return array{list<string>, array<string, string>}
     */
    private function writeDailyHistory(Carbon $now, array $history): array
    {
        $byDay = [];

        foreach ($history as $reading) {
            $day = $reading->timestamp->copy()->utc()->format('Ymd');
            $byDay[$day][] = $reading;
        }

        $today = $now->format('Ymd');
        if (!isset($byDay[$today])) {
            $byDay[$today] = [];
        }

        ksort($byDay);

        $revisions = [];
        foreach ($byDay as $day => $readings) {
            $serialized = ReadingPresenter::history($readings);
            $this->atomicWrite('history-' . $day . '.json.asc', [
                'readings' => $serialized,
            ]);
            $revisions[(string) $day] = hash('sha256', json_encode($serialized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return [array_map(static fn (int|string $day): string => (string) $day, array_keys($byDay)), $revisions];
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
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->crypto !== null) {
            if ($this->passphrase === '') throw new RuntimeException('PGP unlock is required before writing dashboard snapshots.');
            $json = $this->crypto->encrypt($json, $this->passphrase);
        }

        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write dashboard snapshot: ' . $filename);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to publish dashboard snapshot: ' . $filename);
        }

        chmod($path, 0600);
    }
}
