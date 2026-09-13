<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\Config;
use App\Support\Env;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testLoadsEnvFile(): void
    {
        $root = sys_get_temp_dir() . '/mylibre-config-' . uniqid('', true);
        mkdir($root);
        file_put_contents($root . '/.env', "HOST=127.0.0.1\nPORT=8765\nGLUCOSE_PROVIDER=mock\nSQLITE_PATH=data/glucose.sqlite\n");

        foreach (['HOST', 'PORT', 'GLUCOSE_PROVIDER', 'SQLITE_PATH', 'SESSION_PATH', 'AUTH_LISTEN'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        Env::reset();
        $config = Config::fromEnv($root);

        $this->assertSame('127.0.0.1', $config->host);
        $this->assertTrue($config->bindsLocalhostOnly());
        $this->assertTrue($config->isMockProvider());
        $this->assertSame($root . '/data/glucose.sqlite', $config->sqlitePath);
        $this->assertSame('127.0.0.1:8766', $config->authListen);
    }

    public function testAuthListen(): void
    {
        $root = sys_get_temp_dir() . '/mylibre-config-' . uniqid('', true);
        mkdir($root);
        file_put_contents($root . '/.env', "AUTH_LISTEN=127.0.0.1:8766\n");
        Env::reset();
        foreach (['AUTH_LISTEN'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }

        $config = Config::fromEnv($root);
        $this->assertSame('127.0.0.1:8766', $config->authListen);
    }

    public function testLibreLinkCredentialsComeFromDotEnv(): void
    {
        $root = sys_get_temp_dir() . '/mylibre-config-' . uniqid('', true);
        mkdir($root);
        file_put_contents($root . '/.env', "LIBRELINK_EMAIL=user@example.com\nLIBRELINK_PASSWORD=env-secret\n");
        Env::reset();
        foreach (['LIBRELINK_EMAIL', 'LIBRELINK_PASSWORD'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }

        $config = Config::fromEnv($root);
        $this->assertSame('user@example.com', $config->libreLinkEmail);
        $this->assertSame('env-secret', $config->libreLinkPassword);

        foreach (['LIBRELINK_EMAIL', 'LIBRELINK_PASSWORD'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        Env::reset();
    }

    public function testEmptyAuthListenDisablesIntake(): void
    {
        $root = sys_get_temp_dir() . '/mylibre-config-' . uniqid('', true);
        mkdir($root);
        file_put_contents($root . '/.env', "AUTH_LISTEN=\n");
        Env::reset();
        foreach (['AUTH_LISTEN'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }

        $config = Config::fromEnv($root);
        $this->assertSame('', $config->authListen);
    }
}
