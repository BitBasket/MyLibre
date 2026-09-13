<?php

declare(strict_types=1);

namespace App\Export;

use App\API\ReadingPresenter;
use App\DTO\GlucoseReadingDTO;
use App\Security\PgpCrypto;
use App\Support\Config;
use Carbon\Carbon;
use RuntimeException;

/**
 * Writes the static files the pure-static dashboard reads.
 *
 * History is written as immutable, time-bucketed batches:
 *
 *     b/<bucket>.json.enc      bucket = floor(epochSeconds / $bucketSeconds) * $bucketSeconds
 *
 * The bucket name is derived arithmetically by the browser, so there is no
 * manifest, no listing endpoint, and no request-time backend: a bucket URL that
 * does not exist simply 404s. The writer never reads a value back.
 */
final class BucketWriter
{
    public function __construct(
        private readonly Config $config,
        private readonly string $directory,
        private ?PgpCrypto $crypto = null,
        private readonly string $passphrase = '',
        private readonly int $bucketSeconds = 300,
    ) {
        if ($this->bucketSeconds < 1) {
            throw new RuntimeException('Bucket size must be at least one second.');
        }
    }

    public function useRecipient(?PgpCrypto $crypto): void
    {
        $this->crypto = $crypto;
    }

    public function writeCurrent(?GlucoseReadingDTO $latest): void
    {
        $this->atomicWrite('current', ReadingPresenter::stored($latest));
    }

    public function writeStatus(?int $firstReadingAt, ?int $lastReadingAt, bool $loginRequired = false): void
    {
        $this->atomicWrite('status', [
            'ok' => true,
            'encrypted' => $this->crypto !== null,
            'schemaVersion' => 2,
            'provider' => $this->config->glucoseProvider,
            'bucketSeconds' => $this->bucketSeconds,
            'earliestReadingAt' => $this->iso($firstReadingAt),
            'latestReadingAt' => $this->iso($lastReadingAt),
            'browserPollSeconds' => $this->config->browserPollSeconds,
            'loginRequired' => $loginRequired,
        ]);
    }

    /**
     * Writes a single bucket. Used by the poller (one bucket per poll).
     *
     * @param GlucoseReadingDTO[] $readings
     */
    public function writeBatch(int $bucket, array $readings): void
    {
        if ($readings === []) {
            return;
        }

        $this->atomicWrite('b/' . $bucket, $this->batchPayload($bucket, $readings));
    }

    /**
     * Whether a batch file for this bucket already exists on disk.
     *
     * Used to avoid overwriting a finalised (or previously opened) bucket with
     * a partial late set. Does not read glucose values.
     */
    public function exists(int $bucket): bool
    {
        return is_file($this->path('b/' . $bucket, $this->crypto !== null ? 'json.asc' : 'json'));
    }

    /**
     * Writes many buckets in one encryption pass. Used by the history
     * migration: encrypting one bucket per `gpg` invocation (each with its own
     * homedir, key import and agent shutdown) takes minutes for thousands of
     * buckets, whereas GnuPG's --multifile does them in one call.
     *
     * Plaintext is staged outside $directory so it never lands in the served
     * web root, even if the run is interrupted.
     *
     * @param array<int, list<GlucoseReadingDTO>> $byBucket bucket epoch => readings
     */
    public function writeBatches(array $byBucket): void
    {
        $byBucket = array_filter($byBucket, static fn (array $readings): bool => $readings !== []);
        if ($byBucket === []) {
            return;
        }

        if ($this->crypto === null) {
            foreach ($byBucket as $bucket => $readings) {
                $this->atomicWrite('b/' . $bucket, $this->batchPayload($bucket, $readings));
            }

            return;
        }

        $workspace = sys_get_temp_dir() . '/mylibre-bulk-' . bin2hex(random_bytes(8));
        if (!mkdir($workspace, 0700, true) && !is_dir($workspace)) {
            throw new RuntimeException('Unable to create bulk encryption workspace.');
        }

        try {
            $paths = [];
            foreach ($byBucket as $bucket => $readings) {
                $path = $workspace . '/' . $bucket . '.json';
                $json = json_encode(
                    $this->batchPayload($bucket, $readings),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
                if (file_put_contents($path, $json, LOCK_EX) === false) {
                    throw new RuntimeException('Unable to stage batch ' . $bucket);
                }
                $paths[$bucket] = $path;
            }

            $this->crypto->encryptFiles(array_values($paths));

            foreach ($paths as $bucket => $path) {
                $armored = $path . '.asc';
                if (!is_file($armored)) {
                    throw new RuntimeException('Bulk encryption did not produce a payload for ' . $bucket);
                }
                $this->publish($armored, $this->path('b/' . $bucket, 'json.asc'));
            }
        } finally {
            foreach (glob($workspace . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($workspace);
        }
    }

    /**
     * @param GlucoseReadingDTO[] $readings
     * @return array<string, mixed>
     */
    private function batchPayload(int $bucket, array $readings): array
    {
        return [
            'schemaVersion' => 1,
            'bucket' => $bucket,
            'readings' => ReadingPresenter::history($readings),
        ];
    }

    private function publish(string $source, string $destination): void
    {
        $this->ensureDirectory(dirname($destination));

        // The workspace may be on a different filesystem than the web root.
        if (!@rename($source, $destination)) {
            if (@copy($source, $destination) === false) {
                throw new RuntimeException('Unable to publish ' . $destination);
            }
            @unlink($source);
        }
        chmod($destination, 0644);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function atomicWrite(string $relativePath, array $payload): void
    {
        $path = $this->path($relativePath, $this->crypto !== null ? 'json.asc' : 'json');
        $this->ensureDirectory(dirname($path));

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->crypto !== null) {
            if ($this->crypto->hasPrivateKey() && $this->passphrase === '') {
                throw new RuntimeException('PGP unlock is required before writing dashboard snapshots.');
            }
            $json = $this->crypto->encrypt($json, $this->passphrase);
        }

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write snapshot: ' . $relativePath);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to publish snapshot: ' . $relativePath);
        }

        // Ciphertext may be served by nginx running as another user.
        chmod($path, $this->crypto !== null ? 0644 : 0600);
    }

    private function path(string $relativePath, string $extension): string
    {
        return $this->directory . '/' . $relativePath . '.' . $extension;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create snapshot directory: ' . $dir);
        }
    }

    private function iso(?int $epoch): ?string
    {
        return $epoch === null ? null : Carbon::createFromTimestamp($epoch, 'UTC')->format('Y-m-d\TH:i:s\Z');
    }
}
