<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

if (preg_match('#^/(current|status|history-\d{8})\.json(\.asc)?$#', $path) === 1) {
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
