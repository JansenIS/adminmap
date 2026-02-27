<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

$state = api_load_state();
$mtime = api_state_mtime();
$mode = trim((string)($_GET['mode'] ?? 'provinces'));
$allowed = ['provinces', 'kingdoms', 'great_houses', 'free_cities'];
if (!in_array($mode, $allowed, true)) {
  api_json_response(['error' => 'invalid_mode', 'allowed' => $allowed], 400, $mtime);
}

$payload = api_build_layer_payload($state, $mode);
$requestedVersion = trim((string)($_GET['version'] ?? ''));
if ($requestedVersion !== '' && $requestedVersion !== (string)$payload['version']) {
  api_json_response([
    'error' => 'version_mismatch',
    'requested' => $requestedVersion,
    'current' => $payload['version'],
  ], 409, $mtime);
}

api_json_response($payload, 200, $mtime);
