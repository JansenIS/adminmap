<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_state_mtime());
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : null;
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());

$changes = $payload['changes'] ?? null;
if (!is_array($changes)) {
  api_json_response(['error' => 'invalid_payload', 'required' => ['changes:list']], 400, api_state_mtime());
}

$state = api_load_state();
$applied = api_apply_changeset($state, $changes);
if (!empty($applied['errors'])) {
  api_json_response(['error' => 'changeset_failed', 'applied' => (int)$applied['applied'], 'errors' => $applied['errors']], 400, api_state_mtime());
}

$ok = api_atomic_write_json(api_state_path(), $applied['state']);
if (!$ok) api_json_response(['error' => 'write_failed'], 500, api_state_mtime());

api_json_response([
  'ok' => true,
  'applied' => (int)$applied['applied'],
], 200, api_state_mtime());
