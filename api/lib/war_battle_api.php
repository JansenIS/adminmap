<?php

declare(strict_types=1);

require_once __DIR__ . '/state_api.php';
require_once __DIR__ . '/player_admin_api.php';

function war_battles_path(): string { return api_repo_root() . '/data/war_battles.json'; }

function war_battle_load_all(): array {
  $path = war_battles_path();
  if (!is_file($path)) return [];
  $raw = @file_get_contents($path);
  if ($raw === false || trim($raw) === '') return [];
  $rows = json_decode($raw, true);
  return is_array($rows) ? $rows : [];
}

function war_battle_save_all(array $rows): bool { return api_atomic_write_json(war_battles_path(), $rows); }

function war_battle_realm_key(array $army): string {
  return trim((string)($army['realm_type'] ?? '')) . ':' . trim((string)($army['realm_id'] ?? ''));
}

function war_battle_pick_sides(array $armies): ?array {
  $byRealm = [];
  foreach ($armies as $army) {
    if (!is_array($army)) continue;
    $realmKey = war_battle_realm_key($army);
    if ($realmKey === ':') continue;
    if (!isset($byRealm[$realmKey])) $byRealm[$realmKey] = [];
    $byRealm[$realmKey][] = $army;
  }
  if (count($byRealm) < 2) return null;
  uasort($byRealm, static function (array $a, array $b): int {
    $sa = array_sum(array_map(static fn($row) => (int)($row['strength_total'] ?? 0), $a));
    $sb = array_sum(array_map(static fn($row) => (int)($row['strength_total'] ?? 0), $b));
    return $sb <=> $sa;
  });
  $keys = array_keys($byRealm);
  $a = $keys[0] ?? '';
  $b = $keys[1] ?? '';
  if ($a === '' || $b === '') return null;
  return [
    'A' => [
      'realm_key' => $a,
      'realm_type' => (string)($byRealm[$a][0]['realm_type'] ?? ''),
      'realm_id' => (string)($byRealm[$a][0]['realm_id'] ?? ''),
      'realm_name' => (string)($byRealm[$a][0]['realm_name'] ?? $a),
      'army_uids' => array_values(array_map(static fn($row) => (string)($row['army_uid'] ?? ''), $byRealm[$a])),
    ],
    'B' => [
      'realm_key' => $b,
      'realm_type' => (string)($byRealm[$b][0]['realm_type'] ?? ''),
      'realm_id' => (string)($byRealm[$b][0]['realm_id'] ?? ''),
      'realm_name' => (string)($byRealm[$b][0]['realm_name'] ?? $b),
      'army_uids' => array_values(array_map(static fn($row) => (string)($row['army_uid'] ?? ''), $byRealm[$b])),
    ],
  ];
}

function war_battle_status(array $battle, ?int $now = null): string {
  if ((string)($battle['status'] ?? '') === 'finished') return 'finished';
  if (!empty($battle['auto_resolved'])) return 'auto_resolved';
  $ts = $now ?? time();
  $deadline = (int)($battle['auto_resolve_at'] ?? 0);
  if ($deadline > 0 && $deadline <= $ts) return 'auto_resolved';
  $readyA = !empty($battle['ready']['A']);
  $readyB = !empty($battle['ready']['B']);
  return ($readyA && $readyB) ? 'active' : 'setup';
}

function war_battle_armies_for_side(array $state, array $battle, string $side): array {
  $uids = (array)($battle['sides'][$side]['army_uids'] ?? []);
  $set = array_fill_keys(array_map('strval', $uids), true);
  $out = [];
  foreach ((array)($state['army_registry'] ?? []) as $army) {
    if (!is_array($army)) continue;
    $uid = (string)($army['army_uid'] ?? '');
    if (!isset($set[$uid])) continue;
    $out[] = $army;
  }
  return $out;
}

function war_battle_unit_profile(string $unitId, string $source): array {
  $src = trim($source);
  $id = trim($unitId);
  $map = [
    'militia' => ['attack' => 0.010, 'defense' => 0.008, 'morale' => 55],
    'sergeants' => ['attack' => 0.016, 'defense' => 0.013, 'morale' => 62],
    'nehts' => ['attack' => 0.022, 'defense' => 0.016, 'morale' => 68],
    'knights' => ['attack' => 0.030, 'defense' => 0.022, 'morale' => 75],
    'other' => ['attack' => 0.014, 'defense' => 0.012, 'morale' => 60],
  ];
  $p = $map[$src] ?? $map['other'];

  if (in_array($id, ['shot', 'dragoons', 'gauss', 'gauss_raiders', 'assault150'], true)) $p['attack'] *= 1.12;
  if (in_array($id, ['pikes', 'foot_knights', 'preventors100', 'wagenburg'], true)) $p['defense'] *= 1.20;
  if (in_array($id, ['palatines', 'moto_knights', 'foot_knights'], true)) $p['attack'] *= 1.16;
  if (in_array($id, ['militia_tr', 'militia', 'catapult', 'trebuchet'], true)) $p['morale'] = max(35, $p['morale'] - 6);

  return $p;
}

function war_battle_force_from_armies(array $armies, string $side): array {
  $units = [];
  foreach ($armies as $army) {
    if (!is_array($army)) continue;
    $armyUid = (string)($army['army_uid'] ?? '');
    foreach ((array)($army['units'] ?? []) as $idx => $u) {
      if (!is_array($u)) continue;
      $size = max(0, (int)($u['size'] ?? 0));
      if ($size <= 0) continue;
      $unitId = trim((string)($u['unit_id'] ?? ''));
      $source = trim((string)($u['source'] ?? ''));
      $profile = war_battle_unit_profile($unitId, $source);
      $units[] = [
        'id' => $armyUid . '#' . (string)$idx,
        'army_uid' => $armyUid,
        'unit_idx' => (int)$idx,
        'unit_id' => $unitId,
        'source' => $source,
        'side' => $side,
        'hp' => $size,
        'start_hp' => $size,
        'morale' => (float)$profile['morale'],
        'attack' => (float)$profile['attack'],
        'defense' => (float)$profile['defense'],
        'routed' => false,
      ];
    }
  }
  return $units;
}

function war_battle_pick_target_idx(array $defenders): ?int {
  $pool = [];
  $weightSum = 0;
  foreach ($defenders as $i => $d) {
    if (!is_array($d)) continue;
    if (!empty($d['routed'])) continue;
    $hp = max(0, (int)($d['hp'] ?? 0));
    if ($hp <= 0) continue;
    $w = max(1, $hp);
    $pool[] = [$i, $w];
    $weightSum += $w;
  }
  if ($weightSum <= 0 || $pool === []) return null;
  $r = mt_rand(1, $weightSum);
  $acc = 0;
  foreach ($pool as [$i, $w]) {
    $acc += $w;
    if ($r <= $acc) return (int)$i;
  }
  return (int)$pool[0][0];
}

function war_battle_execute_side_fire(array &$attackers, array &$defenders): array {
  $casualties = 0;
  $events = [];
  foreach ($attackers as $aIdx => $a) {
    if (!is_array($a) || !empty($a['routed'])) continue;
    $aHp = max(0, (int)($a['hp'] ?? 0));
    if ($aHp <= 0) continue;
    $targetIdx = war_battle_pick_target_idx($defenders);
    if ($targetIdx === null || !isset($defenders[$targetIdx]) || !is_array($defenders[$targetIdx])) continue;
    $t = $defenders[$targetIdx];
    $tHp = max(0, (int)($t['hp'] ?? 0));
    if ($tHp <= 0) continue;

    $attackRoll = (float)$a['attack'] * (mt_rand(75, 125) / 100);
    $moraleMul = max(0.35, min(1.2, ((float)$a['morale']) / 100));
    $defMul = max(0.30, min(0.95, 1.0 - ((float)$t['defense']) * (mt_rand(80, 120) / 100)));
    $raw = (float)$aHp * $attackRoll * $moraleMul * $defMul;
    $dmg = max(1, (int)floor($raw));
    $maxChunk = max(1, (int)floor($tHp * 0.35));
    if ($dmg > $maxChunk) $dmg = $maxChunk;
    if ($dmg > $tHp) $dmg = $tHp;

    $defenders[$targetIdx]['hp'] = $tHp - $dmg;
    $casualties += $dmg;

    $lossShare = $tHp > 0 ? ($dmg / $tHp) : 1.0;
    $mDrop = ($lossShare * 70.0) + (mt_rand(0, 2));
    $newMorale = max(0.0, ((float)($defenders[$targetIdx]['morale'] ?? 50.0)) - $mDrop);
    $defenders[$targetIdx]['morale'] = $newMorale;
    if ($newMorale < 18.0 && mt_rand(1, 100) <= 60) $defenders[$targetIdx]['routed'] = true;

    if (count($events) < 18) {
      $events[] = [
        'attacker' => (string)($a['unit_id'] ?? ''),
        'target' => (string)($t['unit_id'] ?? ''),
        'damage' => $dmg,
      ];
    }
    $attackers[$aIdx] = $a;
  }
  return ['casualties' => $casualties, 'events' => $events];
}

function war_battle_alive_hp(array $units): int {
  $sum = 0;
  foreach ($units as $u) {
    if (!is_array($u)) continue;
    if (!empty($u['routed'])) continue;
    $sum += max(0, (int)($u['hp'] ?? 0));
  }
  return $sum;
}

function war_battle_group_remaining_by_army(array $units): array {
  $out = [];
  foreach ($units as $u) {
    if (!is_array($u)) continue;
    $armyUid = (string)($u['army_uid'] ?? '');
    if ($armyUid === '') continue;
    $hp = max(0, (int)($u['hp'] ?? 0));
    if (!isset($out[$armyUid])) $out[$armyUid] = 0;
    $out[$armyUid] += $hp;
  }
  ksort($out);
  return $out;
}

function war_battle_remaining_unit_sizes(array $units): array {
  $out = [];
  foreach ($units as $u) {
    if (!is_array($u)) continue;
    $armyUid = (string)($u['army_uid'] ?? '');
    $idx = (int)($u['unit_idx'] ?? -1);
    if ($armyUid === '' || $idx < 0) continue;
    $key = $armyUid . '#' . (string)$idx;
    $out[$key] = max(0, (int)($u['hp'] ?? 0));
  }
  ksort($out);
  return $out;
}

function war_battle_simulate(array $battle, array $state, ?int $seed = null): array {
  if ($seed !== null) mt_srand($seed);
  $unitsA = war_battle_force_from_armies(war_battle_armies_for_side($state, $battle, 'A'), 'A');
  $unitsB = war_battle_force_from_armies(war_battle_armies_for_side($state, $battle, 'B'), 'B');

  $rounds = [];
  $maxRounds = 24;
  for ($round = 1; $round <= $maxRounds; $round++) {
    $aliveA = war_battle_alive_hp($unitsA);
    $aliveB = war_battle_alive_hp($unitsB);
    if ($aliveA <= 0 || $aliveB <= 0) break;

    $order = ($round % 2 === 1) ? ['A', 'B'] : ['B', 'A'];
    $rLog = ['round' => $round, 'steps' => []];
    foreach ($order as $side) {
      if ($side === 'A') {
        $step = war_battle_execute_side_fire($unitsA, $unitsB);
        $rLog['steps'][] = ['side' => 'A', 'casualties' => $step['casualties'], 'events' => $step['events']];
      } else {
        $step = war_battle_execute_side_fire($unitsB, $unitsA);
        $rLog['steps'][] = ['side' => 'B', 'casualties' => $step['casualties'], 'events' => $step['events']];
      }
    }
    $rLog['alive_after'] = ['A' => war_battle_alive_hp($unitsA), 'B' => war_battle_alive_hp($unitsB)];
    $rounds[] = $rLog;
  }

  $endA = war_battle_alive_hp($unitsA);
  $endB = war_battle_alive_hp($unitsB);
  $winner = $endA === $endB ? 'draw' : (($endA > $endB) ? 'A' : 'B');

  return [
    'winner' => $winner,
    'method' => 'battle_sim_v2',
    'rounds_total' => count($rounds),
    'alive' => ['A' => $endA, 'B' => $endB],
    'remaining_by_army' => [
      'A' => war_battle_group_remaining_by_army($unitsA),
      'B' => war_battle_group_remaining_by_army($unitsB),
    ],
    'remaining_units' => war_battle_remaining_unit_sizes(array_merge($unitsA, $unitsB)),
    'rounds' => $rounds,
  ];
}

function war_battle_apply_unit_sizes_to_list(array $units, string $armyUid, array $remaining): array {
  $out = [];
  foreach (array_values($units) as $idx => $u) {
    if (!is_array($u)) continue;
    $key = $armyUid . '#' . (string)$idx;
    $newSize = max(0, (int)($remaining[$key] ?? (int)($u['size'] ?? 0)));
    if ($newSize <= 0) continue;
    $u['size'] = $newSize;
    $out[] = $u;
  }
  return $out;
}

function war_battle_apply_result_to_state(array &$state, array $battle, array $sim): bool {
  $remaining = is_array($sim['remaining_units'] ?? null) ? $sim['remaining_units'] : [];
  if ($remaining === []) return false;

  $changed = false;

  if (is_array($state['army_registry'] ?? null)) {
    foreach ($state['army_registry'] as &$army) {
      if (!is_array($army)) continue;
      $armyUid = (string)($army['army_uid'] ?? '');
      if ($armyUid === '') continue;
      $updated = war_battle_apply_unit_sizes_to_list((array)($army['units'] ?? []), $armyUid, $remaining);
      $newStrength = array_sum(array_map(static fn($u) => (int)($u['size'] ?? 0), $updated));
      if ($newStrength !== (int)($army['strength_total'] ?? 0)) $changed = true;
      $army['units'] = $updated;
      $army['strength_total'] = $newStrength;
    }
    unset($army);
  }

  $types = ['kingdoms', 'great_houses', 'minor_houses', 'free_cities', 'special_territories'];
  foreach ($types as $type) {
    if (!is_array($state[$type] ?? null)) continue;
    foreach ($state[$type] as $realmId => &$realm) {
      if (!is_array($realm)) continue;

      $domainUid = $type . ':' . (string)$realmId . ':domain';
      $newDomain = war_battle_apply_unit_sizes_to_list((array)($realm['arrierban_units'] ?? []), $domainUid, $remaining);
      if ($newDomain !== (array)($realm['arrierban_units'] ?? [])) {
        $realm['arrierban_units'] = $newDomain;
        $changed = true;
      }

      $legacyUid = $type . ':' . (string)$realmId . ':feudal_legacy';
      $newLegacy = war_battle_apply_unit_sizes_to_list((array)($realm['arrierban_vassal_units'] ?? []), $legacyUid, $remaining);
      if ($newLegacy !== (array)($realm['arrierban_vassal_units'] ?? [])) {
        $realm['arrierban_vassal_units'] = $newLegacy;
        $changed = true;
      }

      if (is_array($realm['arrierban_vassal_armies'] ?? null)) {
        foreach ($realm['arrierban_vassal_armies'] as &$vArmy) {
          if (!is_array($vArmy)) continue;
          $armyId = trim((string)($vArmy['army_id'] ?? ''));
          if ($armyId === '') continue;
          $uid = $type . ':' . (string)$realmId . ':' . $armyId;
          $newUnits = war_battle_apply_unit_sizes_to_list((array)($vArmy['units'] ?? []), $uid, $remaining);
          if ($newUnits !== (array)($vArmy['units'] ?? [])) {
            $vArmy['units'] = $newUnits;
            $changed = true;
          }
        }
        unset($vArmy);
      }
    }
    unset($realm);
  }

  if ($changed) {
    api_sync_army_registry($state, null, false);
  }

  return $changed;
}


function war_battle_build_sim_from_remaining(array $battle, array $remainingUnits, ?string $winner = null): array {
  $remainingByArmy = ['A' => [], 'B' => []];
  foreach (['A','B'] as $side) {
    foreach ((array)($battle['sides'][$side]['army_uids'] ?? []) as $uid) {
      $armyUid = (string)$uid;
      if ($armyUid === '') continue;
      $sum = 0;
      foreach ($remainingUnits as $key => $value) {
        $k = (string)$key;
        if (strpos($k, $armyUid . '#') !== 0) continue;
        $sum += max(0, (int)$value);
      }
      $remainingByArmy[$side][$armyUid] = $sum;
    }
    ksort($remainingByArmy[$side]);
  }

  $aliveA = array_sum($remainingByArmy['A']);
  $aliveB = array_sum($remainingByArmy['B']);
  $resolvedWinner = $winner;
  if ($resolvedWinner === null) $resolvedWinner = ($aliveA === $aliveB) ? 'draw' : (($aliveA > $aliveB) ? 'A' : 'B');

  return [
    'winner' => $resolvedWinner,
    'method' => 'manual_token_battle_v1',
    'rounds_total' => null,
    'alive' => ['A' => $aliveA, 'B' => $aliveB],
    'remaining_by_army' => $remainingByArmy,
    'remaining_units' => $remainingUnits,
    'rounds' => [],
  ];
}

function war_battle_finalize_manual(array $battle, array &$state, array $remainingUnits, ?string $winner = null): array {
  $ts = time();
  $sanitized = [];
  foreach ($remainingUnits as $key => $val) {
    $k = trim((string)$key);
    if ($k === '') continue;
    $sanitized[$k] = max(0, (int)$val);
  }
  $sim = war_battle_build_sim_from_remaining($battle, $sanitized, $winner);

  $battle['status'] = 'finished';
  $battle['finished_at'] = $ts;
  $battle['manual_resolved'] = true;
  $battle['auto_resolved'] = false;
  $battle['auto_resolve_result'] = $sim;
  if (!is_array($battle['log'] ?? null)) $battle['log'] = [];
  $battle['log'][] = [
    'at' => $ts,
    'event' => 'battle_finished_manual',
    'winner' => (string)$sim['winner'],
    'method' => (string)$sim['method'],
  ];

  if (war_battle_apply_result_to_state($state, $battle, $sim)) {
    $battle['log'][] = ['at' => $ts, 'event' => 'state_applied'];
  }
  return $battle;
}

function war_battle_auto_resolve_one(array $battle, array &$state, ?int $now = null): array {
  $ts = $now ?? time();
  if ((string)($battle['status'] ?? '') === 'finished') return $battle;
  if (!empty($battle['auto_resolved']) && is_array($battle['auto_resolve_result'] ?? null)) return $battle;

  $seed = ((int)($battle['created_at'] ?? $ts)) + ((int)($battle['province_pid'] ?? 0));
  $sim = war_battle_simulate($battle, $state, $seed);

  $battle['auto_resolved'] = true;
  $battle['status'] = 'auto_resolved';
  $battle['finished_at'] = $ts;
  $battle['auto_resolve_result'] = $sim;
  if (!is_array($battle['log'] ?? null)) $battle['log'] = [];
  $battle['log'][] = [
    'at' => $ts,
    'event' => 'battle_auto_resolved',
    'winner' => (string)($sim['winner'] ?? 'draw'),
    'rounds_total' => (int)($sim['rounds_total'] ?? 0),
    'method' => (string)($sim['method'] ?? 'battle_sim_v2'),
  ];

  if (war_battle_apply_result_to_state($state, $battle, $sim)) {
    $battle['log'][] = ['at' => $ts, 'event' => 'state_applied'];
  }

  return $battle;
}

function war_battle_finalize_expired(array $rows, array &$state, ?int $now = null): array {
  $ts = $now ?? time();
  foreach ($rows as $battleId => $battleRow) {
    if (!is_array($battleRow)) continue;
    if ((string)($battleRow['status'] ?? '') === 'finished') continue;
    $deadline = (int)($battleRow['auto_resolve_at'] ?? 0);
    if ($deadline > 0 && $deadline <= $ts) {
      $battleRow = war_battle_auto_resolve_one($battleRow, $state, $ts);
    } else {
      $battleRow['status'] = war_battle_status($battleRow, $ts);
    }
    $rows[$battleId] = $battleRow;
  }
  return $rows;
}

function war_battle_sync(array $state, bool $includeStaticConflicts = false): array {
  $existing = war_battle_load_all();
  $out = is_array($existing) ? $existing : [];
  $now = time();
  $stateChanged = false;

  $armies = is_array($state['army_registry'] ?? null) ? $state['army_registry'] : [];
  $byPid = [];
  foreach ($armies as $army) {
    if (!is_array($army)) continue;
    $pid = (int)($army['current_pid'] ?? 0);
    if ($pid <= 0) continue;
    if (!isset($byPid[$pid])) $byPid[$pid] = [];
    $byPid[$pid][] = $army;
  }

  foreach ($byPid as $pid => $stack) {
    $realms = [];
    $hasMoved = false;
    foreach ($stack as $army) {
      $realms[war_battle_realm_key($army)] = true;
      if (!empty($army['moved_this_turn'])) $hasMoved = true;
    }
    if (count($realms) < 2) continue;
    if (!$includeStaticConflicts && !$hasMoved) continue;

    $sides = war_battle_pick_sides($stack);
    if (!is_array($sides)) continue;

    $existingId = null;
    foreach ($out as $battleId => $battleRow) {
      if (!is_array($battleRow)) continue;
      if ((int)($battleRow['province_pid'] ?? 0) !== (int)$pid) continue;
      if ((string)($battleRow['status'] ?? '') === 'finished') continue;
      $existingId = (string)$battleId;
      break;
    }
    if ($existingId !== null) continue;

    $battleId = 'battle_' . gmdate('Ymd_His') . '_' . substr(player_admin_generate_token(), 0, 8);
    $tokenA = player_admin_generate_token();
    $tokenB = player_admin_generate_token();
    $out[$battleId] = [
      'battle_id' => $battleId,
      'province_pid' => (int)$pid,
      'created_at' => $now,
      'auto_resolve_at' => $now + 2 * 24 * 60 * 60,
      'status' => 'setup',
      'sides' => $sides,
      'ready' => ['A' => false, 'B' => false],
      'tokens' => ['A' => $tokenA, 'B' => $tokenB],
      'log' => [['at' => $now, 'event' => 'battle_created']],
    ];
  }

  $beforeState = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $out = war_battle_finalize_expired($out, $state, $now);
  $afterState = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $stateChanged = ($beforeState !== $afterState);

  if ($stateChanged) {
    @api_atomic_write_json(api_state_path(), $state);
  }
  war_battle_save_all($out);
  return $out;
}


function war_battle_side_to_color(string $side): string {
  return $side === 'A' ? 'blue' : 'red';
}

function war_battle_color_to_side(string $color): string {
  return $color === 'red' ? 'B' : 'A';
}

function war_battle_unit_runtime_profile(array $u): array {
  $src = (string)($u['source'] ?? 'other');
  $id = (string)($u['unit_id'] ?? '');
  $moveBySrc = ['militia'=>90.0,'sergeants'=>105.0,'nehts'=>140.0,'knights'=>165.0,'other'=>100.0];
  $rangeBySrc = ['militia'=>70.0,'sergeants'=>150.0,'nehts'=>120.0,'knights'=>90.0,'other'=>100.0];
  $move = $moveBySrc[$src] ?? 100.0;
  $range = $rangeBySrc[$src] ?? 100.0;
  if (in_array($id, ['shot','gauss','gauss_raiders','dragoons'], true)) $range += 120;
  if (in_array($id, ['pikes','foot_knights','preventors100'], true)) $move -= 20;
  if (in_array($id, ['palatines','moto_knights','ulans','bikes'], true)) $move += 45;
  return ['move' => max(40.0, $move), 'range' => max(30.0, $range), 'melee' => 85.0];
}

function war_battle_default_realtime_units(array $battle, array $state): array {
  $units = [];
  foreach (['A','B'] as $side) {
    $color = war_battle_side_to_color($side);
    $armies = war_battle_armies_for_side($state, $battle, $side);
    $laneBase = ($color === 'blue') ? -520 : 520;
    $yBase = ($color === 'blue') ? -520 : 520;
    $n = 0;
    foreach ($armies as $army) {
      if (!is_array($army)) continue;
      $armyUid = (string)($army['army_uid'] ?? '');
      $armyUnits = (array)($army['units'] ?? []);
      foreach (array_values($armyUnits) as $idx => $u) {
        if (!is_array($u)) continue;
        $size = max(0, (int)($u['size'] ?? 0));
        if ($size <= 0) continue;
        $uid = $armyUid . '#' . (string)$idx;
        $prof = war_battle_unit_runtime_profile($u);
        $units[] = [
          'uid' => $uid,
          'army_uid' => $armyUid,
          'unit_idx' => (int)$idx,
          'unit_id' => (string)($u['unit_id'] ?? ''),
          'source' => (string)($u['source'] ?? ''),
          'side' => $color,
          'formation' => 'line',
          'men' => $size,
          'baseSize' => max(1, $size),
          'baseXpl' => 1,
          'x' => $laneBase + (($n % 6) * 38) * (($color === 'blue') ? 1 : -1),
          'y' => $yBase + (int)floor($n / 6) * 42,
          'angle' => ($color === 'blue') ? 0 : 3.141592653589793,
          'morale' => 60,
          'state' => 'ready',
          'move_range' => $prof['move'],
          'ranged_range' => $prof['range'],
          'melee_range' => $prof['melee'],
          'acted_turn' => -1,
        ];
        $n++;
      }
    }
  }
  return $units;
}

function war_battle_ensure_realtime(array &$battle, array $state): void {
  if (!is_array($battle['realtime'] ?? null)) $battle['realtime'] = [];
  $rt = (array)$battle['realtime'];
  if (!is_array($rt['state'] ?? null)) {
    $rt['state'] = [
      'rev' => 1,
      'turn' => 1,
      'phase' => 'movement',
      'active_side' => 'A',
      'units' => war_battle_default_realtime_units($battle, $state),
      'updated_at' => time(),
    ];
  }
  if (!is_array($rt['history'] ?? null)) $rt['history'] = [];
  $battle['realtime'] = $rt;
}

function war_battle_units_index_by_uid(array $units): array {
  $idx = [];
  foreach ($units as $i => $u) {
    if (!is_array($u)) continue;
    $uid = (string)($u['uid'] ?? '');
    if ($uid === '') continue;
    $idx[$uid] = (int)$i;
  }
  return $idx;
}

function war_battle_validate_setup_band(array $u, float $y): bool {
  $h = 1400.0;
  $hh = $h / 2.0;
  $third = $h / 3.0;
  $side = (string)($u['side'] ?? 'blue');
  if ($side === 'blue') return ($y >= -$hh && $y <= (-$hh + $third));
  return ($y >= ($hh - $third) && $y <= $hh);
}

function war_battle_apply_action_to_state(array &$state, string $side, array $action): ?string {
  $type = (string)($action['type'] ?? '');
  $units = array_values(array_filter((array)($state['units'] ?? []), static fn($u) => is_array($u)));
  $idx = war_battle_units_index_by_uid($units);
  $active = (string)($state['active_side'] ?? 'A');
  $phase = (string)($state['phase'] ?? 'movement');

  if ($type === 'advance_phase') {
    if ($side !== $active) return 'not_active_side';
    $order = ['movement','ranged','melee','morale'];
    $pidx = array_search($phase, $order, true);
    if (!is_int($pidx)) $pidx = 0;
    if ($pidx < count($order) - 1) {
      $state['phase'] = $order[$pidx + 1];
    } else {
      $state['phase'] = 'movement';
      $state['active_side'] = $active === 'A' ? 'B' : 'A';
      if ($state['active_side'] === 'A') $state['turn'] = (int)($state['turn'] ?? 1) + 1;
    }
    $state['units'] = $units;
    return null;
  }

  $uid = trim((string)($action['uid'] ?? ''));
  if ($uid === '' || !isset($idx[$uid])) return 'unit_not_found';
  $i = $idx[$uid];
  $u = $units[$i];
  if (war_battle_color_to_side((string)($u['side'] ?? 'blue')) !== $side) return 'unit_not_owned';
  if ($side !== $active) return 'not_active_side';

  if ($type === 'move') {
    if (!in_array($phase, ['movement','setup'], true)) return 'phase_forbidden';
    $nx = (float)($action['x'] ?? $u['x'] ?? 0);
    $ny = (float)($action['y'] ?? $u['y'] ?? 0);
    $dx = $nx - (float)($u['x'] ?? 0);
    $dy = $ny - (float)($u['y'] ?? 0);
    $dist = sqrt($dx*$dx + $dy*$dy);
    $max = (float)($u['move_range'] ?? 100.0);
    if ($phase === 'setup') {
      if (!war_battle_validate_setup_band($u, $ny)) return 'setup_band_forbidden';
      $max = max($max, 99999.0);
    }
    if ($dist > $max + 0.001) return 'move_too_far';
    $u['x'] = $nx; $u['y'] = $ny;
    $u['angle'] = (float)($action['angle'] ?? $u['angle'] ?? 0);
    $u['acted_turn'] = (int)($state['turn'] ?? 1);
    $units[$i] = $u; $state['units'] = $units;
    return null;
  }

  if ($type === 'ranged_attack' || $type === 'melee_attack') {
    $required = ($type === 'ranged_attack') ? 'ranged' : 'melee';
    if ($phase !== $required) return 'phase_forbidden';
    $targetUid = trim((string)($action['target_uid'] ?? ''));
    if ($targetUid === '' || !isset($idx[$targetUid])) return 'target_not_found';
    $ti = $idx[$targetUid];
    $t = $units[$ti];
    if ((string)($t['side'] ?? '') === (string)($u['side'] ?? '')) return 'friendly_target';
    $dx = (float)($u['x'] ?? 0) - (float)($t['x'] ?? 0);
    $dy = (float)($u['y'] ?? 0) - (float)($t['y'] ?? 0);
    $dist = sqrt($dx*$dx + $dy*$dy);
    $range = ($type === 'ranged_attack') ? (float)($u['ranged_range'] ?? 120.0) : (float)($u['melee_range'] ?? 85.0);
    if ($dist > $range + 0.001) return 'target_out_of_range';
    $atk = max(1, (int)floor(((int)($u['men'] ?? 0)) * (($type === 'ranged_attack') ? 0.09 : 0.14)));
    $dmg = max(1, (int)floor($atk * (mt_rand(80,120)/100)));
    $t['men'] = max(0, (int)($t['men'] ?? 0) - $dmg);
    $t['morale'] = max(0, (float)($t['morale'] ?? 60) - (($type === 'ranged_attack') ? 4.0 : 7.0));
    if ((int)$t['men'] <= 0) $t['state'] = 'destroyed';
    $u['acted_turn'] = (int)($state['turn'] ?? 1);
    $units[$i] = $u; $units[$ti] = $t; $state['units'] = $units;
    return null;
  }

  return 'unknown_action_type';
}

function war_battle_realtime_commit(array &$battle, array $state, string $side, array $payload): array {
  war_battle_ensure_realtime($battle, $state);
  $rt = (array)$battle['realtime'];
  $cur = (array)($rt['state'] ?? []);

  $baseRev = (int)($payload['base_rev'] ?? $cur['rev'] ?? 1);
  $curRev = (int)($cur['rev'] ?? 1);
  if ($baseRev !== $curRev) {
    return ['error' => 'revision_conflict', 'expected_rev' => $curRev, 'battle' => $battle];
  }

  $actions = array_values(array_filter((array)($payload['actions'] ?? []), static fn($a) => is_array($a)));
  if ($actions === []) {
    $legacy = array_values(array_filter((array)($payload['units'] ?? []), static fn($u) => is_array($u)));
    foreach ($legacy as $u) {
      $actions[] = [
        'type' => 'move',
        'uid' => (string)($u['uid'] ?? ''),
        'x' => (float)($u['x'] ?? 0),
        'y' => (float)($u['y'] ?? 0),
        'angle' => (float)($u['angle'] ?? 0),
      ];
    }
  }

  $tmp = $cur;
  foreach ($actions as $idx => $action) {
    $err = war_battle_apply_action_to_state($tmp, $side, $action);
    if ($err !== null) {
      return ['error' => 'invalid_action', 'action_index' => $idx, 'reason' => $err, 'battle' => $battle];
    }
  }

  $tmp['rev'] = $curRev + 1;
  $tmp['updated_at'] = time();
  $rt['state'] = $tmp;
  $entry = ['rev' => (int)$tmp['rev'], 'at' => time(), 'side' => $side, 'actions' => $actions];
  $hist = array_values(array_filter((array)($rt['history'] ?? []), static fn($h) => is_array($h)));
  $hist[] = $entry;
  if (count($hist) > 400) $hist = array_slice($hist, -400);
  $rt['history'] = $hist;
  $battle['realtime'] = $rt;

  if (!is_array($battle['log'] ?? null)) $battle['log'] = [];
  $battle['log'][] = ['at' => time(), 'event' => 'realtime_action_commit', 'side' => $side, 'rev' => (int)$tmp['rev'], 'actions' => count($actions)];

  return ['ok' => true, 'battle' => $battle];
}

function war_battle_find_by_token(string $token): ?array {
  $tok = trim($token);
  if ($tok === '') return null;
  $all = war_battle_load_all();
  foreach ($all as $battle) {
    if (!is_array($battle)) continue;
    if ((string)($battle['tokens']['A'] ?? '') === $tok) return ['side' => 'A', 'battle' => $battle];
    if ((string)($battle['tokens']['B'] ?? '') === $tok) return ['side' => 'B', 'battle' => $battle];
  }
  return null;
}
