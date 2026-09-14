<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$config = App\Support\Config::fromEnv(dirname(__DIR__));
$kernel = new App\Http\Kernel(
    $config->publicPath,
    $config->authListen,
    new App\Http\KeyEnrollmentHandler($config->userPublicKeyPath),
);
$result = $kernel->handle($_SERVER, file_get_contents('php://input') ?: '');

if (($result['passthrough'] ?? false) === true) {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = $path === '/' || $path === '/index.html'
        ? $config->publicPath . '/index.html'
        : $config->publicPath . $path;
    if (!is_file($file)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        return;
    }
    $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
        'html' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json', 'webmanifest' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'asc' => 'text/plain; charset=utf-8',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    readfile($file);
    return;
}

http_response_code($result['status']);
foreach ($result['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $result['body'];
