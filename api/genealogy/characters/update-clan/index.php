<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/lib/genealogy_api.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'PATCH' && $method !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['PATCH', 'POST']], 405, genealogy_mtime());
}

$payload = api_read_json_body();
$id = trim((string)($payload['id'] ?? $_GET['id'] ?? ''));

if ($id === '') {
  api_json_response(['error' => 'id_required'], 400, genealogy_mtime());
}

$data = genealogy_load();
$access = genealogy_resolve_admin_access();
$previous = null;
foreach (($data['characters'] ?? []) as $row) {
  if (!is_array($row)) continue;
  if ((string)($row['id'] ?? '') !== $id) continue;
  $previous = $row;
  break;
}

if (is_array($access) && (!is_array($previous) || !genealogy_character_in_access_clan($previous, $access))) {
  genealogy_forbidden_for_access($access);
}

$character = genealogy_update_character($data, $id, is_array($payload) ? $payload : []);
if ($character === null) {
  api_json_response(['error' => 'character_not_found_or_invalid_payload'], 400, genealogy_mtime());
}

if (is_array($access) && !genealogy_character_in_access_clan($character, $access)) {
  genealogy_forbidden_for_access($access);
}

if (!genealogy_save($data)) {
  api_json_response(['error' => 'write_failed'], 500, genealogy_mtime());
}

genealogy_sync_people_profiles_from_characters([$character], true, true);

$oldName = trim((string)($previous['name'] ?? ''));
$newName = trim((string)($character['name'] ?? ''));
if ($oldName !== '' && $newName !== '' && $oldName !== $newName) {
  $mapState = api_load_state();
  if (api_replace_person_name_in_state($mapState, $oldName, $newName)) {
    api_atomic_write_json(api_state_path(), $mapState);
  }
}

api_json_response([
  'ok' => true,
  'character' => $character,
], 200, genealogy_mtime());
