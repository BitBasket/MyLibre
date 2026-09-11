<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class PgpKeyGenerator
{
    public static function ensure(string $publicKeyPath, string $privateKeyPath, string $passphrase, string $gpg = 'gpg'): void
    {
        if (is_readable($publicKeyPath) && is_readable($privateKeyPath)) {
            (new PgpCrypto($publicKeyPath, $privateKeyPath, $gpg))->validate($passphrase);
            return;
        }

        self::generate($publicKeyPath, $privateKeyPath, $passphrase, $gpg);
        (new PgpCrypto($publicKeyPath, $privateKeyPath, $gpg))->validate($passphrase);
    }

    public static function generate(string $publicKeyPath, string $privateKeyPath, string $passphrase, string $gpg = 'gpg'): void
    {
        if ($passphrase === '') {
            throw new RuntimeException('A passphrase is required to generate a protected PGP key.');
        }

        $home = sys_get_temp_dir() . '/mylibre-gpg-gen-' . bin2hex(random_bytes(12));
        $umask = umask(0077);
        $ok = mkdir($home, 0700, true);
        umask($umask);
        if (!$ok) {
            throw new RuntimeException('Unable to create PGP key generation workspace.');
        }

        try {
            self::writeCompatConfig($home);
            $batch = <<<'BATCH'
Key-Type: EDDSA
Key-Curve: Ed25519
Key-Usage: sign
Subkey-Type: ECDH
Subkey-Curve: Curve25519
Subkey-Usage: encrypt
Name-Real: MyLibre
Name-Email: mylibre@localhost
Expire-Date: 0
%commit
BATCH;
            self::run(
                $gpg,
                $home,
                [
                    '--batch',
                    '--pinentry-mode', 'loopback',
                    '--passphrase-fd', '3',
                    '--status-fd', '2',
                    '--generate-key',
                ],
                $batch,
                $passphrase,
            );

            $fingerprint = self::fingerprint($gpg, $home);
            self::run(
                $gpg,
                $home,
                [
                    '--batch',
                    '--yes',
                    '--command-fd', '0',
                    '--status-fd', '2',
                    '--pinentry-mode', 'loopback',
                    '--passphrase-fd', '3',
                    '--edit-key',
                    $fingerprint,
                ],
                "setpref SHA512 SHA384 SHA256 SHA224 AES256 AES192 AES ZLIB BZIP2 ZIP Uncompressed\nsave\n",
                $passphrase,
            );

            [$public] = self::run($gpg, $home, ['--batch', '--armor', '--export', $fingerprint]);
            [$private] = self::run(
                $gpg,
                $home,
                [
                    '--batch',
                    '--yes',
                    '--pinentry-mode', 'loopback',
                    '--passphrase-fd', '3',
                    '--armor',
                    '--export-secret-keys',
                    $fingerprint,
                ],
                '',
                $passphrase,
            );

            if (!str_contains($public, 'BEGIN PGP PUBLIC KEY BLOCK')
                || !str_contains($private, 'BEGIN PGP PRIVATE KEY BLOCK')) {
                throw new RuntimeException('GnuPG did not export ASCII-armored key blocks.');
            }

            self::writeKeyFile($publicKeyPath, $public);
            self::writeKeyFile($privateKeyPath, $private);
        } finally {
            @exec('gpgconf --homedir ' . escapeshellarg($home) . ' --kill gpg-agent 2>/dev/null');
            foreach (glob($home . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($home);
        }
    }

    private static function writeKeyFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create PGP key directory.');
        }

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $contents, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to write PGP key file.');
        }
        chmod($path, 0600);
    }

    private static function fingerprint(string $gpg, string $home): string
    {
        [$out] = self::run($gpg, $home, ['--with-colons', '--list-secret-keys']);
        foreach (explode("\n", $out) as $line) {
            $parts = explode(':', $line);
            if (($parts[0] ?? '') === 'fpr' && !empty($parts[9])) {
                return $parts[9];
            }
        }

        throw new RuntimeException('Generated PGP key has no fingerprint.');
    }

    private static function writeCompatConfig(string $home): void
    {
        file_put_contents($home . '/gpg.conf', <<<'CONF'
personal-cipher-preferences AES256 AES192 AES
personal-digest-preferences SHA512 SHA384 SHA256 SHA224
personal-compress-preferences ZLIB BZIP2 ZIP Uncompressed
s2k-cipher-algo AES256
s2k-digest-algo SHA256
CONF);
    }

    /**
     * @param list<string> $args
     * @return array{0: string, 1: string}
     */
    private static function run(string $gpg, string $home, array $args, string $input = '', ?string $secret = null): array
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        if ($secret !== null) {
            $spec[3] = ['pipe', 'r'];
        }

        $process = proc_open(array_merge([$gpg, '--homedir', $home, '--no-options', '--no-tty'], $args), $spec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start GnuPG.');
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, true);
        }

        if ($secret !== null) {
            fwrite($pipes[3], $secret);
            fclose($pipes[3]);
        }
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException('PGP key generation failed: ' . trim($stderr !== '' ? $stderr : $stdout));
        }

        return [$stdout, $stderr];
    }
}
