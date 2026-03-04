<?php

declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/lib/player_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_state_mtime());
}
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());

$token = trim((string)($payload['token'] ?? ''));
if ($token === '') api_json_response(['error' => 'token_required'], 400, api_state_mtime());

$state = api_load_state();
$session = player_resolve_session($state, $token);
if ($session === null) api_json_response(['error' => 'invalid_or_expired_token'], 403, api_state_mtime());

$entityType = $session['entity']['type'];
$entityId = $session['entity']['id'];
$realm =& $state[$entityType][$entityId];
$updated = ['entity' => 0, 'provinces' => 0];

$entityPatch = $payload['entity'] ?? null;
if (is_array($entityPatch)) {
  foreach (['emblem_svg', 'wiki_description', 'image_url'] as $field) {
    if (!array_key_exists($field, $entityPatch)) continue;
    $realm[$field] = (string)$entityPatch[$field];
    $updated['entity']++;
  }
}

$owned = array_flip($session['owned_pids']);
$provincesPatch = $payload['provinces'] ?? null;
if (is_array($provincesPatch)) {
  foreach ($provincesPatch as $row) {
    if (!is_array($row)) continue;
    $pid = (int)($row['pid'] ?? 0);
    if ($pid <= 0 || !isset($owned[$pid])) continue;
    if (!isset($state['provinces'][(string)$pid]) || !is_array($state['provinces'][(string)$pid])) continue;
    foreach (['emblem_svg', 'wiki_description', 'province_card_image'] as $field) {
      if (!array_key_exists($field, $row)) continue;
      $state['provinces'][(string)$pid][$field] = (string)$row[$field];
      $updated['provinces']++;
    }
  }
}

if (!api_save_state($state)) api_json_response(['error' => 'write_failed'], 500, api_state_mtime());
api_json_response(['ok' => true, 'updated' => $updated], 200, api_state_mtime());
