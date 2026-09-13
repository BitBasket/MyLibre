#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Public PHP API + poller supervisor.
 *
 * Binds the dashboard/API on loopback (HOST/PORT, default 127.0.0.1:8765)
 * and makes sure the glucose poller is running on AUTH_LISTEN. The browser
 * posts LibreLinkUp credentials here; this process forwards them to the
 * poller over loopback HTTP. Put TLS in front (nginx/Caddy) before exposing
 * a public hostname.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$app = App\Support\App::boot($root);
$config = $app->config;
$host = $config->host;
$port = $config->port;
$listen = $config->authListen;
$public = $config->publicPath;
$router = $public . '/router.php';

if (!is_file($router)) {
    fwrite(STDERR, "Missing router at {$router}\n");
    exit(1);
}

$children = [];
$shutdown = static function () use (&$children): void {
    foreach ($children as $proc) {
        if (is_resource($proc)) {
            $status = proc_get_status($proc);
            if (!empty($status['running']) && isset($status['pid']) && function_exists('posix_kill')) {
                posix_kill((int) $status['pid'], SIGTERM);
            }
            proc_terminate($proc, SIGTERM);
        }
    }
    $children = [];
};
register_shutdown_function($shutdown);

if ($config->isLibreLinkUpProvider() && $listen !== '') {
    if (intakeIsUp($listen)) {
        fwrite(STDOUT, "Using existing poller on {$listen}\n");
    } else {
        $children[] = spawn([PHP_BINARY, $root . '/bin/poll-glucose.php'], $root);
        if (!waitForIntake($listen, 10.0)) {
            fwrite(STDERR, "Poller did not bind AUTH_LISTEN {$listen}\n");
            exit(1);
        }
        fwrite(STDOUT, "Poller listening on {$listen}\n");
    }
} else {
    $children[] = spawn([PHP_BINARY, $root . '/bin/poll-glucose.php'], $root);
}

$server = spawn(
    [PHP_BINARY, '-S', $host . ':' . $port, '-t', $public, $router],
    $public,
);
$children[] = $server;

fwrite(STDOUT, "API http://{$host}:{$port}/\n");
if (!$config->bindsLocalhostOnly()) {
    fwrite(STDERR, "HOST is not loopback. LibreLinkUp login will be refused without HTTPS in front.\n");
}

while (true) {
    foreach ($children as $proc) {
        $status = proc_get_status($proc);
        if (empty($status['running'])) {
            $shutdown();
            exit((int) ($status['exitcode'] ?? 1));
        }
    }
    usleep(200000);
}

/**
 * @param list<string> $command
 * @return resource
 */
function spawn(array $command, string $cwd)
{
    $proc = proc_open(
        $command,
        [0 => STDIN, 1 => STDOUT, 2 => STDERR],
        $pipes,
        $cwd,
    );
    if (!is_resource($proc)) {
        fwrite(STDERR, 'Unable to start ' . implode(' ', $command) . "\n");
        exit(1);
    }

    return $proc;
}

function intakeIsUp(string $listen): bool
{
    $socket = @stream_socket_client('tcp://' . $listen, $errno, $errstr, 0.2);
    if ($socket === false) {
        return false;
    }
    fclose($socket);

    return true;
}

function waitForIntake(string $listen, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    do {
        if (intakeIsUp($listen)) {
            return true;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);

    return false;
}
