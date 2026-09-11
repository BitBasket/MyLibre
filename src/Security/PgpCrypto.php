<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class PgpCrypto
{
    public function __construct(
        private readonly string $publicKeyPath,
        private readonly string $privateKeyPath,
        private readonly string $gpg = 'gpg',
    ) {
    }

    public function encrypt(string $plaintext, ?string $passphrase = null): string
    {
        if ($passphrase === null || $passphrase === '') {
            throw new RuntimeException('A passphrase is required for signed encryption.');
        }

        $home = $this->home();
        try {
            $this->writeCompatConfig($home);
            $this->import($home, $this->publicKeyPath);
            $this->import($home, $this->privateKeyPath);
            $fingerprint = $this->fingerprint($home, true);
            [$out, $status] = $this->run(
                $home,
                [
                    '--batch',
                    '--yes',
                    '--pinentry-mode', 'loopback',
                    '--passphrase-fd', '3',
                    '--armor',
                    '--status-fd', '2',
                    '--rfc4880',
                    '--compress-algo', 'ZIP',
                    '--cipher-algo', 'AES256',
                    '--local-user', $fingerprint,
                    '--sign',
                    '--trust-model', 'always',
                    '--recipient', $fingerprint,
                    '--encrypt',
                ],
                $plaintext,
                $passphrase,
            );
            if (!str_contains($status, 'SIG_CREATED')) {
                throw new RuntimeException('PGP signature was not created.');
            }
            if (!str_contains($out, 'BEGIN PGP MESSAGE')) {
                throw new RuntimeException('PGP encryption did not return an armored message.');
            }

            return $out;
        } finally {
            $this->cleanup($home);
        }
    }

    public function decrypt(string $ciphertext, string $passphrase): string
    {
        if ($passphrase === '') {
            throw new RuntimeException('A passphrase is required to unlock encrypted data.');
        }

        $home = $this->home();
        try {
            $this->writeCompatConfig($home);
            $this->import($home, $this->privateKeyPath);
            $expected = $this->fingerprint($home, true);
            [$out, $status] = $this->run(
                $home,
                [
                    '--batch',
                    '--pinentry-mode', 'loopback',
                    '--passphrase-fd', '3',
                    '--status-fd', '2',
                    '--decrypt',
                ],
                $ciphertext,
                $passphrase,
            );
            $valid = false;
            foreach (preg_split('/\R/', $status) ?: [] as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (($parts[1] ?? '') === 'VALIDSIG' && strtoupper((string) ($parts[2] ?? '')) === strtoupper($expected)) {
                    $valid = true;
                    break;
                }
            }
            if (!$valid) {
                throw new RuntimeException('Encrypted payload has no valid signature from the configured key.');
            }

            return $out;
        } finally {
            $this->cleanup($home);
        }
    }

    public function validate(string $passphrase): void
    {
        if (!is_readable($this->publicKeyPath) || !is_readable($this->privateKeyPath)) {
            throw new RuntimeException('Configured PGP key files are missing or unreadable.');
        }

        $public = file_get_contents($this->publicKeyPath);
        $private = file_get_contents($this->privateKeyPath);
        if ($public === false || $private === false
            || !str_contains($public, 'BEGIN PGP PUBLIC KEY BLOCK')
            || !str_contains($private, 'BEGIN PGP PRIVATE KEY BLOCK')) {
            throw new RuntimeException('PGP keys must be ASCII-armored key blocks.');
        }
        if ($passphrase === '') {
            throw new RuntimeException('The private PGP key must be passphrase protected.');
        }

        $home = $this->home();
        try {
            $this->writeCompatConfig($home);
            $this->import($home, $this->publicKeyPath);
            $publicFingerprint = $this->fingerprint($home, false);
            $this->import($home, $this->privateKeyPath);
            if ($publicFingerprint !== $this->fingerprint($home, true)) {
                throw new RuntimeException('Configured PGP public and private keys do not match.');
            }
            [$packets] = $this->run($home, ['--list-packets'], $private);
            if (!preg_match('/protected|s2k/i', $packets)) {
                throw new RuntimeException('The private PGP key must be passphrase protected.');
            }
        } finally {
            $this->cleanup($home);
        }

        $this->decrypt($this->encrypt('key-check', $passphrase), $passphrase);
    }

    private function import(string $home, string $path): void
    {
        $this->run($home, ['--batch', '--import'], (string) file_get_contents($path));
    }

    private function fingerprint(string $home, bool $secret): string
    {
        [$out] = $this->run($home, ['--with-colons', $secret ? '--list-secret-keys' : '--list-keys'], '');
        foreach (explode("\n", $out) as $line) {
            $parts = explode(':', $line);
            if (($parts[0] ?? '') === 'fpr' && !empty($parts[9])) {
                return $parts[9];
            }
        }

        throw new RuntimeException('No usable PGP key found.');
    }

    /**
     * @param list<string> $args
     * @return array{0: string, 1: string}
     */
    private function run(string $home, array $args, string $input = '', ?string $secret = null): array
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        if ($secret !== null) {
            $spec[3] = ['pipe', 'r'];
        }

        $command = array_merge([$this->gpg, '--homedir', $home, '--no-options', '--no-tty'], $args);
        $process = proc_open($command, $spec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start GnuPG.');
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $stdin = $input;
        $pass = $secret ?? '';
        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $stdinOpen = true;
        $passOpen = $secret !== null;

        while ($stdin !== '' || $pass !== '' || !feof($pipes[1]) || !feof($pipes[2])) {
            if (microtime(true) - $started > 60) {
                proc_terminate($process);
                throw new RuntimeException('GnuPG operation timed out.');
            }

            $read = [];
            $write = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }
            if ($stdinOpen && $stdin !== '') {
                $write[] = $pipes[0];
            }
            if ($passOpen && $pass !== '') {
                $write[] = $pipes[3];
            }

            $except = null;
            @stream_select($read, $write, $except, 0, 200000);

            foreach ($read as $pipe) {
                $chunk = fread($pipe, 8192);
                if ($chunk === false) {
                    continue;
                }
                if ($pipe === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }

            foreach ($write as $pipe) {
                $buffer = $pipe === $pipes[0] ? $stdin : $pass;
                $written = fwrite($pipe, $buffer);
                if ($written === false) {
                    continue;
                }
                if ($pipe === $pipes[0]) {
                    $stdin = substr($stdin, $written);
                    if ($stdin === '') {
                        fclose($pipes[0]);
                        $stdinOpen = false;
                    }
                } else {
                    $pass = substr($pass, $written);
                    if ($pass === '') {
                        fclose($pipes[3]);
                        $passOpen = false;
                    }
                }
            }

            if ($stdinOpen && $stdin === '') {
                fclose($pipes[0]);
                $stdinOpen = false;
            }
            if ($passOpen && $pass === '') {
                fclose($pipes[3]);
                $passOpen = false;
            }
        }

        foreach ([1, 2] as $index) {
            if (is_resource($pipes[$index])) {
                fclose($pipes[$index]);
            }
        }

        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException('PGP operation failed.');
        }

        return [$stdout, $stderr];
    }

    private function writeCompatConfig(string $home): void
    {
        $config = <<<'CONF'
personal-cipher-preferences AES256 AES192 AES
personal-digest-preferences SHA512 SHA384 SHA256 SHA224
personal-compress-preferences ZLIB BZIP2 ZIP Uncompressed
s2k-cipher-algo AES256
s2k-digest-algo SHA256
CONF;
        file_put_contents($home . '/gpg.conf', $config);
    }

    private function home(): string
    {
        $home = sys_get_temp_dir() . '/mylibre-gpg-' . bin2hex(random_bytes(12));
        $umask = umask(0077);
        $ok = mkdir($home, 0700, true);
        umask($umask);
        if (!$ok) {
            throw new RuntimeException('Unable to create secure PGP workspace.');
        }

        return $home;
    }

    private function cleanup(string $home): void
    {
        @exec('gpgconf --homedir ' . escapeshellarg($home) . ' --kill gpg-agent 2>/dev/null');
        foreach (glob($home . '/*') ?: [] as $file) {
            if (is_dir($file)) {
                $this->cleanup($file);
            } else {
                @unlink($file);
            }
        }
        @rmdir($home);
    }
}
