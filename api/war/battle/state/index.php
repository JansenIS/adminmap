<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/war_battle_api.php';

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') api_json_response(['ok' => false, 'error' => 'token_required'], 400, api_file_mtime(war_battles_path()));
$sinceRev = (int)($_GET['since_rev'] ?? 0);

$state = api_load_state();
api_sync_army_registry($state, null, false);
$rows = war_battle_sync($state, false);
$found = war_battle_find_by_token($token);
if (!is_array($found)) api_json_response(['ok' => false, 'error' => 'invalid_or_expired_token'], 403, api_file_mtime(war_battles_path()));

$battleId = (string)($found['battle']['battle_id'] ?? '');
$side = (string)($found['side'] ?? 'A');
if ($battleId === '' || !isset($rows[$battleId]) || !is_array($rows[$battleId])) api_json_response(['ok' => false, 'error' => 'battle_not_found'], 404, api_file_mtime(war_battles_path()));

$battle = $rows[$battleId];
war_battle_ensure_realtime($battle, $state);
$rows[$battleId] = $battle;
war_battle_save_all($rows);

$rt = (array)($battle['realtime'] ?? []);
$hist = array_values(array_filter((array)($rt['history'] ?? []), static fn($h) => is_array($h)));
if ($sinceRev > 0) {
  $hist = array_values(array_filter($hist, static fn($h) => (int)($h['rev'] ?? 0) > $sinceRev));
}
api_json_response([
  'ok' => true,
  'side' => $side,
  'battle_id' => $battleId,
  'status' => (string)($battle['status'] ?? 'setup'),
  'realtime' => $rt,
  'history' => $hist,
], 200, max(api_state_mtime(), api_file_mtime(war_battles_path())));
