<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib.php';

$root = sidecar_repo_root();
$pipelineFile = $root . '/data/sidecar_pipelines.json';
$raw = is_file($pipelineFile) ? file_get_contents($pipelineFile) : '{}';
$pipelines = json_decode((string)$raw, true);
if (!is_array($pipelines)) $pipelines = [];

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    sidecar_json_response(['ok' => true, 'pipelines' => $pipelines]);
    exit;
}

$input = sidecar_read_json_input();
$pipelineId = (string)($input['pipeline_id'] ?? '');
$pipeline = $pipelines[$pipelineId] ?? null;
if (!is_array($pipeline)) {
    sidecar_json_response(['ok' => false, 'error' => 'pipeline not found'], 404);
    exit;
}

$steps = is_array($pipeline['steps'] ?? null) ? $pipeline['steps'] : [];
$registry = sidecar_registry_by_id();
$results = [];
$failed = false;
foreach ($steps as $stepIdRaw) {
    $stepId = sidecar_sanitize_module_id((string)$stepIdRaw);
    if ($stepId === '') continue;
    $module = $registry[$stepId] ?? null;
    if (!is_array($module)) {
        $results[] = ['module_id' => $stepId, 'ok' => false, 'error' => 'module not installed'];
        $failed = true;
        break;
    }
    try {
        $result = sidecar_run_php_endpoint((string)$module['api']['run'], ['dry_run' => (bool)($input['dry_run'] ?? false)]);
        $ok = (bool)($result['ok'] ?? true);
        $results[] = ['module_id' => $stepId, 'ok' => $ok, 'result' => $result];
        if (!$ok) {
            $failed = true;
            break;
        }
    } catch (Throwable $e) {
        $results[] = ['module_id' => $stepId, 'ok' => false, 'error' => $e->getMessage()];
        $failed = true;
        break;
    }
}

sidecar_json_response([
    'ok' => !$failed,
    'pipeline_id' => $pipelineId,
    'results' => $results,
]);
