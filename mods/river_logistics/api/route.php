<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/RiverLogisticsModule.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Invalid JSON payload');
    }
    $from = (string)($payload['from_province_id'] ?? '');
    $to = (string)($payload['to_province_id'] ?? '');
    $mode = (string)($payload['mode'] ?? 'cargo');
    if ($from === '' || $to === '') {
        throw new InvalidArgumentException('from_province_id and to_province_id are required');
    }
    $module = new RiverLogisticsModule();
    echo json_encode($module->findRoute($from, $to, $mode), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
