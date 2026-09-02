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

        foreach (['HOST', 'PORT', 'GLUCOSE_PROVIDER', 'SQLITE_PATH', 'SESSION_PATH'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        Env::reset();
        $config = Config::fromEnv($root);

        $this->assertSame('127.0.0.1', $config->host);
        $this->assertTrue($config->bindsLocalhostOnly());
        $this->assertTrue($config->isMockProvider());
        $this->assertSame($root . '/data/glucose.sqlite', $config->sqlitePath);
    }
}
