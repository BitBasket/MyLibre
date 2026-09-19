<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the poller bootstrap.
 *
 * On a fresh install there is no user public key until the dashboard enrols
 * one, and the writer refuses to publish without a recipient. The poller must
 * therefore keep running (so the loopback intake, and thus `/api/keys`, stays
 * reachable) instead of exiting on the missing key.
 */
final class PollerBootstrapTest extends TestCase
{
    public function testPollerWaitsForAnEnrolledUserKeyInsteadOfExiting(): void
    {
        $proc = proc_open(
            [PHP_BINARY, $this->script()],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root(),
            $this->env(),
        );
        $this->assertIsResource($proc, 'unable to launch the poller');

        // Give it long enough to have exited 1 if it were going to.
        usleep(1_500_000);

        $status = proc_get_status($proc);
        $this->assertTrue($status['running'] ?? false, 'the poller must keep running until a user key is enrolled');

        proc_terminate($proc);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($proc);

        $this->assertStringContainsString('waiting for the dashboard to enroll one', $stderr);
    }

    public function testOnceWithoutAUserKeyFailsFast(): void
    {
        $proc = proc_open(
            [PHP_BINARY, $this->script(), '--once'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root(),
            $this->env(),
        );
        $this->assertIsResource($proc, 'unable to launch the poller');

        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exit = proc_close($proc);

        $this->assertSame(1, $exit, '--once has no intake to wait on, so it should still fail fast');
        $this->assertStringContainsString('No user public key', $stderr);
    }

    private function script(): string
    {
        return $this->root() . '/bin/poll-glucose.php';
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * A hermetic environment: the mock provider and no intake, so the process
     * never touches GnuPG, the network, or the real data directory.
     *
     * @return array<string, string>
     */
    private function env(): array
    {
        return [
            'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
            'APP_ENV' => 'test',
            'GLUCOSE_PROVIDER' => 'mock',
            'AUTH_LISTEN' => '',
            'PGP_USER_PUBLIC_KEY_PATH' => sys_get_temp_dir() . '/mylibre-missing-user-key-' . uniqid('', true) . '.asc',
        ];
    }
}
