<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';
require_once dirname(__DIR__, 2) . '/lib/sync_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_state_mtime());
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : null;
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());

$valid = api_validate_changes_apply_payload($payload);
if (!$valid['ok']) {
  api_json_response(['error' => $valid['error'], 'field' => $valid['field'] ?? null, 'index' => $valid['index'] ?? null, 'required' => $valid['required'] ?? null], 400, api_state_mtime());
}
$changes = $valid['changes'];

$state = api_load_state();
$ifMatch = api_check_if_match($state, $payload);
if (!$ifMatch['ok']) {
  $status = (($ifMatch['error'] ?? '') === 'if_match_required') ? 428 : 412;
  api_json_response(['error' => ($ifMatch['error'] ?? 'version_conflict'), 'expected_version' => $ifMatch['expected'], 'provided_if_match' => $ifMatch['provided']], $status, api_state_mtime());
}
$applied = api_apply_changeset($state, $changes);
if (!empty($applied['errors'])) {
  api_json_response(['error' => 'changeset_failed', 'applied' => (int)$applied['applied'], 'errors' => $applied['errors']], 400, api_state_mtime());
}

$ok = api_atomic_write_json(api_state_path(), $applied['state']);
if (!$ok) api_json_response(['error' => 'write_failed'], 500, api_state_mtime());
foreach ($changes as $entry) {
  if (!is_array($entry)) continue;
  $kind = (string)($entry['kind'] ?? '');
  if ($kind === 'province') {
    $pid = (string)((int)($entry['pid'] ?? 0));
    if ($pid !== '0' && !sync_record_external_change('province', $pid, 'patch', (array)($entry['changes'] ?? []), $applied['state']['provinces'][$pid] ?? [])) api_json_response(['error' => 'sync_journal_failed'], 500, api_state_mtime());
  } elseif ($kind === 'realm') {
    $type = (string)($entry['type'] ?? ''); $id = (string)($entry['id'] ?? '');
    $syncEntityType = ['kingdoms' => 'realm', 'great_houses' => 'great_house', 'minor_houses' => 'minor_house', 'free_cities' => 'free_city', 'special_territories' => 'special_territory'][$type] ?? 'realm';
    if ($id !== '' && !sync_record_external_change($syncEntityType, $id, 'patch', (array)($entry['changes'] ?? []), $applied['state'][$type][$id] ?? [])) api_json_response(['error' => 'sync_journal_failed'], 500, api_state_mtime());
  }
}

api_json_response([
  'ok' => true,
  'applied' => (int)$applied['applied'],
], 200, api_state_mtime());
