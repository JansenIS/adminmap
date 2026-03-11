<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/enterprise_module.php';

try {
    $root = eo_find_repo_root(__DIR__);
    $scope = trim((string)($_GET['scope'] ?? 'state'));
    if ($scope === 'trace') {
        $path = eo_trace_path($root);
        $name = 'enterprise_overlay_trace.json';
    } elseif ($scope === 'turn') {
        $year = (int)($_GET['turn_year'] ?? 0);
        if ($year <= 0) throw new RuntimeException('turn_year required');
        $path = eo_turn_overlay_path($root, $year);
        $name = 'enterprise_overlay_turn_' . $year . '.json';
    } else {
        $path = eo_state_overlay_path($root);
        $name = 'enterprise_overlay_state.json';
    }

    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'overlay file not found', 'path' => $path], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    readfile($path);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
