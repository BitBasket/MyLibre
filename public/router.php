<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$config = App\Support\Config::fromEnv($root);
$kernel = new App\Http\Kernel(
    $config->publicPath,
    $config->authListen,
    new App\Http\KeyEnrollmentHandler($config->userPublicKeyPath),
);
$result = $kernel->handle($_SERVER, file_get_contents('php://input') ?: '');

if (($result['passthrough'] ?? false) === true) {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $pwa = App\Http\Kernel::pwaDir($root);
    $publicFile = $path === '/' || $path === '/index.html'
        ? $config->publicPath . '/index.html'
        : $config->publicPath . $path;
    if (!is_file($publicFile) && is_file($pwa . ($path === '/' ? '/index.html' : $path))) {
        $file = $pwa . ($path === '/' ? '/index.html' : $path);
        $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'html' => 'text/html; charset=utf-8',
            'js' => 'text/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'json', 'webmanifest' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        readfile($file);

        return true;
    }

    return false;
}

http_response_code($result['status']);
foreach ($result['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $result['body'];

return true;
