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
    sidecar_json_response(['ok' => false, 'error' => 'module not available'], 404);
    exit;
}
if (!($module['supports']['cleanup'] ?? false)) {
    sidecar_json_response(['ok' => false, 'error' => 'cleanup unsupported by module'], 400);
    exit;
}

$runtime = sidecar_repo_root() . '/' . ltrim((string)$module['runtime_dir'], '/');
$existed = is_dir($runtime);
sidecar_rrmdir($runtime);
sidecar_json_response([
    'ok' => true,
    'module_id' => $moduleId,
    'runtime_dir' => $module['runtime_dir'],
    'removed' => $existed,
]);
