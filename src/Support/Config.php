<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class Config
{
    public function __construct(
        public readonly string $root,
        public readonly string $appEnv,
        public readonly string $host,
        public readonly int $port,
        public readonly string $glucoseProvider,
        public readonly string $libreLinkEmail,
        public readonly string $libreLinkPassword,
        public readonly string $libreLinkRegion,
        public readonly string $libreLinkBaseUri,
        public readonly string $libreLinkPatientId,
        public readonly string $libreLinkClientVersion,
        public readonly string $sqlitePath,
        public readonly string $sessionPath,
        public readonly int $abbottPollSeconds,
        public readonly int $browserPollSeconds,
        public readonly string $dataPath = '',
        public readonly string $publicKeyPath = '',
        public readonly string $privateKeyPath = '',
        public readonly string $unlockPassphrase = '',
        public readonly string $userPublicKeyPath = '',
        public readonly string $pollStatePath = '',
        public readonly int $bucketSeconds = 300,
        public readonly string $publicPath = '',
    ) {
    }

    public static function fromEnv(string $root): self
    {
        Env::load($root);

        $sqlite = Env::get('SQLITE_PATH', '') ?? '';
        $session = Env::get('SESSION_PATH', 'data/libre-session.json.asc') ?? 'data/libre-session.json.asc';
        $userPublic = Env::get('PGP_USER_PUBLIC_KEY_PATH', '') ?? '';

        return new self(
            root: $root,
            appEnv: Env::get('APP_ENV', 'development') ?? 'development',
            host: Env::get('HOST', '127.0.0.1') ?? '127.0.0.1',
            port: self::positiveInt('PORT', Env::get('PORT', '8765'), 8765),
            glucoseProvider: strtolower(Env::get('GLUCOSE_PROVIDER', 'mock') ?? 'mock'),
            libreLinkEmail: Env::get('LIBRELINK_EMAIL', '') ?? '',
            libreLinkPassword: Env::get('LIBRELINK_PASSWORD', '') ?? '',
            libreLinkRegion: strtoupper(Env::get('LIBRELINK_REGION', 'AUTO') ?? 'AUTO'),
            libreLinkBaseUri: Env::get('LIBRELINK_BASE_URI', '') ?? '',
            libreLinkPatientId: Env::get('LIBRELINK_PATIENT_ID', '') ?? '',
            libreLinkClientVersion: Env::get('LIBRELINK_CLIENT_VERSION', '4.16.0') ?? '4.16.0',
            sqlitePath: self::absolutePath($root, $sqlite),
            sessionPath: self::absolutePath($root, $session),
            dataPath: self::absolutePath($root, Env::get('DATA_PATH', 'data/glucose.json.asc') ?? 'data/glucose.json.asc'),
            publicKeyPath: self::absolutePath($root, Env::get('PGP_PUBLIC_KEY_PATH', 'data/keys/public.asc') ?? 'data/keys/public.asc'),
            privateKeyPath: self::absolutePath($root, Env::get('PGP_PRIVATE_KEY_PATH', 'data/keys/private.asc') ?? 'data/keys/private.asc'),
            unlockPassphrase: Env::get('PGP_PASSPHRASE', '') ?? '',
            userPublicKeyPath: $userPublic === '' ? '' : self::absolutePath($root, $userPublic),
            pollStatePath: self::absolutePath($root, Env::get('POLL_STATE_PATH', 'data/poll-state.json') ?? 'data/poll-state.json'),
            bucketSeconds: self::positiveInt('BUCKET_SECONDS', Env::get('BUCKET_SECONDS', '300'), 300),
            publicPath: self::absolutePath($root, Env::get('PUBLIC_PATH', 'public') ?? 'public'),
            abbottPollSeconds: self::positiveInt('ABBOTT_POLL_SECONDS', Env::get('ABBOTT_POLL_SECONDS', '60'), 60),
            browserPollSeconds: self::positiveInt('BROWSER_POLL_SECONDS', Env::get('BROWSER_POLL_SECONDS', '5'), 5),
        );
    }

    public function isMockProvider(): bool
    {
        return $this->glucoseProvider === 'mock';
    }

    public function isLibreLinkUpProvider(): bool
    {
        return $this->glucoseProvider === 'librelinkup';
    }

    public function bindsLocalhostOnly(): bool
    {
        return in_array($this->host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    private static function absolutePath(string $root, string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        return $root . '/' . ltrim($path, '/');
    }

    private static function positiveInt(string $name, ?string $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (!ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException($name . ' must be a positive integer.');
        }

        return (int) $value;
    }
}
