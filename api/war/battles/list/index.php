<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/lib/war_battle_api.php';

$state = api_load_state();
api_sync_army_registry($state, null, false);
$rows = war_battle_sync($state, false);

$playerToken = player_admin_token_from_request();
$scope = null;
if ($playerToken !== '') $scope = player_admin_resolve_session($state, $playerToken);

$out = [];
foreach ($rows as $battle) {
  if (!is_array($battle)) continue;
  $entry = [
    'battle_id' => (string)($battle['battle_id'] ?? ''),
    'province_pid' => (int)($battle['province_pid'] ?? 0),
    'status' => (string)($battle['status'] ?? 'setup'),
    'auto_resolve_at' => (int)($battle['auto_resolve_at'] ?? 0),
    'sides' => $battle['sides'] ?? [],
  ];
  if ($scope) {
    $realmType = (string)($scope['entity_type'] ?? '');
    $realmId = (string)($scope['entity_id'] ?? '');
    foreach (['A', 'B'] as $side) {
      $sideType = (string)($battle['sides'][$side]['realm_type'] ?? '');
      $sideId = (string)($battle['sides'][$side]['realm_id'] ?? '');
      if ($sideType === $realmType && $sideId === $realmId) {
        $tok = (string)($battle['tokens'][$side] ?? '');
        if ($tok !== '') {
          $entry['my_side'] = $side;
          $entry['my_link'] = '/battle_sim/token.html?token=' . rawurlencode($tok);
        }
      }
    }
  }
  $out[] = $entry;
}

api_json_response(['ok' => true, 'battles' => $out, 'scope' => $scope], 200, max(api_state_mtime(), api_file_mtime(war_battles_path())));
