<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'PATCH') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['PATCH']], 405, api_state_mtime());
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : null;
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());

$valid = api_validate_province_patch_payload($payload);
if (!$valid['ok']) {
  api_json_response(['error' => $valid['error'], 'field' => $valid['field'] ?? null, 'required' => $valid['required'] ?? null], 400, api_state_mtime());
}
$pid = (int)$valid['pid'];
$changes = $valid['changes'];

$state = api_load_state();
$ifMatch = api_check_if_match($state, $payload);
if (!$ifMatch['ok']) {
  $status = (($ifMatch['error'] ?? '') === 'if_match_required') ? 428 : 412;
  api_json_response(['error' => ($ifMatch['error'] ?? 'version_conflict'), 'expected_version' => $ifMatch['expected'], 'provided_if_match' => $ifMatch['provided']], $status, api_state_mtime());
}
$patched = api_patch_province($state, $pid, $changes);
if (!$patched['ok']) {
  $e = (string)($patched['error'] ?? 'patch_failed');
  $status = in_array($e, ['invalid_field','invalid_type'], true) ? 400 : 404;
  api_json_response(['error' => $e, 'field' => $patched['field'] ?? null], $status, api_state_mtime());
}

$ok = api_atomic_write_json(api_state_path(), $patched['state']);
if (!$ok) api_json_response(['error' => 'write_failed'], 500, api_state_mtime());

api_json_response([
  'ok' => true,
  'pid' => $pid,
  'updated_fields' => (int)($patched['updated_fields'] ?? 0),
], 200, api_state_mtime());
