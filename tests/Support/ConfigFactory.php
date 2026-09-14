<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Config;

final class ConfigFactory
{
    public static function make(
        string $root = '/tmp',
        string $provider = 'librelinkup',
        string $region = 'AUTO',
        string $baseUri = '',
        string $patientId = '',
        string $email = 'user@example.com',
        string $password = 'secret',
        string $authListen = '',
        string $publicKeyPath = '',
        string $privateKeyPath = '',
        string $userPublicKeyPath = '',
        string $unlockPassphrase = '',
        string $publicPath = '',
    ): Config {
        return new Config(
            root: $root,
            appEnv: 'testing',
            host: '127.0.0.1',
            port: 8765,
            glucoseProvider: $provider,
            libreLinkEmail: $email,
            libreLinkPassword: $password,
            libreLinkRegion: $region,
            libreLinkBaseUri: $baseUri,
            libreLinkPatientId: $patientId,
            libreLinkClientVersion: '4.16.0',
            sqlitePath: ':memory:',
            sessionPath: $root . '/libre-session.json',
            abbottPollSeconds: 60,
            browserPollSeconds: 5,
            publicKeyPath: $publicKeyPath,
            privateKeyPath: $privateKeyPath,
            unlockPassphrase: $unlockPassphrase,
            userPublicKeyPath: $userPublicKeyPath,
            bucketSeconds: 300,
            publicPath: $publicPath,
            authListen: $authListen,
        );
    }
}
