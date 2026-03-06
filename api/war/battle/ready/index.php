<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/war_battle_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_file_mtime(war_battles_path()));
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : null;
if (!is_array($payload)) api_json_response(['ok' => false, 'error' => 'invalid_json'], 400, api_file_mtime(war_battles_path()));
$token = trim((string)($payload['token'] ?? ''));
if ($token === '') api_json_response(['ok' => false, 'error' => 'token_required'], 400, api_file_mtime(war_battles_path()));

$state = api_load_state();
api_sync_army_registry($state, null, false);
$all = war_battle_sync($state, false);
$found = war_battle_find_by_token($token);
if (!is_array($found)) api_json_response(['ok' => false, 'error' => 'invalid_or_expired_token'], 403, api_file_mtime(war_battles_path()));
$battleId = (string)($found['battle']['battle_id'] ?? '');
$side = (string)($found['side'] ?? 'A');
if ($battleId === '' || !isset($all[$battleId]) || !is_array($all[$battleId])) api_json_response(['ok' => false, 'error' => 'battle_not_found'], 404, api_file_mtime(war_battles_path()));

$ready = !empty($payload['ready']);
$battle = $all[$battleId];
if (in_array((string)($battle['status'] ?? ''), ['auto_resolved','finished'], true)) {
  api_json_response(['ok' => false, 'error' => 'battle_closed', 'battle' => $battle], 409, api_file_mtime(war_battles_path()));
}
if (!is_array($battle['ready'] ?? null)) $battle['ready'] = ['A' => false, 'B' => false];
$battle['ready'][$side] = $ready;
$battle['status'] = war_battle_status($battle);
$battle['log'][] = ['at' => time(), 'event' => 'ready_changed', 'side' => $side, 'ready' => $ready];
$all[$battleId] = $battle;
war_battle_save_all($all);

api_json_response(['ok' => true, 'battle' => $battle, 'side' => $side], 200, api_file_mtime(war_battles_path()));
