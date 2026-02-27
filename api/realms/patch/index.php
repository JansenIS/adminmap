<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'PATCH') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['PATCH']], 405, api_state_mtime());
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : null;
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());

$type = trim((string)($payload['type'] ?? ''));
$id = trim((string)($payload['id'] ?? ''));
$changes = $payload['changes'] ?? null;
if ($type === '' || $id === '' || !is_array($changes)) {
  api_json_response(['error' => 'invalid_payload', 'required' => ['type:string', 'id:string', 'changes:object']], 400, api_state_mtime());
}

$state = api_load_state();
$patched = api_patch_realm($state, $type, $id, $changes);
if (!$patched['ok']) {
  $status = (($patched['error'] ?? '') === 'invalid_type') ? 400 : 404;
  api_json_response(['error' => (string)($patched['error'] ?? 'patch_failed')], $status, api_state_mtime());
}

$ok = api_atomic_write_json(api_state_path(), $patched['state']);
if (!$ok) api_json_response(['error' => 'write_failed'], 500, api_state_mtime());

api_json_response([
  'ok' => true,
  'type' => $type,
  'id' => $id,
  'updated_fields' => (int)($patched['updated_fields'] ?? 0),
], 200, api_state_mtime());
