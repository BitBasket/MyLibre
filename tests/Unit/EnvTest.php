<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\Env;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function testValuesThatSurviveTheEnvFile(): void
    {
        foreach (['correct horse battery staple', 'a-b_c.d:e/f', 'has "inner" quotes', 'trailing"quote'] as $value) {
            $this->assertTrue(Env::roundTrips($value), $value);
        }
    }

    public function testValuesThatWouldBeRewritten(): void
    {
        foreach (['', ' leading space', 'trailing space ', "tab\t", '"wrapped"', "'wrapped'", "two\nlines"] as $value) {
            $this->assertFalse(Env::roundTrips($value), var_export($value, true));
        }
    }

    public function testWriteCreatesTheFileWithRestrictivePermissions(): void
    {
        $dir = self::tempDir();
        Env::write($dir . '/.env', 'PGP_PASSPHRASE', 'correct horse battery staple');

        $this->assertSame("PGP_PASSPHRASE=correct horse battery staple\n", file_get_contents($dir . '/.env'));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($dir . '/.env')), -4));
    }

    public function testWriteReplacesOnlyTheMatchingLine(): void
    {
        $dir = self::tempDir();
        file_put_contents($dir . '/.env', "APP_ENV=development\nPGP_PASSPHRASE=old value\nPGP_PASSPHRASE_EXTRA=keep me\n");

        Env::write($dir . '/.env', 'PGP_PASSPHRASE', 'tricky $1 \2 value');

        $this->assertSame(
            "APP_ENV=development\n" . 'PGP_PASSPHRASE=tricky $1 \2 value' . "\nPGP_PASSPHRASE_EXTRA=keep me\n",
            file_get_contents($dir . '/.env'),
        );
    }

    public function testWriteAppendsWhenTheKeyIsMissing(): void
    {
        $dir = self::tempDir();
        file_put_contents($dir . '/.env', "APP_ENV=development\n");

        Env::write($dir . '/.env', 'PGP_PASSPHRASE', 'appended value');

        $this->assertSame("APP_ENV=development\nPGP_PASSPHRASE=appended value\n", file_get_contents($dir . '/.env'));
    }

    public function testWrittenValueIsReadBackVerbatim(): void
    {
        $dir = self::tempDir();
        foreach (['tricky $1 \2 value', 'has=equals sign', 'has "inner" quotes'] as $value) {
            $this->assertTrue(Env::roundTrips($value), $value);
            Env::write($dir . '/.env', 'PGP_PASSPHRASE', $value);

            putenv('PGP_PASSPHRASE');
            unset($_ENV['PGP_PASSPHRASE']);
            Env::reset();
            Env::load($dir);

            $this->assertSame($value, Env::get('PGP_PASSPHRASE'));
        }

        putenv('PGP_PASSPHRASE');
        unset($_ENV['PGP_PASSPHRASE']);
    }

    public function testWriteRejectsAnInvalidKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Env::write(self::tempDir() . '/.env', 'BAD KEY', 'x');
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/mylibre-env-' . uniqid('', true);
        mkdir($dir);

        return $dir;
    }
}
