<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib.php';

$input = sidecar_read_json_input();
$moduleId = sidecar_sanitize_module_id((string)($input['module_id'] ?? ''));
if ($moduleId === '') {
    sidecar_json_response(['ok' => false, 'error' => 'module_id required'], 400);
    exit;
}

$registry = sidecar_registry_by_id();
$module = $registry[$moduleId] ?? null;
if (!is_array($module) || !($module['installed'] ?? false) || ($module['invalid'] ?? false)) {
    sidecar_json_response(['ok' => false, 'error' => 'module not available', 'module_id' => $moduleId], 404);
    exit;
}

$runPath = (string)($module['api']['run'] ?? '');
if ($runPath === '') {
    sidecar_json_response(['ok' => false, 'error' => 'run API is not configured'], 400);
    exit;
}

$params = is_array($input['params'] ?? null) ? $input['params'] : [];
$dryRun = (bool)($input['dry_run'] ?? false);
$params['dry_run'] = $dryRun;
if (isset($input['attach_to_turn'])) {
    $params['attach_to_turn'] = (bool)$input['attach_to_turn'];
}
if (isset($input['turn_year'])) {
    $params['turn_year'] = (int)$input['turn_year'];
}

try {
    $runResult = sidecar_run_php_endpoint($runPath, $params);
    $response = [
        'ok' => (bool)($runResult['ok'] ?? true),
        'module_id' => $moduleId,
        'dry_run' => $dryRun,
        'result' => $runResult,
    ];

    $attachRequested = (bool)($input['attach_to_turn'] ?? false);
    if ($attachRequested) {
        if (!($module['supports']['attach_to_turn'] ?? false)) {
            $response['attach'] = ['ok' => false, 'error' => 'module does not support attach_to_turn'];
        } else {
            $year = (int)($input['turn_year'] ?? 0);
            $response['attach'] = sidecar_attach_overlay($moduleId, $year, $runResult);
        }
    }

    sidecar_json_response($response, $response['ok'] ? 200 : 500);
} catch (Throwable $e) {
    sidecar_json_response(['ok' => false, 'error' => $e->getMessage(), 'module_id' => $moduleId], 500);
}
