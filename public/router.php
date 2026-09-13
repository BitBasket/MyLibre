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
    return false;
}

http_response_code($result['status']);
foreach ($result['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $result['body'];

return true;
