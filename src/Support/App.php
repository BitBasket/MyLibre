<?php

declare(strict_types=1);

namespace App\Support;

use App\Contract\GlucoseProvider;
use App\Contract\GlucoseRepository;
use App\Database\SQLiteConnection;
use App\Database\SQLiteGlucoseRepository;
use App\LibreLink\LibreLinkUpProvider;
use App\LibreLink\SessionStore;
use App\Mock\MockGlucoseProvider;
use InvalidArgumentException;
use PDO;

final class App
{
    private ?PDO $pdo = null;

    private ?GlucoseRepository $repository = null;

    private ?GlucoseProvider $provider = null;

    public function __construct(
        public readonly Config $config,
        public readonly Logger $logger,
    ) {
    }

    public static function boot(?string $root = null): self
    {
        $root ??= dirname(__DIR__, 2);
        $config = Config::fromEnv($root);

        return new self($config, new Logger());
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= SQLiteConnection::connect($this->config->sqlitePath);
    }

    public function repository(): GlucoseRepository
    {
        return $this->repository ??= new SQLiteGlucoseRepository($this->pdo());
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
            new SessionStore($this->config->sessionPath, $this->logger),
        );
    }
}
