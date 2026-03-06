<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/war_battle_api.php';

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') api_json_response(['ok' => false, 'error' => 'token_required'], 400, api_file_mtime(war_battles_path()));

$state = api_load_state();
api_sync_army_registry($state, null, false);
war_battle_sync($state, false);

$resolved = war_battle_find_by_token($token);
if (!is_array($resolved)) api_json_response(['ok' => false, 'error' => 'invalid_or_expired_token'], 403, api_file_mtime(war_battles_path()));

$battle = (array)($resolved['battle'] ?? []);
$battle['status'] = war_battle_status($battle);

war_battle_ensure_realtime($battle, $state);
$rows = war_battle_load_all();
if (is_array($rows)) {
  $rows[(string)($battle['battle_id'] ?? '')] = $battle;
  war_battle_save_all($rows);
}

$mySide = (string)($resolved['side'] ?? 'A');

api_json_response([
  'ok' => true,
  'side' => $mySide,
  'battle' => $battle,
  'my_armies' => war_battle_armies_for_side($state, $battle, $mySide),
  'enemy_armies' => war_battle_armies_for_side($state, $battle, $mySide === 'A' ? 'B' : 'A'),
], 200, max(api_state_mtime(), api_file_mtime(war_battles_path())));
