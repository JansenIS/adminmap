<?php
declare(strict_types=1);
$path = dirname(__DIR__, 3) . '/data/module_runtime/raster_logistics/network.json';
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'network.json not found; run the module first'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="raster_logistics_network.json"');
readfile($path);
