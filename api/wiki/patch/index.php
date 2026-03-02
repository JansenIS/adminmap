<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/wiki_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'PATCH') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['PATCH']], 405, api_state_mtime());
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : null;
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());

$valid = api_wiki_validate_patch_payload($payload);
if (!$valid['ok']) {
  api_json_response(['error' => $valid['error'], 'field' => $valid['field'] ?? null], 400, api_state_mtime());
}

if (($valid['kind'] ?? '') === 'province' || ($valid['kind'] ?? '') === 'entity') {
  $state = api_load_state();
  $ifMatch = api_check_if_match($state, $payload);
  if (!$ifMatch['ok']) {
    $status = (($ifMatch['error'] ?? '') === 'if_match_required') ? 428 : 412;
    api_json_response(['error' => ($ifMatch['error'] ?? 'version_conflict'), 'expected_version' => $ifMatch['expected'], 'provided_if_match' => $ifMatch['provided']], $status, api_state_mtime());
  }

  if (($valid['kind'] ?? '') === 'province') {
    $patched = api_patch_province($state, (int)$valid['pid'], (array)$valid['changes']);
    if (!$patched['ok']) {
      $e = (string)($patched['error'] ?? 'patch_failed');
      $status = in_array($e, ['invalid_field', 'invalid_type'], true) ? 400 : 404;
      api_json_response(['error' => $e, 'field' => $patched['field'] ?? null], $status, api_state_mtime());
    }

    $ok = api_atomic_write_json(api_state_path(), $patched['state']);
    if (!$ok) api_json_response(['error' => 'write_failed'], 500, api_state_mtime());
    api_json_response(['ok' => true, 'kind' => 'province', 'pid' => (int)$valid['pid'], 'updated_fields' => (int)($patched['updated_fields'] ?? 0)], 200, api_state_mtime());
  }

  $patched = api_patch_realm($state, (string)$valid['entity_type'], (string)$valid['id'], (array)$valid['changes']);
  if (!$patched['ok']) {
    $e = (string)($patched['error'] ?? 'patch_failed');
    $status = in_array($e, ['invalid_type', 'invalid_field'], true) ? 400 : 404;
    api_json_response(['error' => $e, 'field' => $patched['field'] ?? null], $status, api_state_mtime());
  }

  $ok = api_atomic_write_json(api_state_path(), $patched['state']);
  if (!$ok) api_json_response(['error' => 'write_failed'], 500, api_state_mtime());
  api_json_response(['ok' => true, 'kind' => 'entity', 'entity_type' => (string)$valid['entity_type'], 'id' => (string)$valid['id'], 'updated_fields' => (int)($patched['updated_fields'] ?? 0)], 200, api_state_mtime());
}

$data = genealogy_load();
if (($valid['kind'] ?? '') === 'character') {
  $id = (string)$valid['id'];
  $changes = (array)$valid['changes'];

  $mapped = [];
  $fieldMap = ['description' => 'notes', 'biography' => 'notes', 'title' => 'name'];
  foreach ($changes as $field => $value) {
    $target = $fieldMap[(string)$field] ?? (string)$field;
    $mapped[$target] = $value;
  }

  $updated = genealogy_update_character($data, $id, $mapped);
  if (!is_array($updated)) {
    api_json_response(['error' => 'not_found_or_invalid_character_payload'], 400, genealogy_mtime());
  }

  if (!genealogy_save($data)) api_json_response(['error' => 'write_failed'], 500, genealogy_mtime());
  api_json_response(['ok' => true, 'kind' => 'character', 'id' => $id, 'updated_fields' => count($mapped)], 200, genealogy_mtime());
}

if (($valid['kind'] ?? '') === 'clan') {
  $id = (string)$valid['id'];
  $changes = (array)$valid['changes'];
  if (!is_array($data['clans'] ?? null)) $data['clans'] = [];
  if (!is_array($data['clans'][$id] ?? null)) $data['clans'][$id] = ['name' => $id, 'description' => '', 'emblem_asset_id' => '', 'emblem_svg' => ''];

  $allowed = ['name', 'description', 'emblem_asset_id', 'emblem_svg'];
  $updatedFields = 0;
  foreach ($changes as $field => $value) {
    $f = (string)$field;
    if (!in_array($f, $allowed, true)) continue;
    if (!is_string($value)) continue;
    $data['clans'][$id][$f] = trim($value);
    $updatedFields++;
  }

  if (!genealogy_save($data)) api_json_response(['error' => 'write_failed'], 500, genealogy_mtime());
  api_json_response(['ok' => true, 'kind' => 'clan', 'id' => $id, 'updated_fields' => $updatedFields], 200, genealogy_mtime());
}

api_json_response(['error' => 'invalid_kind'], 400, api_state_mtime());
