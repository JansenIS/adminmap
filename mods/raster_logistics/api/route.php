<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/lib/RasterLogisticsUnifiedModule.php';
try {
    $input = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid JSON body');
    $from = (string)($input['from'] ?? '');
    $to = (string)($input['to'] ?? '');
    $mode = (string)($input['mode'] ?? 'cargo');
    if ($from === '' || $to === '') throw new InvalidArgumentException('from and to are required');
    $module = new RasterLogisticsUnifiedModule(dirname(__DIR__, 3));
    echo json_encode($module->findRoute($from, $to, $mode), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
