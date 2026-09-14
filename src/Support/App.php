<?php

declare(strict_types=1);

namespace App\Support;

use App\Contract\GlucoseProvider;
use App\Export\BucketWriter;
use App\Http\AuthIntakeHandler;
use App\Http\AuthIntakeServer;
use App\LibreLink\LibreLinkUpProvider;
use App\LibreLink\SessionStore;
use App\Mock\MockGlucoseProvider;
use App\Poller\PollStateStore;
use App\Security\PgpCrypto;
use InvalidArgumentException;

final class App
{
    private ?GlucoseProvider $provider = null;

    private ?PgpCrypto $crypto = null;

    private ?PgpCrypto $recipient = null;

    private ?PollStateStore $pollState = null;

    private ?BucketWriter $bucketWriter = null;

    public function __construct(
        public readonly Config $config,
        public readonly Logger $logger,
    ) {
    }

    public static function boot(?string $root = null): self
    {
        $root ??= dirname(__DIR__, 2);

        return new self(Config::fromEnv($root), new Logger());
    }

    public function crypto(): PgpCrypto
    {
        if ($this->crypto !== null) {
            return $this->crypto;
        }

        if ($this->config->publicKeyPath === '' || $this->config->privateKeyPath === '') {
            throw new InvalidArgumentException('PGP_PUBLIC_KEY_PATH and PGP_PRIVATE_KEY_PATH are required.');
        }
        if ($this->config->unlockPassphrase === '') {
            throw new InvalidArgumentException('PGP_PASSPHRASE is required. Run php bin/init-pgp.php');
        }
        if (!is_readable($this->config->publicKeyPath) || !is_readable($this->config->privateKeyPath)) {
            throw new InvalidArgumentException('PGP key files are missing. Run php bin/init-pgp.php');
        }

        $crypto = new PgpCrypto($this->config->publicKeyPath, $this->config->privateKeyPath);
        $crypto->validate($this->config->unlockPassphrase);
        $this->crypto = $crypto;

        return $this->crypto;
    }

    /**
     * The only key outbound dashboard snapshots may be encrypted to: the
     * user's public key (`data/keys/user-public.asc` or
     * PGP_USER_PUBLIC_KEY_PATH), whose private half never reaches the server.
     *
     * There is deliberately no fallback to the server keypair. Every published
     * artifact carries glucose (PHI), so a missing recipient key is a
     * configuration error and must stop the write, never downgrade to a key
     * the host itself can read.
     */
    public function recipientCrypto(): PgpCrypto
    {
        if ($this->recipient !== null) {
            return $this->recipient;
        }

        $path = $this->config->userPublicKeyPath;
        if ($path === '' || !is_readable($path)) {
            throw new InvalidArgumentException(
                'No user public key to encrypt dashboard snapshots to. '
                . 'Unlock the dashboard so it POSTs its public key to /api/keys, or copy it to '
                . ($path === '' ? 'PGP_USER_PUBLIC_KEY_PATH' : $path) . '. '
                . 'Glucose files are never published under the server key.',
            );
        }

        $crypto = new PgpCrypto($path);
        $crypto->validate();

        return $this->recipient = $crypto;
    }

    public function pollState(): PollStateStore
    {
        return $this->pollState ??= new PollStateStore($this->config->pollStatePath);
    }

    /**
     * Writes the static files the dashboard reads. History goes out as
     * immutable, time-bucketed batches encrypted to the user's public key.
     * Throws when no user key is enrolled — nothing is published unencrypted.
     */
    public function bucketWriter(): BucketWriter
    {
        return $this->bucketWriter ??= new BucketWriter(
            $this->config,
            $this->config->publicPath,
            $this->recipientCrypto(),
            $this->config->unlockPassphrase,
            $this->config->bucketSeconds,
        );
    }

    public function provider(): GlucoseProvider
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        if ($this->config->isMockProvider()) {
            return $this->provider = new MockGlucoseProvider();
        }

        if (!$this->config->isLibreLinkUpProvider()) {
            throw new InvalidArgumentException('Unknown GLUCOSE_PROVIDER: ' . $this->config->glucoseProvider);
        }

        return $this->provider = new LibreLinkUpProvider(
            $this->config,
            $this->logger,
            new SessionStore(
                $this->config->sessionPath,
                $this->logger,
                $this->crypto(),
                $this->config->unlockPassphrase,
            ),
        );
    }

    /**
     * Loopback HTTP intake for a dashboard LibreLinkUp login. Null when
     * AUTH_LISTEN is unset or the provider is not LibreLinkUp. Bind is
     * loopback-only; the public PHP API (Kernel) proxies /api/librelink/ here.
     */
    public function authIntake(): ?AuthIntakeServer
    {
        if ($this->config->authListen === '' || !$this->config->isLibreLinkUpProvider()) {
            return null;
        }

        $provider = $this->provider();
        if (!$provider instanceof LibreLinkUpProvider) {
            return null;
        }

        return AuthIntakeServer::bind(
            $this->config->authListen,
            new AuthIntakeHandler($provider, $this->logger),
            $this->logger,
        );
    }
}
