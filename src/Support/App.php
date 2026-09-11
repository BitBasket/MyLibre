<?php

declare(strict_types=1);

namespace App\Support;

use App\Contract\GlucoseProvider;
use App\Contract\GlucoseRepository;
use App\Database\EncryptedGlucoseRepository;
use App\LibreLink\LibreLinkUpProvider;
use App\LibreLink\SessionStore;
use App\Mock\MockGlucoseProvider;
use App\Security\PgpCrypto;
use InvalidArgumentException;

final class App
{
    private ?GlucoseRepository $repository = null;

    private ?GlucoseProvider $provider = null;

    private ?PgpCrypto $crypto = null;

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

    public function repository(): GlucoseRepository
    {
        return $this->repository ??= new EncryptedGlucoseRepository(
            $this->config->dataPath,
            $this->crypto(),
            $this->config->unlockPassphrase,
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

        if ($this->config->libreLinkEmail === '' || $this->config->libreLinkPassword === '') {
            throw new InvalidArgumentException('LIBRELINK_EMAIL and LIBRELINK_PASSWORD are required for librelinkup.');
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
}
