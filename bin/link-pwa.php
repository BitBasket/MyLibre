#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pwa = $root . '/vendor/bitbasket/mycgm-core/pwa';
$public = $root . '/public';
if (!is_dir($pwa)) {
    fwrite(STDERR, "mycgm-core pwa is missing at {$pwa}\n");
    exit(1);
}
if (!is_dir($public) && !mkdir($public, 0755, true) && !is_dir($public)) {
    fwrite(STDERR, "Unable to create {$public}\n");
    exit(1);
}

$keep = ['index.php', 'router.php', 'signup.html'];
$relative = '../vendor/bitbasket/mycgm-core/pwa';
foreach (scandir($pwa) ?: [] as $name) {
    if ($name === '.' || $name === '..' || in_array($name, $keep, true)) {
        continue;
    }
    $target = $public . '/' . $name;
    if (is_link($target)) {
        unlink($target);
    } elseif (file_exists($target)) {
        continue;
    }
    if (!symlink($relative . '/' . $name, $target)) {
        fwrite(STDERR, "Unable to link {$name}\n");
        exit(1);
    }
}

echo "Linked engine PWA into {$public}\n";
