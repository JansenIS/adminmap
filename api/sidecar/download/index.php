<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib.php';

$moduleId = sidecar_sanitize_module_id((string)($_GET['module_id'] ?? ''));
$file = basename((string)($_GET['file'] ?? ''));
if ($moduleId === '' || $file === '') {
    sidecar_json_response(['ok' => false, 'error' => 'module_id and file are required'], 400);
    exit;
}

$registry = sidecar_registry_by_id();
$module = $registry[$moduleId] ?? null;
if (!is_array($module) || !($module['installed'] ?? false) || ($module['invalid'] ?? false)) {
    sidecar_json_response(['ok' => false, 'error' => 'module not available'], 404);
    exit;
}

$runtimeDir = sidecar_repo_root() . '/' . ltrim((string)$module['runtime_dir'], '/');
$path = realpath($runtimeDir . '/' . $file);
$runtimeReal = realpath($runtimeDir);
if ($path === false || $runtimeReal === false || !str_starts_with($path, $runtimeReal . DIRECTORY_SEPARATOR) || !is_file($path)) {
    sidecar_json_response(['ok' => false, 'error' => 'file not found in runtime'], 404);
    exit;
}

$mime = 'application/octet-stream';
if (str_ends_with($file, '.json')) $mime = 'application/json; charset=utf-8';
if (str_ends_with($file, '.txt') || str_ends_with($file, '.log')) $mime = 'text/plain; charset=utf-8';
if (str_ends_with($file, '.png')) $mime = 'image/png';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $moduleId . '_' . $file . '"');
header('Content-Length: ' . (string)filesize($path));
readfile($path);
