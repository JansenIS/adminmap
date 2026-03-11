<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/lib/RasterLogisticsUnifiedModule.php';
try {
    $input = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($input)) $input = [];
    $module = new RasterLogisticsUnifiedModule(dirname(__DIR__, 3));
    $result = $module->run($input);
    echo json_encode(['ok' => true, 'summary' => $result['summary'] ?? null, 'sources' => $result['sources'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
