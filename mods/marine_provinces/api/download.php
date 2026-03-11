<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../../../');
$runtimeDir = ($root ?: dirname(__DIR__, 3)) . '/data/module_runtime/marine_provinces';
$name = basename((string)($_GET['name'] ?? 'summary.json'));
$path = $runtimeDir . '/' . $name;
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File not found';
    exit;
}
$mime = 'application/octet-stream';
if (str_ends_with($name, '.json')) {
    $mime = 'application/json; charset=utf-8';
} elseif (str_ends_with($name, '.png')) {
    $mime = 'image/png';
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
readfile($path);
