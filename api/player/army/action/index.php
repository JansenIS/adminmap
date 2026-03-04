<?php

declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/lib/player_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_state_mtime());
}
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());

$token = trim((string)($payload['token'] ?? ''));
$action = trim((string)($payload['action'] ?? ''));
if ($token === '' || $action === '') api_json_response(['error' => 'token_and_action_required'], 400, api_state_mtime());

$state = api_load_state();
$session = player_resolve_session($state, $token);
if ($session === null) api_json_response(['error' => 'invalid_or_expired_token'], 403, api_state_mtime());

$entityType = $session['entity']['type'];
$entityId = $session['entity']['id'];
$owned = array_flip($session['owned_pids']);
$realm =& $state[$entityType][$entityId];
if (!is_array($realm['player_armies'] ?? null)) $realm['player_armies'] = [];
$armies =& $realm['player_armies'];

$findArmyIdx = static function (array $list, string $armyId): int {
  foreach ($list as $idx => $a) {
    if (!is_array($a)) continue;
    if ((string)($a['army_id'] ?? '') === $armyId) return (int)$idx;
  }
  return -1;
};

if ($action === 'muster') {
  $pid = (int)($payload['pid'] ?? 0);
  $size = max(1, (int)($payload['size'] ?? 0));
  if (!isset($owned[$pid])) api_json_response(['error' => 'pid_not_owned'], 400, api_state_mtime());
  $cap = (int)($session['entity']['muster_cap'] ?? 1000);
  $current = 0;
  foreach ($armies as $a) $current += (int)($a['size'] ?? 0);
  if ($current + $size > $cap) api_json_response(['error' => 'muster_cap_exceeded', 'cap' => $cap, 'current' => $current], 400, api_state_mtime());
  $armies[] = [
    'army_id' => 'army_' . substr(player_generate_token(), 0, 10),
    'army_name' => trim((string)($payload['army_name'] ?? 'Новый арьербан')),
    'location_pid' => $pid,
    'size' => $size,
  ];
} elseif ($action === 'move') {
  $armyId = trim((string)($payload['army_id'] ?? ''));
  $toPid = (int)($payload['to_pid'] ?? 0);
  if ($armyId === '' || !isset($owned[$toPid])) api_json_response(['error' => 'invalid_move_payload'], 400, api_state_mtime());
  $idx = $findArmyIdx($armies, $armyId);
  if ($idx < 0) api_json_response(['error' => 'army_not_found'], 404, api_state_mtime());
  $armies[$idx]['location_pid'] = $toPid;
} elseif ($action === 'disband') {
  $armyId = trim((string)($payload['army_id'] ?? ''));
  $idx = $findArmyIdx($armies, $armyId);
  if ($idx < 0) api_json_response(['error' => 'army_not_found'], 404, api_state_mtime());
  array_splice($armies, $idx, 1);
} else {
  api_json_response(['error' => 'unsupported_action'], 400, api_state_mtime());
}

if (!api_save_state($state)) api_json_response(['error' => 'write_failed'], 500, api_state_mtime());
api_json_response(['ok' => true, 'armies' => array_values($armies)], 200, api_state_mtime());
