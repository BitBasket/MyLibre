<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if (str_starts_with($path, '/api/librelink/')) {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $config = App\Support\Config::fromEnv(dirname(__DIR__));
    [$status, $headers, $body] = App\Http\AuthIntakeProxy::forward(
        $config->authListen,
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $path,
        file_get_contents('php://input') ?: '',
        $_SERVER['CONTENT_TYPE'] ?? '',
    );
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $body;

    return true;
}

if ($path !== '/' && is_file($file)) {
    return false;
}

if (preg_match('#^/(current|status|b/\d+)\.json(\.asc)?$#', $path) === 1) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"not_found"}';
    return true;
}

if ($path === '/' || $path === '/index.html') {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
