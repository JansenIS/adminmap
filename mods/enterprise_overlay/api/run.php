<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/enterprise_module.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $root = eo_find_repo_root(__DIR__);
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $input = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $input = $decoded;
        }
        if ($input === [] && !empty($_POST)) $input = $_POST;
    } else {
        $input = $_GET;
    }

    $action = trim((string)($input['action'] ?? 'run'));
    if ($action === 'cleanup') {
        $result = eo_cleanup_runtime($root);
        echo json_encode(['ok' => true, 'action' => 'cleanup', 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'latest') {
        $payload = eo_read_json_file(eo_state_overlay_path($root), []);
        echo json_encode(['ok' => true, 'action' => 'latest', 'overlay' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $options = [
        'dry_run' => filter_var($input['dry_run'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
        'attach_to_turn' => filter_var($input['attach_to_turn'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
        'turn_year' => (int)($input['turn_year'] ?? 0),
        'run_label' => (string)($input['run_label'] ?? 'manual'),
    ];

    $result = eo_run_module($root, $options);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
