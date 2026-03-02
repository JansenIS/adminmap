<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/wiki_api.php';

$state = api_load_state();
$mtime = api_state_mtime();
$kind = trim((string)($_GET['kind'] ?? ''));

if ($kind === 'province') {
  $pid = (int)($_GET['pid'] ?? 0);
  if ($pid <= 0) api_json_response(['error' => 'invalid_pid'], 400, $mtime);

  $page = api_wiki_build_province_page($state, $pid);
  if (!is_array($page)) api_json_response(['error' => 'not_found'], 404, $mtime);
  api_json_response(['item' => $page], 200, $mtime);
}

if ($kind === 'entity') {
  $entityType = trim((string)($_GET['entity_type'] ?? ''));
  $id = trim((string)($_GET['id'] ?? ''));
  if ($id === '') api_json_response(['error' => 'invalid_id'], 400, $mtime);

  $page = api_wiki_build_entity_page($state, $entityType, $id);
  if (!is_array($page)) api_json_response(['error' => 'not_found'], 404, $mtime);
  api_json_response(['item' => $page], 200, $mtime);
}

$genealogy = api_wiki_load_genealogy_snapshot();
if ($kind === 'clan') {
  $id = trim((string)($_GET['id'] ?? ''));
  if ($id === '') api_json_response(['error' => 'invalid_id'], 400, api_state_mtime());

  $page = api_wiki_build_clan_page($genealogy, $id);
  if (!is_array($page)) api_json_response(['error' => 'not_found'], 404, api_state_mtime());
  api_json_response(['item' => $page], 200, api_state_mtime());
}

if ($kind === 'character') {
  $id = trim((string)($_GET['id'] ?? ''));
  if ($id === '') api_json_response(['error' => 'invalid_id'], 400, api_state_mtime());

  $page = api_wiki_build_character_page($genealogy, $id);
  if (!is_array($page)) api_json_response(['error' => 'not_found'], 404, api_state_mtime());
  api_json_response(['item' => $page], 200, api_state_mtime());
}

api_json_response(['error' => 'invalid_kind', 'allowed' => ['province', 'entity', 'clan', 'character']], 400, $mtime);
