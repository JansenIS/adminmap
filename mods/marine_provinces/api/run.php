<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/MarineProvincesModule.php';

$root = realpath(__DIR__ . '/../../../');
$raw = file_get_contents('php://input') ?: '{}';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = [];
}

try {
    $module = new MarineProvincesModule($root ?: dirname(__DIR__, 3));
    $result = $module->run([
        'write_runtime' => empty($payload['dry_run']),
        'downsample' => isset($payload['downsample']) ? (int)$payload['downsample'] : null,
        'territorial_radius_cells' => isset($payload['territorial_radius_cells']) ? (int)$payload['territorial_radius_cells'] : null,
        'target_neutral_area_cells' => isset($payload['target_neutral_area_cells']) ? (int)$payload['target_neutral_area_cells'] : null,
    ]);
    echo json_encode(['ok' => true, 'summary' => $result['summary'], 'meta' => $result['meta']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
