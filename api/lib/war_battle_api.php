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

function war_battle_survivor_hp(array $units): int {
  $sum = 0;
  foreach ($units as $u) {
    if (!is_array($u)) continue;
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
  $survivorsA = war_battle_survivor_hp($unitsA);
  $survivorsB = war_battle_survivor_hp($unitsB);
  $winner = $survivorsA === $survivorsB ? 'draw' : (($survivorsA > $survivorsB) ? 'A' : 'B');

  return [
    'winner' => $winner,
    'method' => 'battle_sim_v2',
    'rounds_total' => count($rounds),
    'alive' => ['A' => $endA, 'B' => $endB],
    'survivors' => ['A' => $survivorsA, 'B' => $survivorsB],
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

  if (war_battle_apply_retreat_after_battle($state, $battle, $sim)) {
    $changed = true;
  }

  return $changed;
}

function war_battle_hexmap_data_cached(): ?array {
  static $cached = null;
  static $loaded = false;
  if ($loaded) return $cached;
  $loaded = true;
  $path = api_repo_root() . '/hexmap/data.js';
  if (!is_file($path)) return null;
  $raw = @file_get_contents($path);
  if ($raw === false) return null;
  $prefix = 'window.HEXMAP=';
  $start = strpos($raw, $prefix);
  if ($start === false) return null;
  $json = trim(substr($raw, $start + strlen($prefix)));
  if (str_ends_with($json, ';')) $json = substr($json, 0, -1);
  $decoded = json_decode($json, true);
  if (!is_array($decoded)) return null;
  $cached = $decoded;
  return $cached;
}

function war_battle_neighbor_coords_oddq(int $q, int $r): array {
  if (($q % 2) !== 0) {
    return [[$q + 1, $r], [$q + 1, $r + 1], [$q, $r + 1], [$q - 1, $r + 1], [$q - 1, $r], [$q, $r - 1]];
  }
  return [[$q + 1, $r - 1], [$q + 1, $r], [$q, $r + 1], [$q - 1, $r], [$q - 1, $r - 1], [$q, $r - 1]];
}

function war_battle_adjacent_pids_cached(): array {
  static $adj = null;
  if (is_array($adj)) return $adj;
  $adj = [];
  $hexmap = war_battle_hexmap_data_cached();
  if (!is_array($hexmap)) return $adj;
  $hexes = is_array($hexmap['hexes'] ?? null) ? $hexmap['hexes'] : [];
  $byCoord = [];
  foreach ($hexes as $hex) {
    if (!is_array($hex)) continue;
    $q = (int)($hex['q'] ?? 0);
    $r = (int)($hex['r'] ?? 0);
    $p = (int)($hex['p'] ?? 0);
    if ($p <= 0) continue;
    $byCoord[$q . ':' . $r] = $p;
  }
  foreach ($hexes as $hex) {
    if (!is_array($hex)) continue;
    $q = (int)($hex['q'] ?? 0);
    $r = (int)($hex['r'] ?? 0);
    $p = (int)($hex['p'] ?? 0);
    if ($p <= 0) continue;
    if (!isset($adj[$p])) $adj[$p] = [];
    foreach (war_battle_neighbor_coords_oddq($q, $r) as [$nq, $nr]) {
      $np = (int)($byCoord[$nq . ':' . $nr] ?? 0);
      if ($np <= 0 || $np === $p) continue;
      $adj[$p][$np] = true;
    }
  }
  foreach ($adj as $pid => $set) {
    $ids = array_map('intval', array_keys($set));
    sort($ids);
    $adj[$pid] = $ids;
  }
  return $adj;
}

function war_battle_pick_retreat_pid(array $state, int $fromPid): int {
  $adj = war_battle_adjacent_pids_cached();
  $candidates = array_values(array_filter(array_map('intval', (array)($adj[$fromPid] ?? [])), static fn($pid) => $pid > 0));
  if ($candidates === []) return 0;

  $occupied = [];
  foreach ((array)($state['army_registry'] ?? []) as $army) {
    if (!is_array($army)) continue;
    if (max(0, (int)($army['strength_total'] ?? 0)) <= 0) continue;
    $pid = (int)($army['current_pid'] ?? 0);
    if ($pid > 0) $occupied[$pid] = true;
  }

  $busyBattlePids = [];
  foreach (war_battle_load_all() as $battleRow) {
    if (!is_array($battleRow)) continue;
    if ((string)($battleRow['status'] ?? '') === 'finished') continue;
    if (!empty($battleRow['auto_resolved'])) continue;
    $pid = (int)($battleRow['province_pid'] ?? 0);
    if ($pid > 0) $busyBattlePids[$pid] = true;
  }

  foreach ($candidates as $pid) {
    if (isset($occupied[$pid])) continue;
    if (isset($busyBattlePids[$pid])) continue;
    return $pid;
  }
  return 0;
}

function war_battle_apply_retreat_after_battle(array &$state, array $battle, array $sim): bool {
  $winner = (string)($sim['winner'] ?? 'draw');
  if (!in_array($winner, ['A', 'B'], true)) return false;
  $loserSide = $winner === 'A' ? 'B' : 'A';
  $loserArmies = array_values(array_filter(array_map('strval', (array)($battle['sides'][$loserSide]['army_uids'] ?? [])), static fn($v) => $v !== ''));
  if ($loserArmies === []) return false;
  $provincePid = (int)($battle['province_pid'] ?? 0);
  if ($provincePid <= 0) return false;

  $loserSet = array_fill_keys($loserArmies, true);
  $changed = false;
  foreach ($state['army_registry'] as &$army) {
    if (!is_array($army)) continue;
    $uid = (string)($army['army_uid'] ?? '');
    if ($uid === '' || !isset($loserSet[$uid])) continue;
    if (max(0, (int)($army['strength_total'] ?? 0)) <= 0) continue;
    if ((int)($army['current_pid'] ?? 0) !== $provincePid) continue;
    $retreatPid = war_battle_pick_retreat_pid($state, $provincePid);
    if ($retreatPid <= 0) continue;
    $army['current_pid'] = $retreatPid;
    $changed = true;
  }
  unset($army);
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
    'survivors' => ['A' => $aliveA, 'B' => $aliveB],
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

function war_battle_finalize_open(array $rows, array &$state, ?int $now = null): array {
  $ts = $now ?? time();
  foreach ($rows as $battleId => $battleRow) {
    if (!is_array($battleRow)) continue;
    if ((string)($battleRow['status'] ?? '') === 'finished') continue;
    if (!empty($battleRow['auto_resolved']) && is_array($battleRow['auto_resolve_result'] ?? null)) continue;
    $rows[$battleId] = war_battle_auto_resolve_one($battleRow, $state, $ts);
  }
  return $rows;
}

function war_battle_generate_token_or_null(): ?string {
  try {
    return player_admin_generate_token();
  } catch (Throwable $_e) {
    return null;
  }
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

    $battleIdToken = war_battle_generate_token_or_null();
    $tokenA = war_battle_generate_token_or_null();
    $tokenB = war_battle_generate_token_or_null();
    if ($battleIdToken === null || $tokenA === null || $tokenB === null) continue;

    $battleId = 'battle_' . gmdate('Ymd_His') . '_' . substr($battleIdToken, 0, 8);
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



function war_battle_clamp(float $v, float $min, float $max): float {
  if ($v < $min) return $min;
  if ($v > $max) return $max;
  return $v;
}

function war_battle_catalog(): array {
  static $catalog = null;
  if ($catalog !== null) return $catalog;
  $catalog = [
    'militia' => ['kind'=>'inf','base_size'=>1000,'base_xpl'=>0.5,'move'=>80.0,'melee'=>['power'=>0.020,'cap_pct'=>0.10],'armor'=>0.04,'morale'=>55.0],
    'militia_tr' => ['kind'=>'inf','base_size'=>1000,'base_xpl'=>1.0,'move'=>85.0,'melee'=>['power'=>0.024,'cap_pct'=>0.12],'armor'=>0.06,'morale'=>62.0],
    'pikes' => ['kind'=>'pike','base_size'=>500,'base_xpl'=>1.0,'move'=>75.0,'melee'=>['power'=>0.030,'cap_pct'=>0.14],'armor'=>0.08,'morale'=>65.0,'tags'=>['antiCav']],
    'shot' => ['kind'=>'shot','base_size'=>500,'base_xpl'=>1.0,'move'=>75.0,'ranged'=>['range'=>240.0,'power'=>0.020,'cap_pct'=>0.06,'acc'=>0.55],'melee'=>['power'=>0.014,'cap_pct'=>0.08],'armor'=>0.06,'morale'=>63.0],
    'engineers' => ['kind'=>'support','base_size'=>10,'base_xpl'=>2.0,'move'=>70.0,'melee'=>['power'=>0.014,'cap_pct'=>0.10],'armor'=>0.10,'morale'=>60.0],
    'city100' => ['kind'=>'inf','base_size'=>100,'base_xpl'=>0.5,'move'=>90.0,'melee'=>['power'=>0.022,'cap_pct'=>0.12],'armor'=>0.06,'morale'=>60.0],
    'assault150' => ['kind'=>'inf','base_size'=>150,'base_xpl'=>2.0,'move'=>85.0,'melee'=>['power'=>0.036,'cap_pct'=>0.18],'armor'=>0.12,'morale'=>70.0],
    'grey250' => ['kind'=>'inf','base_size'=>250,'base_xpl'=>3.0,'move'=>85.0,'melee'=>['power'=>0.040,'cap_pct'=>0.18],'armor'=>0.14,'morale'=>72.0],
    'houseguard150' => ['kind'=>'elite','base_size'=>150,'base_xpl'=>3.0,'move'=>90.0,'melee'=>['power'=>0.048,'cap_pct'=>0.20],'armor'=>0.18,'morale'=>78.0],
    'preventors100' => ['kind'=>'elite','base_size'=>100,'base_xpl'=>4.0,'move'=>90.0,'melee'=>['power'=>0.056,'cap_pct'=>0.22],'armor'=>0.22,'morale'=>85.0],
    'foot_knights' => ['kind'=>'elite','base_size'=>100,'base_xpl'=>2.0,'move'=>85.0,'melee'=>['power'=>0.028,'cap_pct'=>0.11],'armor'=>0.16,'morale'=>74.0],
    'foot_nehts' => ['kind'=>'inf','base_size'=>100,'base_xpl'=>1.0,'move'=>85.0,'melee'=>['power'=>0.014,'cap_pct'=>0.055],'armor'=>0.10,'morale'=>66.0],
    'gauss' => ['kind'=>'gun','base_size'=>1,'base_xpl'=>1.0,'move'=>0.0,'ranged'=>['range'=>520.0,'power'=>0.030,'cap_pct'=>0.08,'acc'=>0.66],'melee'=>['power'=>0.004,'cap_pct'=>0.04],'armor'=>0.20,'morale'=>65.0],
    'bikes' => ['kind'=>'cav','base_size'=>50,'base_xpl'=>1.0,'move'=>220.0,'ranged'=>['range'=>150.0,'power'=>0.012,'cap_pct'=>0.03,'acc'=>0.42],'melee'=>['power'=>0.032,'cap_pct'=>0.14],'armor'=>0.14,'morale'=>70.0],
    'dragoons' => ['kind'=>'cav','base_size'=>50,'base_xpl'=>1.0,'move'=>210.0,'ranged'=>['range'=>170.0,'power'=>0.018,'cap_pct'=>0.04,'acc'=>0.48],'melee'=>['power'=>0.022,'cap_pct'=>0.10],'armor'=>0.14,'morale'=>70.0],
    'ulans' => ['kind'=>'cav','base_size'=>50,'base_xpl'=>0.67,'move'=>215.0,'ranged'=>['range'=>160.0,'power'=>0.012,'cap_pct'=>0.027,'acc'=>0.44],'melee'=>['power'=>0.021,'cap_pct'=>0.093],'armor'=>0.12,'morale'=>66.0],
    'catapult' => ['kind'=>'siege','base_size'=>1,'base_xpl'=>2.0,'move'=>35.0,'ranged'=>['range'=>380.0,'power'=>0.020,'cap_pct'=>0.10,'acc'=>0.48],'melee'=>['power'=>0.004,'cap_pct'=>0.05],'armor'=>0.20,'morale'=>60.0],
    'gauss_raiders' => ['kind'=>'gun','base_size'=>2,'base_xpl'=>2.0,'move'=>160.0,'ranged'=>['range'=>420.0,'power'=>0.026,'cap_pct'=>0.07,'acc'=>0.62],'melee'=>['power'=>0.020,'cap_pct'=>0.10],'armor'=>0.16,'morale'=>68.0],
    'trebuchet' => ['kind'=>'siege','base_size'=>1,'base_xpl'=>3.0,'move'=>25.0,'ranged'=>['range'=>560.0,'power'=>0.022,'cap_pct'=>0.12,'acc'=>0.44],'melee'=>['power'=>0.004,'cap_pct'=>0.05],'armor'=>0.22,'morale'=>60.0],
    'assault_gun' => ['kind'=>'vehicle','base_size'=>1,'base_xpl'=>3.0,'move'=>160.0,'ranged'=>['range'=>360.0,'power'=>0.024,'cap_pct'=>0.10,'acc'=>0.55],'melee'=>['power'=>0.028,'cap_pct'=>0.12],'armor'=>0.28,'morale'=>75.0],
    'palatines' => ['kind'=>'heavycav','base_size'=>20,'base_xpl'=>3.0,'move'=>170.0,'ranged'=>['range'=>120.0,'power'=>0.010,'cap_pct'=>0.03,'acc'=>0.40],'melee'=>['power'=>0.060,'cap_pct'=>0.22],'armor'=>0.24,'morale'=>82.0],
    'moto_knights' => ['kind'=>'cav','base_size'=>20,'base_xpl'=>1.0,'move'=>220.0,'ranged'=>['range'=>120.0,'power'=>0.004,'cap_pct'=>0.01,'acc'=>0.38],'melee'=>['power'=>0.020,'cap_pct'=>0.073],'armor'=>0.16,'morale'=>72.0],
    'big_vehicle' => ['kind'=>'vehicle_big','base_size'=>1,'base_xpl'=>4.0,'move'=>140.0,'ranged'=>['range'=>320.0,'power'=>0.026,'cap_pct'=>0.10,'acc'=>0.52],'melee'=>['power'=>0.040,'cap_pct'=>0.16],'armor'=>0.36,'morale'=>78.0],
    'wagenburg' => ['kind'=>'wagen','base_size'=>1,'base_xpl'=>7.0,'move'=>25.0,'ranged'=>['range'=>280.0,'power'=>0.035,'cap_pct'=>0.12,'acc'=>0.60],'melee'=>['power'=>0.050,'cap_pct'=>0.16],'armor'=>0.48,'morale'=>90.0,'no_flanks'=>true],
  ];
  return $catalog;
}

function war_battle_token_profiles(): array {
  return [
    'inf' => ['size'=>1.35,'spacing'=>3.0], 'pike' => ['size'=>1.35,'spacing'=>3.0], 'shot' => ['size'=>1.35,'spacing'=>3.0],
    'support' => ['size'=>1.35,'spacing'=>4.0], 'elite' => ['size'=>1.45,'spacing'=>3.0], 'cav' => ['size'=>2.0,'spacing'=>5.0],
    'heavycav' => ['size'=>2.1,'spacing'=>6.0], 'gun' => ['size'=>3.8,'spacing'=>12.0], 'siege' => ['size'=>4.6,'spacing'=>14.0],
    'vehicle' => ['size'=>4.8,'spacing'=>14.0], 'vehicle_big' => ['size'=>5.6,'spacing'=>16.0], 'wagen' => ['size'=>6.2,'spacing'=>18.0],
  ];
}

function war_battle_catalog_row(string $unitId): array {
  $cat = war_battle_catalog();
  return $cat[$unitId] ?? ['kind'=>'inf','base_size'=>100,'base_xpl'=>1.0,'move'=>90.0,'melee'=>['power'=>0.02,'cap_pct'=>0.12],'armor'=>0.08,'morale'=>60.0];
}

function war_battle_layout_collision_radius(string $formation, int $men, string $kind): float {
  $profiles = war_battle_token_profiles();
  $prof = $profiles[$kind] ?? $profiles['inf'];
  $cell = (float)$prof['spacing'];
  $maxTokens = max(1, $men);
  if ($formation === 'line') {
    $cols = max(2, (int)ceil(sqrt((float)$maxTokens) * 1.6));
    $rows = (int)ceil($maxTokens / max(1, $cols));
    return max($cols * $cell, $rows * $cell) * 0.55;
  }
  if ($formation === 'sleeve') {
    $rows = 6;
    $cols = (int)ceil($maxTokens / $rows);
    return max($cols * $cell, $rows * $cell) * 0.55;
  }
  if ($formation === 'block') {
    $side = (int)ceil(sqrt((float)$maxTokens));
    $rows = (int)ceil($maxTokens / max(1, $side));
    return max($side * $cell, $rows * $cell) * 0.55;
  }
  if ($formation === 'wedge') {
    $rows = (int)ceil(sqrt((float)$maxTokens));
    $maxCols = max(1, ($rows * 2) - 1);
    return max($rows * $cell, $maxCols * $cell) * 0.55;
  }
  if ($formation === 'chatillon') {
    $targetArea = $maxTokens * ($cell * $cell);
    return max(18.0, sqrt($targetArea / M_PI));
  }
  return 28.0;
}

function war_battle_default_stats_for_unit(string $unitId): array {
  $tpl = war_battle_catalog_row($unitId);
  return [
    'kind' => (string)($tpl['kind'] ?? 'inf'),
    'base_size' => max(1, (int)($tpl['base_size'] ?? 100)),
    'base_xpl' => max(0.01, (float)($tpl['base_xpl'] ?? 1.0)),
    'move' => max(0.0, (float)($tpl['move'] ?? 90.0)),
    'ranged' => is_array($tpl['ranged'] ?? null) ? [
      'range' => max(1.0, (float)($tpl['ranged']['range'] ?? 1.0)),
      'power' => max(0.0, (float)($tpl['ranged']['power'] ?? 0.0)),
      'cap_pct' => max(0.0, (float)($tpl['ranged']['cap_pct'] ?? 0.0)),
      'acc' => war_battle_clamp((float)($tpl['ranged']['acc'] ?? 0.5), 0.01, 0.99),
    ] : null,
    'melee' => [
      'power' => max(0.0, (float)($tpl['melee']['power'] ?? 0.02)),
      'cap_pct' => max(0.0, (float)($tpl['melee']['cap_pct'] ?? 0.12)),
    ],
    'armor' => war_battle_clamp((float)($tpl['armor'] ?? 0.0), 0.0, 0.9),
    'morale' => war_battle_clamp((float)($tpl['morale'] ?? 60.0), 1.0, 100.0),
    'tags' => is_array($tpl['tags'] ?? null) ? array_values($tpl['tags']) : [],
    'no_flanks' => !empty($tpl['no_flanks']),
  ];
}

function war_battle_xpl_per_man(array $u): float {
  $baseSize = max(1.0, (float)($u['base_size'] ?? 100.0));
  $baseXpl = max(0.01, (float)($u['base_xpl'] ?? 1.0));
  return $baseXpl / $baseSize;
}

function war_battle_xpl_to_size_loss(array $target, float $xplDamage): int {
  $ppm = war_battle_xpl_per_man($target);
  if ($ppm <= 0.0 || $xplDamage <= 0.0) return 0;
  $frac = $xplDamage / $ppm;
  if ($frac <= 0.0) return 0;
  $loss = (int)floor($frac);
  $rem = $frac - (float)$loss;
  if ($rem > 0.0 && mt_rand(1, 1000000) <= (int)floor($rem * 1000000.0)) $loss++;
  return max(1, $loss);
}

function war_battle_formation_no_flanks(array $u): bool {
  return ((string)($u['formation'] ?? 'line') === 'chatillon') || !empty($u['no_flanks']);
}

function war_battle_flank_type(array $def, array $atk): string {
  if (war_battle_formation_no_flanks($def)) return 'front';
  $a = (float)($def['angle'] ?? 0.0);
  $fvx = sin($a); $fvy = -cos($a);
  $vx = (float)($atk['x'] ?? 0.0) - (float)($def['x'] ?? 0.0);
  $vy = (float)($atk['y'] ?? 0.0) - (float)($def['y'] ?? 0.0);
  $len = sqrt($vx*$vx + $vy*$vy);
  if ($len <= 1e-9) return 'front';
  $dot = war_battle_clamp(($fvx * ($vx/$len)) + ($fvy * ($vy/$len)), -1.0, 1.0);
  $deg = rad2deg(acos($dot));
  if ($deg > 130.0) return 'rear';
  if ($deg > 70.0) return 'flank';
  return 'front';
}

function war_battle_side_to_color(string $side): string {
  return $side === 'A' ? 'blue' : 'red';
}

function war_battle_color_to_side(string $color): string {
  return $color === 'red' ? 'B' : 'A';
}

function war_battle_unit_runtime_profile(array $u): array {
  $unitId = (string)($u['unit_id'] ?? '');
  return war_battle_default_stats_for_unit($unitId);
}

function war_battle_default_realtime_units(array $battle, array $state): array {
  $units = [];
  foreach (['A','B'] as $side) {
    $color = war_battle_side_to_color($side);
    $armies = war_battle_armies_for_side($state, $battle, $side);
    $pending = [];
    foreach ($armies as $army) {
      if (!is_array($army)) continue;
      $armyUid = (string)($army['army_uid'] ?? '');
      $armyUnits = (array)($army['units'] ?? []);
      foreach (array_values($armyUnits) as $idx => $u) {
        if (!is_array($u)) continue;
        $size = max(0, (int)($u['size'] ?? 0));
        if ($size <= 0) continue;
        $pending[] = [
          'army_uid' => $armyUid,
          'unit_idx' => (int)$idx,
          'unit' => $u,
          'size' => $size,
        ];
      }
    }

    $total = count($pending);
    if ($total <= 0) continue;

    $mapHalfW = 2200.0 / 2.0;
    $mapHalfH = 1400.0 / 2.0;
    $third = (2.0 * $mapHalfH) / 3.0;
    $bandYMin = $color === 'blue' ? -$mapHalfH : ($mapHalfH - $third);
    $bandYMax = $color === 'blue' ? (-$mapHalfH + $third) : $mapHalfH;

    $xPad = 90.0;
    $yPad = 55.0;
    $xMin = -$mapHalfW + $xPad;
    $xMax = $mapHalfW - $xPad;
    $xSpan = max(60.0, $xMax - $xMin);
    $yMin = $bandYMin + $yPad;
    $yMax = $bandYMax - $yPad;
    $ySpan = max(45.0, $yMax - $yMin);

    $maxCols = max(1, min(18, $total));
    $idealCols = (int)ceil(sqrt($total * 2.2));
    $cols = max(1, min($maxCols, $idealCols));
    $rows = max(1, (int)ceil($total / $cols));
    while ($rows > 1 && ($ySpan / max(1, $rows - 1)) < 44.0 && $cols < $maxCols) {
      $cols++;
      $rows = max(1, (int)ceil($total / $cols));
    }

    $xStep = $cols > 1 ? ($xSpan / ($cols - 1)) : 0.0;
    $yStep = $rows > 1 ? ($ySpan / ($rows - 1)) : 0.0;
    $angle = ($color === 'blue') ? 0.0 : 3.141592653589793;

    foreach ($pending as $n => $row) {
      $u = (array)$row['unit'];
      $size = (int)$row['size'];
      $uid = (string)$row['army_uid'] . '#' . (string)$row['unit_idx'];
      $unitId = (string)($u['unit_id'] ?? '');
      $prof = war_battle_unit_runtime_profile($u);
      $formation = 'line';
      $collisionR = war_battle_layout_collision_radius($formation, $size, (string)($prof['kind'] ?? 'inf'));

      $col = $n % $cols;
      $rowIdx = (int)floor($n / $cols);
      if (($rowIdx % 2) === 1) $col = ($cols - 1) - $col;
      $x = $xMin + ($col * $xStep);
      $y = $yMin + ($rowIdx * $yStep);
      $y = war_battle_clamp($y, $bandYMin + 6.0, $bandYMax - 6.0);

      $units[] = [
        'uid' => $uid,
        'army_uid' => (string)$row['army_uid'],
        'unit_idx' => (int)$row['unit_idx'],
        'unit_id' => $unitId,
        'source' => (string)($u['source'] ?? ''),
        'side' => $color,
        'formation' => $formation,
        'kind' => (string)($prof['kind'] ?? 'inf'),
        'men' => $size,
        'base_size' => max(1, (int)($prof['base_size'] ?? $size)),
        'base_xpl' => max(0.01, (float)($prof['base_xpl'] ?? 1.0)),
        'x' => $x,
        'y' => $y,
        'angle' => $angle,
        'morale_base' => (float)($prof['morale'] ?? 60.0),
        'morale' => (float)($prof['morale'] ?? 60.0),
        'state' => 'ready',
        'move_range' => (float)($prof['move'] ?? 0.0),
        'ranged' => is_array($prof['ranged'] ?? null) ? $prof['ranged'] : null,
        'melee' => is_array($prof['melee'] ?? null) ? $prof['melee'] : ['power'=>0.02,'cap_pct'=>0.12],
        'armor' => (float)($prof['armor'] ?? 0.0),
        'tags' => is_array($prof['tags'] ?? null) ? $prof['tags'] : [],
        'no_flanks' => !empty($prof['no_flanks']),
        'collision_r' => $collisionR,
        'fired_turn' => 0,
        'moved_turn' => 0,
        'losses' => 0,
        'start_size_start_battle' => $size,
      ];
    }
  }
  return $units;
}


function war_battle_normalize_realtime_unit(array $u): array {
  $unitId = (string)($u['unit_id'] ?? '');
  $stats = war_battle_default_stats_for_unit($unitId);
  $kind = (string)($u['kind'] ?? $stats['kind']);
  $formation = (string)($u['formation'] ?? 'line');
  $men = max(0, (int)($u['men'] ?? 0));
  $u['kind'] = $kind;
  $u['base_size'] = max(1, (int)($u['base_size'] ?? $u['baseSize'] ?? $stats['base_size']));
  $u['base_xpl'] = max(0.01, (float)($u['base_xpl'] ?? $u['baseXpl'] ?? $stats['base_xpl']));
  $u['move_range'] = max(0.0, (float)($u['move_range'] ?? $stats['move']));
  $u['melee'] = is_array($u['melee'] ?? null) ? $u['melee'] : $stats['melee'];
  $u['ranged'] = is_array($u['ranged'] ?? null) ? $u['ranged'] : $stats['ranged'];
  $u['armor'] = (float)($u['armor'] ?? $stats['armor']);
  $u['tags'] = is_array($u['tags'] ?? null) ? array_values($u['tags']) : $stats['tags'];
  $u['no_flanks'] = !empty($u['no_flanks']) || !empty($stats['no_flanks']);
  $u['morale_base'] = (float)($u['morale_base'] ?? $u['moraleBase'] ?? $stats['morale']);
  $u['morale'] = (float)($u['morale'] ?? $u['morale_base']);
  $u['fired_turn'] = (int)($u['fired_turn'] ?? 0);
  $u['moved_turn'] = (int)($u['moved_turn'] ?? 0);
  $u['losses'] = max(0, (int)($u['losses'] ?? 0));
  $u['start_size_start_battle'] = max(1, (int)($u['start_size_start_battle'] ?? $men ?: 1));
  $u['collision_r'] = (float)($u['collision_r'] ?? war_battle_layout_collision_radius($formation, max(1, $men), $kind));
  $u['baseSize'] = (int)$u['base_size'];
  $u['baseXpl'] = (float)$u['base_xpl'];
  return $u;
}

function war_battle_ensure_realtime(array &$battle, array $state): void {
  if (!is_array($battle['realtime'] ?? null)) $battle['realtime'] = [];
  $rt = (array)$battle['realtime'];
  if (!is_array($rt['state'] ?? null)) {
    $rt['state'] = [
      'rev' => 1,
      'turn' => 1,
      'phase' => 'setup',
      'active_side' => 'A',
      'submitted' => ['A' => false, 'B' => false],
      'units' => war_battle_default_realtime_units($battle, $state),
      'updated_at' => time(),
    ];
  }
  $cur = (array)($rt['state'] ?? []);
  $curUnits = array_values(array_filter((array)($cur['units'] ?? []), static fn($u) => is_array($u)));
  foreach ($curUnits as $k => $u) $curUnits[$k] = war_battle_normalize_realtime_unit($u);
  $cur['units'] = $curUnits;
  if (!isset($cur['turn'])) $cur['turn'] = 1;
  if (!isset($cur['phase'])) $cur['phase'] = 'movement';
  if (!isset($cur['active_side'])) $cur['active_side'] = 'A';
  if (!is_array($cur['submitted'] ?? null)) $cur['submitted'] = ['A' => false, 'B' => false];
  $cur['submitted'] = ['A' => !empty($cur['submitted']['A']), 'B' => !empty($cur['submitted']['B'])];
  if (!isset($cur['rev'])) $cur['rev'] = 1;
  if (!isset($cur['updated_at'])) $cur['updated_at'] = time();
  $rt['state'] = $cur;
  if (!is_array($rt['history'] ?? null)) $rt['history'] = [];
  $battle['realtime'] = $rt;
}


function war_battle_recreate_from_scratch(array &$battle, array $state, string $side = ''): void {
  $now = time();
  $battleId = (string)($battle['battle_id'] ?? '');
  $provincePid = (int)($battle['province_pid'] ?? 0);
  $sides = is_array($battle['sides'] ?? null) ? $battle['sides'] : ['A' => ['army_uids' => []], 'B' => ['army_uids' => []]];
  $tokens = is_array($battle['tokens'] ?? null) ? $battle['tokens'] : ['A' => '', 'B' => ''];
  $prevRev = (int)($battle['realtime']['state']['rev'] ?? 0);

  $existingLog = array_values(array_filter((array)($battle['log'] ?? []), static fn($row) => is_array($row)));
  $existingLog[] = ['at' => $now, 'event' => 'battle_recreated_from_scratch', 'side' => $side];
  $prevHistory = array_values(array_filter((array)($battle['realtime']['history'] ?? []), static fn($row) => is_array($row)));

  $battle = [
    'battle_id' => $battleId,
    'province_pid' => $provincePid,
    'created_at' => $now,
    'auto_resolve_at' => $now + 2 * 24 * 60 * 60,
    'status' => 'setup',
    'sides' => $sides,
    'ready' => ['A' => false, 'B' => false],
    'tokens' => $tokens,
    'log' => $existingLog,
    'realtime' => [
      'state' => [
        'rev' => max(1, $prevRev + 1),
        'turn' => 1,
        'phase' => 'setup',
        'active_side' => 'A',
        'submitted' => ['A' => false, 'B' => false],
        'units' => war_battle_default_realtime_units(['sides' => $sides], $state),
        'updated_at' => $now,
      ],
      'history' => $prevHistory,
    ],
  ];
}

function war_battle_restart(array &$battle, array $state, string $side = ''): void {
  $now = time();
  $prevRev = (int)($battle['realtime']['state']['rev'] ?? 0);
  $battle['ready'] = ['A' => false, 'B' => false];
  $battle['status'] = 'setup';
  $battle['auto_resolved'] = false;
  unset($battle['auto_resolve_result'], $battle['finished_at'], $battle['winner']);
  $battle['auto_resolve_at'] = $now + 2 * 24 * 60 * 60;
  $prevHistory = array_values(array_filter((array)($battle['realtime']['history'] ?? []), static fn($row) => is_array($row)));
  $battle['realtime'] = [
    'state' => [
      'rev' => max(1, $prevRev + 1),
      'turn' => 1,
      'phase' => 'setup',
      'active_side' => 'A',
      'submitted' => ['A' => false, 'B' => false],
      'units' => war_battle_default_realtime_units($battle, $state),
      'updated_at' => $now,
    ],
    'history' => $prevHistory,
  ];
  if (!is_array($battle['log'] ?? null)) $battle['log'] = [];
  $battle['log'][] = ['at' => $now, 'event' => 'battle_restarted', 'side' => $side];
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
  $turn = max(1, (int)($state['turn'] ?? 1));

  if (in_array($type, ['retreat', 'escape', 'surrender'], true)) {
    foreach ($units as $k => $u) {
      if (war_battle_color_to_side((string)($u['side'] ?? 'blue')) !== $side) continue;
      if (in_array((string)($u['state'] ?? 'ready'), ['destroyed', 'routed'], true)) continue;
      $men = max(0, (int)($u['men'] ?? 0));
      if ($men > 0) $u['losses'] = (int)($u['losses'] ?? 0) + $men;
      $u['men'] = 0;
      $u['state'] = 'routed';
      $u['collision_r'] = war_battle_layout_collision_radius((string)($u['formation'] ?? 'line'), 0, (string)($u['kind'] ?? 'inf'));
      $units[$k] = $u;
    }
    $state['units'] = $units;
    return null;
  }

  if (in_array($type, ['advance_phase', 'submit_turn', 'end_turn'], true)) {
    if (!($phase === 'setup') && $side !== $active) return 'not_active_side';
    if (!is_array($state['submitted'] ?? null)) $state['submitted'] = ['A' => false, 'B' => false];
    $submitted = (array)$state['submitted'];
    $submitted['A'] = !empty($submitted['A']);
    $submitted['B'] = !empty($submitted['B']);
    $submitted[$side] = true;
    $state['submitted'] = $submitted;

    if ($phase === 'setup' && !($submitted['A'] && $submitted['B'])) {
      return null;
    }

    if ($phase === 'melee') {
      $pairs = [];
      $n = count($units);
      for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
          $a = $units[$i];
          $b = $units[$j];
          if ((string)($a['side'] ?? '') === (string)($b['side'] ?? '')) continue;
          if (in_array((string)($a['state'] ?? 'ready'), ['destroyed','routed'], true)) continue;
          if (in_array((string)($b['state'] ?? 'ready'), ['destroyed','routed'], true)) continue;
          $dx = (float)($a['x'] ?? 0.0) - (float)($b['x'] ?? 0.0);
          $dy = (float)($a['y'] ?? 0.0) - (float)($b['y'] ?? 0.0);
          $sumR = (float)($a['collision_r'] ?? 28.0) + (float)($b['collision_r'] ?? 28.0);
          if (($dx*$dx + $dy*$dy) <= (($sumR * 1.05) ** 2)) $pairs[] = [$i, $j];
        }
      }
      foreach ($pairs as [$ia, $ib]) {
        $a = $units[$ia];
        $b = $units[$ib];
        if ((int)($a['men'] ?? 0) <= 0 || (int)($b['men'] ?? 0) <= 0) continue;
        $fa = war_battle_flank_type($a, $b);
        $fb = war_battle_flank_type($b, $a);

        $aMul = ($fa === 'rear') ? 1.45 : (($fa === 'flank') ? 1.25 : 1.0);
        $bMul = ($fb === 'rear') ? 1.45 : (($fb === 'flank') ? 1.25 : 1.0);
        $aKind = (string)($a['kind'] ?? 'inf');
        $bKind = (string)($b['kind'] ?? 'inf');
        if (in_array('antiCav', (array)($a['tags'] ?? []), true) && in_array($bKind, ['cav','heavycav'], true)) $aMul *= 1.25;
        if (in_array('antiCav', (array)($b['tags'] ?? []), true) && in_array($aKind, ['cav','heavycav'], true)) $bMul *= 1.25;

        $engagedA = max(0, min((int)($a['men'] ?? 0), (int)round((int)($a['men'] ?? 0) * 0.45)));
        $engagedB = max(0, min((int)($b['men'] ?? 0), (int)round((int)($b['men'] ?? 0) * 0.45)));
        if ($engagedA <= 0 && $engagedB <= 0) continue;

        $aMor = war_battle_clamp(((float)($a['morale'] ?? 60.0)) / 100.0, 0.4, 1.15);
        $bMor = war_battle_clamp(((float)($b['morale'] ?? 60.0)) / 100.0, 0.4, 1.15);
        $aMelee = is_array($a['melee'] ?? null) ? $a['melee'] : ['power'=>0.02,'cap_pct'=>0.12];
        $bMelee = is_array($b['melee'] ?? null) ? $b['melee'] : ['power'=>0.02,'cap_pct'=>0.12];

        $xplToB = $engagedA * war_battle_xpl_per_man($a) * max(0.0, (float)($aMelee['power'] ?? 0.02)) * (mt_rand(85,125)/100) * $aMul * $aMor;
        $xplToA = $engagedB * war_battle_xpl_per_man($b) * max(0.0, (float)($bMelee['power'] ?? 0.02)) * (mt_rand(85,125)/100) * $bMul * $bMor;
        $xplToB *= (1.0 - war_battle_clamp((float)($b['armor'] ?? 0.0) * 0.6, 0.0, 0.9));
        $xplToA *= (1.0 - war_battle_clamp((float)($a['armor'] ?? 0.0) * 0.6, 0.0, 0.9));

        $lossB = war_battle_xpl_to_size_loss($b, $xplToB);
        $lossA = war_battle_xpl_to_size_loss($a, $xplToA);
        $capB = max(1, (int)floor((int)($b['men'] ?? 0) * max(0.0, (float)($aMelee['cap_pct'] ?? 0.18))));
        $capA = max(1, (int)floor((int)($a['men'] ?? 0) * max(0.0, (float)($bMelee['cap_pct'] ?? 0.18))));
        $lossB = min($lossB, $capB, max(0, (int)($b['men'] ?? 0)));
        $lossA = min($lossA, $capA, max(0, (int)($a['men'] ?? 0)));

        $a['men'] = max(0, (int)($a['men'] ?? 0) - $lossA);
        $b['men'] = max(0, (int)($b['men'] ?? 0) - $lossB);
        $a['losses'] = (int)($a['losses'] ?? 0) + $lossA;
        $b['losses'] = (int)($b['losses'] ?? 0) + $lossB;
        $aShock = ($fb === 'rear') ? 80.0 : (($fb === 'flank') ? 65.0 : 55.0);
        $bShock = ($fa === 'rear') ? 80.0 : (($fa === 'flank') ? 65.0 : 55.0);
        if ($lossA > 0) $a['morale'] = war_battle_clamp((float)($a['morale'] ?? 60.0) - (($lossA / max(1, $lossA + (int)$a['men'])) * $aShock), 0.0, 100.0);
        if ($lossB > 0) $b['morale'] = war_battle_clamp((float)($b['morale'] ?? 60.0) - (($lossB / max(1, $lossB + (int)$b['men'])) * $bShock), 0.0, 100.0);
        if ((int)$a['men'] <= 0) $a['state'] = 'destroyed';
        if ((int)$b['men'] <= 0) $b['state'] = 'destroyed';
        $a['collision_r'] = war_battle_layout_collision_radius((string)($a['formation'] ?? 'line'), (int)$a['men'], (string)($a['kind'] ?? 'inf'));
        $b['collision_r'] = war_battle_layout_collision_radius((string)($b['formation'] ?? 'line'), (int)$b['men'], (string)($b['kind'] ?? 'inf'));
        $units[$ia] = $a;
        $units[$ib] = $b;
      }
    } elseif ($phase === 'morale') {
      foreach ($units as $k => $u) {
        if (in_array((string)($u['state'] ?? 'ready'), ['destroyed','routed'], true)) continue;
        $start = max(1, (int)($u['start_size_start_battle'] ?? (int)($u['men'] ?? 1)));
        $lossPct = ((int)($u['losses'] ?? 0)) / $start;
        $morale = (float)($u['morale'] ?? 60.0);
        if ($lossPct > 0.15) $morale -= 1.5;
        $discipline = war_battle_clamp(((float)($u['morale_base'] ?? 60.0)) / 100.0, 0.01, 1.0);
        $threshold = war_battle_clamp(28.0 + ($discipline * 10.0), 20.0, 45.0);
        if ($morale < $threshold) {
          $chance = war_battle_clamp(($threshold - $morale) * 2.2, 5.0, 65.0);
          if (mt_rand(1, 100) <= (int)round($chance)) $u['state'] = 'routed';
        }
        $u['morale'] = war_battle_clamp($morale, 0.0, 100.0);
        $units[$k] = $u;
      }
    }

    $order = ['setup','movement','ranged','melee','morale'];
    $pidx = array_search($phase, $order, true);
    if (!is_int($pidx)) $pidx = 0;
    if ($pidx < count($order) - 1) {
      $state['phase'] = $order[$pidx + 1];
    } else {
      $state['phase'] = 'movement';
      $state['active_side'] = $active === 'A' ? 'B' : 'A';
      if ($state['active_side'] === 'A') {
        $state['turn'] = $turn + 1;
        foreach ($units as $k => $u) {
          $u['fired_turn'] = 0;
          $u['moved_turn'] = 0;
          $units[$k] = $u;
        }
      }
    }
    $state['submitted'] = ['A' => false, 'B' => false];
    $state['units'] = $units;
    return null;
  }

  $uid = trim((string)($action['uid'] ?? ''));
  if ($uid === '' || !isset($idx[$uid])) return 'unit_not_found';
  $i = $idx[$uid];
  $u = $units[$i];
  if (war_battle_color_to_side((string)($u['side'] ?? 'blue')) !== $side) return 'unit_not_owned';
  if (in_array((string)($u['state'] ?? 'ready'), ['destroyed','routed'], true)) return 'unit_inactive';

  if ($type === 'move') {
    if (!in_array($phase, ['movement','setup'], true)) return 'phase_forbidden';
    if ($phase !== 'setup' && $side !== $active) return 'not_active_side';
    if ($phase !== 'setup' && (int)($u['moved_turn'] ?? 0) === $turn) return 'already_moved';
    $nx = (float)($action['x'] ?? $u['x'] ?? 0);
    $ny = (float)($action['y'] ?? $u['y'] ?? 0);
    $dx = $nx - (float)($u['x'] ?? 0);
    $dy = $ny - (float)($u['y'] ?? 0);
    $dist = sqrt($dx*$dx + $dy*$dy);
    $max = (float)($u['move_range'] ?? 100.0);
    $kind = strtolower((string)($u['kind'] ?? ''));
    // In the client simulator cavalry movement uses an x2 tactical range multiplier.
    // Keep server validation in sync to avoid false `move_too_far` on token sessions.
    if (in_array($kind, ['cav', 'heavycav'], true)) {
      $max *= 2.0;
    }
    if ($phase === 'setup') {
      if (!war_battle_validate_setup_band($u, $ny)) return 'setup_band_forbidden';
      $max = max($max, 99999.0);
    }
    if ($dist > $max + 0.001) return 'move_too_far';

    $newR = war_battle_layout_collision_radius((string)($u['formation'] ?? 'line'), max(1, (int)($u['men'] ?? 1)), (string)($u['kind'] ?? 'inf'));

    $u['x'] = $nx; $u['y'] = $ny;
    $u['angle'] = (float)($action['angle'] ?? $u['angle'] ?? 0);
    $requestedFormation = strtolower(trim((string)($action['formation'] ?? '')));
    if ($requestedFormation !== '') {
      $allowedFormations = ['line','block','wedge','sleeve','chatillon'];
      if (!in_array($requestedFormation, $allowedFormations, true)) return 'invalid_formation';
      $u['formation'] = $requestedFormation;
      $newR = war_battle_layout_collision_radius((string)$u['formation'], max(1, (int)($u['men'] ?? 1)), (string)($u['kind'] ?? 'inf'));
    }
    if ($phase !== 'setup') $u['moved_turn'] = $turn;
    $u['collision_r'] = $newR;
    $units[$i] = $u; $state['units'] = $units;
    return null;
  }

  if ($side !== $active) return 'not_active_side';

  if ($type === 'ranged_attack' || $type === 'melee_attack') {
    $required = ($type === 'ranged_attack') ? 'ranged' : 'melee';
    if ($phase !== $required) return 'phase_forbidden';
    if ($type === 'ranged_attack' && (int)($u['fired_turn'] ?? 0) === $turn) return 'already_fired';
    $targetUid = trim((string)($action['target_uid'] ?? ''));
    if ($targetUid === '' || !isset($idx[$targetUid])) return 'target_not_found';
    $ti = $idx[$targetUid];
    $t = $units[$ti];
    if ((string)($t['side'] ?? '') === (string)($u['side'] ?? '')) return 'friendly_target';
    if (in_array((string)($t['state'] ?? 'ready'), ['destroyed','routed'], true)) return 'target_inactive';
    $dx = (float)($u['x'] ?? 0) - (float)($t['x'] ?? 0);
    $dy = (float)($u['y'] ?? 0) - (float)($t['y'] ?? 0);
    $dist = sqrt($dx*$dx + $dy*$dy);
    $range = 85.0;
    if ($type === 'ranged_attack') {
      $ranged = is_array($u['ranged'] ?? null) ? $u['ranged'] : null;
      if (!$ranged) return 'no_ranged';
      $range = (float)($ranged['range'] ?? 120.0);
    } else {
      $range = ((float)($u['collision_r'] ?? 28.0) + (float)($t['collision_r'] ?? 28.0)) * 1.05;
    }
    if ($dist > $range + 0.001) return 'target_out_of_range';

    if ($type === 'ranged_attack') {
      $ranged = is_array($u['ranged'] ?? null) ? $u['ranged'] : [];
      $acc = war_battle_clamp((float)($ranged['acc'] ?? 0.5) + (((float)($u['morale'] ?? 60.0)-50.0)/200.0), 0.05, 0.95);
      $u['fired_turn'] = $turn;
      if ((mt_rand(1, 1000) / 1000.0) > $acc) {
        $t['morale'] = war_battle_clamp((float)($t['morale'] ?? 60.0) - 2.0, 0.0, 100.0);
        $units[$i] = $u; $units[$ti] = $t; $state['units'] = $units;
        return null;
      }
      $shootingModels = max(1, (int)round(max(1, (int)($u['men'] ?? 0)) * 0.35));
      $xplDmg = ($shootingModels * $acc) * war_battle_xpl_per_man($u) * max(0.0, (float)($ranged['power'] ?? 0.01)) * (mt_rand(80,120)/100);
      $xplDmg *= (1.0 - war_battle_clamp((float)($t['armor'] ?? 0.0) * 0.6, 0.0, 0.9));
      $loss = war_battle_xpl_to_size_loss($t, $xplDmg);
      $cap = max(1, (int)floor((int)($t['men'] ?? 0) * max(0.0, (float)($ranged['cap_pct'] ?? 0.08))));
      $loss = min($loss, $cap, max(0, (int)($t['men'] ?? 0)));
      $t['men'] = max(0, (int)($t['men'] ?? 0) - $loss);
      if ($loss > 0) {
        $lossPct = $loss / max(1, $loss + (int)$t['men']);
        $shock = 35.0 + (max(0.0, (float)($ranged['cap_pct'] ?? 0.08)) * 220.0);
        $t['morale'] = war_battle_clamp((float)($t['morale'] ?? 60.0) - ($lossPct * $shock), 0.0, 100.0);
        $t['losses'] = (int)($t['losses'] ?? 0) + $loss;
      }
    } else {
      $melee = is_array($u['melee'] ?? null) ? $u['melee'] : ['power'=>0.02,'cap_pct'=>0.12];
      $xplDmg = max(1, (int)($u['men'] ?? 0)) * war_battle_xpl_per_man($u) * max(0.0, (float)($melee['power'] ?? 0.02)) * (mt_rand(85,125)/100);
      $xplDmg *= (1.0 - war_battle_clamp((float)($t['armor'] ?? 0.0) * 0.6, 0.0, 0.9));
      $loss = war_battle_xpl_to_size_loss($t, $xplDmg);
      $cap = max(1, (int)floor((int)($t['men'] ?? 0) * max(0.0, (float)($melee['cap_pct'] ?? 0.18))));
      $loss = min($loss, $cap, max(0, (int)($t['men'] ?? 0)));
      $t['men'] = max(0, (int)($t['men'] ?? 0) - $loss);
      if ($loss > 0) {
        $lossPct = $loss / max(1, $loss + (int)$t['men']);
        $t['morale'] = war_battle_clamp((float)($t['morale'] ?? 60.0) - ($lossPct * 55.0), 0.0, 100.0);
        $t['losses'] = (int)($t['losses'] ?? 0) + $loss;
      }
    }

    if ((int)$t['men'] <= 0) $t['state'] = 'destroyed';
    $t['collision_r'] = war_battle_layout_collision_radius((string)($t['formation'] ?? 'line'), max(0, (int)$t['men']), (string)($t['kind'] ?? 'inf'));
    $units[$i] = $u; $units[$ti] = $t; $state['units'] = $units;
    return null;
  }

  return 'unknown_action_type';
}

function war_battle_realtime_pick_winner(array $units): ?string {
  $alive = ['A' => 0, 'B' => 0];
  foreach ($units as $u) {
    if (!is_array($u)) continue;
    $men = max(0, (int)($u['men'] ?? 0));
    if ($men <= 0) continue;
    if (in_array((string)($u['state'] ?? 'ready'), ['destroyed', 'routed'], true)) continue;
    $uSide = war_battle_color_to_side((string)($u['side'] ?? 'blue'));
    if (!isset($alive[$uSide])) continue;
    $alive[$uSide] += $men;
  }
  if ($alive['A'] > 0 && $alive['B'] <= 0) return 'A';
  if ($alive['B'] > 0 && $alive['A'] <= 0) return 'B';
  if ($alive['A'] <= 0 && $alive['B'] <= 0) return 'draw';
  return null;
}

function war_battle_realtime_build_remaining_units(array $battle): array {
  $rt = (array)($battle['realtime'] ?? []);
  $state = (array)($rt['state'] ?? []);
  $remaining = [];
  foreach ((array)($state['units'] ?? []) as $u) {
    if (!is_array($u)) continue;
    $armyUid = trim((string)($u['army_uid'] ?? ''));
    $idx = (int)($u['unit_idx'] ?? -1);
    if ($armyUid === '' || $idx < 0) continue;

    $men = max(0, (int)($u['men'] ?? 0));
    $uState = (string)($u['state'] ?? 'ready');
    if (in_array($uState, ['destroyed', 'routed'], true)) $men = 0;

    $remaining[$armyUid . '#' . (string)$idx] = $men;
  }
  ksort($remaining);
  return $remaining;
}


function war_battle_realtime_side_men_totals(array $units): array {
  $totals = ['A' => 0, 'B' => 0];
  foreach ($units as $u) {
    if (!is_array($u)) continue;
    $side = war_battle_color_to_side((string)($u['side'] ?? ''));
    if (!in_array($side, ['A', 'B'], true)) continue;
    $totals[$side] += max(0, (int)($u['men'] ?? 0));
  }
  return $totals;
}

function war_battle_realtime_action_outcome(array $beforeUnits, array $afterUnits, array $action): array {
  $before = war_battle_realtime_side_men_totals($beforeUnits);
  $after = war_battle_realtime_side_men_totals($afterUnits);
  return [
    'type' => (string)($action['type'] ?? ''),
    'uid' => (string)($action['uid'] ?? ''),
    'target_uid' => (string)($action['target_uid'] ?? ''),
    'men_before' => $before,
    'men_after' => $after,
    'losses' => [
      'A' => max(0, (int)$before['A'] - (int)$after['A']),
      'B' => max(0, (int)$before['B'] - (int)$after['B']),
    ],
  ];
}

function war_battle_try_finalize_realtime(array &$battle, array &$state): bool {
  if (in_array((string)($battle['status'] ?? ''), ['finished', 'auto_resolved'], true)) return false;
  $rt = (array)($battle['realtime'] ?? []);
  $rtState = (array)($rt['state'] ?? []);
  $winner = war_battle_realtime_pick_winner((array)($rtState['units'] ?? []));
  if ($winner === null) return false;

  $remainingUnits = war_battle_realtime_build_remaining_units($battle);
  $battle = war_battle_finalize_manual($battle, $state, $remainingUnits, $winner);
  if (!is_array($battle['log'] ?? null)) $battle['log'] = [];
  $battle['log'][] = ['at' => time(), 'event' => 'battle_finished_realtime_auto', 'winner' => $winner];
  return true;
}

function war_battle_realtime_commit(array &$battle, array &$state, string $side, array $payload): array {
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
  $actionResults = [];
  foreach ($actions as $idx => $action) {
    $beforeUnits = array_values((array)($tmp['units'] ?? []));
    $err = war_battle_apply_action_to_state($tmp, $side, $action);
    if ($err !== null) {
      return ['error' => 'invalid_action', 'action_index' => $idx, 'reason' => $err, 'battle' => $battle];
    }
    $afterUnits = array_values((array)($tmp['units'] ?? []));
    $actionResults[] = war_battle_realtime_action_outcome($beforeUnits, $afterUnits, $action);
  }

  $tmp['rev'] = $curRev + 1;
  $tmp['updated_at'] = time();
  $rt['state'] = $tmp;
  $totBefore = war_battle_realtime_side_men_totals((array)($cur['units'] ?? []));
  $totAfter = war_battle_realtime_side_men_totals((array)($tmp['units'] ?? []));
  $lossTotals = [
    'A' => max(0, (int)$totBefore['A'] - (int)$totAfter['A']),
    'B' => max(0, (int)$totBefore['B'] - (int)$totAfter['B']),
  ];
  $entry = [
    'rev' => (int)$tmp['rev'],
    'at' => time(),
    'side' => $side,
    'actions' => $actions,
    'results' => $actionResults,
    'loss_totals' => $lossTotals,
  ];
  $hist = array_values(array_filter((array)($rt['history'] ?? []), static fn($h) => is_array($h)));
  $hist[] = $entry;
  $rt['history'] = $hist;
  $battle['realtime'] = $rt;

  if (!is_array($battle['log'] ?? null)) $battle['log'] = [];
  $battle['log'][] = [
    'at' => time(),
    'event' => 'realtime_action_commit',
    'side' => $side,
    'rev' => (int)$tmp['rev'],
    'actions' => count($actions),
    'loss_totals' => $lossTotals,
    'results' => $actionResults,
  ];

  $autoFinished = war_battle_try_finalize_realtime($battle, $state);

  return ['ok' => true, 'battle' => $battle, 'auto_finished' => $autoFinished];
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
