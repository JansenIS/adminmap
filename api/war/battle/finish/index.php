<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/lib/war_battle_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_file_mtime(war_battles_path()));
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : null;
if (!is_array($payload)) api_json_response(['ok' => false, 'error' => 'invalid_json'], 400, api_file_mtime(war_battles_path()));

$token = trim((string)($payload['token'] ?? ''));
if ($token === '') api_json_response(['ok' => false, 'error' => 'token_required'], 400, api_file_mtime(war_battles_path()));

$remainingUnits = $payload['remaining_units'] ?? null;
if (!is_array($remainingUnits)) api_json_response(['ok' => false, 'error' => 'remaining_units_required'], 400, api_file_mtime(war_battles_path()));

$state = api_load_state();
api_sync_army_registry($state, null, false);
$rows = war_battle_sync($state, false);
$found = war_battle_find_by_token($token);
if (!is_array($found)) api_json_response(['ok' => false, 'error' => 'invalid_or_expired_token'], 403, api_file_mtime(war_battles_path()));

$battleId = (string)($found['battle']['battle_id'] ?? '');
if ($battleId === '' || !isset($rows[$battleId]) || !is_array($rows[$battleId])) api_json_response(['ok' => false, 'error' => 'battle_not_found'], 404, api_file_mtime(war_battles_path()));

$battle = $rows[$battleId];
if (in_array((string)($battle['status'] ?? ''), ['finished', 'auto_resolved'], true)) {
  api_json_response(['ok' => false, 'error' => 'battle_closed', 'battle' => $battle], 409, api_file_mtime(war_battles_path()));
}

$winnerRaw = trim((string)($payload['winner'] ?? ''));
$winner = in_array($winnerRaw, ['A','B','draw'], true) ? $winnerRaw : null;

$finalized = war_battle_finalize_manual($battle, $state, $remainingUnits, $winner);
$rows[$battleId] = $finalized;
if (!api_atomic_write_json(api_state_path(), $state)) api_json_response(['ok' => false, 'error' => 'state_write_failed'], 500, api_file_mtime(war_battles_path()));
war_battle_save_all($rows);

api_json_response(['ok' => true, 'battle' => $finalized], 200, max(api_state_mtime(), api_file_mtime(war_battles_path())));
