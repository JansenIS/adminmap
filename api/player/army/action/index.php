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

$entityType = (string)$session['entity']['type'];
$entityId = (string)$session['entity']['id'];
$owned = array_flip($session['owned_pids']);
$realm =& $state[$entityType][$entityId];

if (!is_array($realm['arrierban_units'] ?? null)) $realm['arrierban_units'] = [];
if (!is_array($realm['arrierban_vassal_armies'] ?? null)) $realm['arrierban_vassal_armies'] = [];
if (!is_array($realm['arrierban_vassal_units'] ?? null)) $realm['arrierban_vassal_units'] = [];
if (!is_array($state['war_armies'] ?? null)) $state['war_armies'] = [];

$normalizeUnits = static function ($units): array {
  $map = [];
  foreach ((is_array($units) ? $units : []) as $unit) {
    if (!is_array($unit)) continue;
    $unitId = trim((string)($unit['unit_id'] ?? ''));
    $size = max(0, (int)($unit['size'] ?? 0));
    if ($unitId === '' || $size <= 0) continue;
    if (!isset($map[$unitId])) {
      $map[$unitId] = [
        'source' => (string)($unit['source'] ?? ''),
        'unit_id' => $unitId,
        'unit_name' => (string)($unit['unit_name'] ?? $unitId),
        'size' => 0,
        'base_size' => max(1, (int)($unit['base_size'] ?? 1)),
      ];
    }
    $map[$unitId]['size'] += $size;
  }
  return array_values($map);
};

$sanitizeArmy = static function (array $army) use ($normalizeUnits): array {
  $units = $normalizeUnits($army['units'] ?? []);
  $size = 0;
  foreach ($units as $u) $size += (int)$u['size'];
  return [
    'army_id' => trim((string)($army['army_id'] ?? '')),
    'army_name' => trim((string)($army['army_name'] ?? 'Армия')),
    'army_kind' => trim((string)($army['army_kind'] ?? 'vassal')),
    'muster_pid' => (int)($army['muster_pid'] ?? 0),
    'units' => $units,
    'size' => $size,
  ];
};

$collectProvinceIdsForArrierban = static function (string $mode, string $id, array $realmObj) use ($state): array {
  $pids = [];
  if (is_array($realmObj['province_pids'] ?? null)) {
    foreach ($realmObj['province_pids'] as $raw) { $pid = (int)$raw; if ($pid > 0) $pids[$pid] = true; }
  }
  if ($mode === 'kingdoms') return array_keys($pids);

  if ($mode === 'great_houses') {
    $layer = is_array($realmObj['minor_house_layer'] ?? null) ? $realmObj['minor_house_layer'] : null;
    if ($layer) {
      foreach ((is_array($layer['domain_pids'] ?? null) ? $layer['domain_pids'] : []) as $raw) {
        $pid = (int)$raw;
        if ($pid > 0) $pids[$pid] = true;
      }
    }
    foreach (($state['provinces'] ?? []) as $pd) {
      if (!is_array($pd)) continue;
      $pid = (int)($pd['pid'] ?? 0);
      if ($pid <= 0) continue;
      if (trim((string)($pd['great_house_id'] ?? '')) === $id) $pids[$pid] = true;
    }
  }

  if ($mode === 'minor_houses') {
    foreach (($state['provinces'] ?? []) as $pd) {
      if (!is_array($pd)) continue;
      $pid = (int)($pd['pid'] ?? 0);
      if ($pid <= 0) continue;
      if (trim((string)($pd['minor_house_id'] ?? '')) === $id) $pids[$pid] = true;
    }
  }
  return array_keys($pids);
};

$calculateArrierbanForRealm = static function (string $mode, string $id, array $realmObj) use ($state, $collectProvinceIdsForArrierban): array {
  $warlikeCoeff = max(0, min(100, (int)($realmObj['warlike_coeff'] ?? 30)));
  $loyaltyCoeff = max(0, min(100, (int)($realmObj['loyalty_coeff'] ?? 0)));
  $domainPids = $collectProvinceIdsForArrierban($mode, $id, $realmObj);
  $domainHexes = 0;
  $domainPopulation = 0;
  foreach ($domainPids as $pid) {
    $pd = $state['provinces'][(string)$pid] ?? null;
    if (!is_array($pd)) continue;
    $domainPopulation += max(0, (int)($pd['population'] ?? 0));
    $hex = (int)($pd['hex_count'] ?? 0);
    $domainHexes += ($hex > 0 ? $hex : 1);
  }

  $sergeantsPool = (int)floor((((($domainPopulation / 3) * ($warlikeCoeff / 100)) / 50) * 5));
  $pools = [
    'knights' => (int)floor(($domainHexes / 10) * ($warlikeCoeff / 100)),
    'nehts' => (int)floor(((floor($domainHexes / 3) * ($warlikeCoeff / 100) * 10) / 10)),
    'sergeants' => $sergeantsPool,
    'militia' => (int)floor((((max(0, $domainPopulation - $sergeantsPool) * ($warlikeCoeff / 100)) / 50) * 5)),
  ];

  $supportingSources = [];
  $triggerChance = max(0, min(100, $loyaltyCoeff + $warlikeCoeff));
  if ($mode === 'great_houses') {
    $layer = is_array($realmObj['minor_house_layer'] ?? null) ? $realmObj['minor_house_layer'] : null;
    if ($layer && is_array($layer['vassals'] ?? null)) {
      foreach ($layer['vassals'] as $v) {
        if (!is_array($v)) continue;
        if ((mt_rand() / mt_getrandmax()) * 100 >= $triggerChance) continue;
        $vid = trim((string)($v['id'] ?? ''));
        if ($vid === '') continue;
        $musterPid = (int)($v['capital_pid'] ?? 0);
        if ($musterPid <= 0 && is_array($v['province_pids'] ?? null) && count($v['province_pids'])) $musterPid = (int)$v['province_pids'][0];
        $supportingSources[] = ['id' => 'vassal:' . $vid, 'name' => (string)($v['name'] ?? $vid), 'kind' => 'vassal', 'muster_pid' => $musterPid];
      }
    }
  }

  return ['realm' => $realmObj, 'domainPids' => $domainPids, 'pools' => $pools, 'supportingSources' => $supportingSources];
};

$arrierbanDomainUnitDefs = static function (): array {
  return [
    ['source' => 'militia', 'id' => 'militia', 'name' => 'Милиция', 'base_size' => 1],
    ['source' => 'sergeants', 'id' => 'sergeants', 'name' => 'Сержанты', 'base_size' => 1],
    ['source' => 'nehts', 'id' => 'nehts', 'name' => 'Нейты', 'base_size' => 1],
    ['source' => 'knights', 'id' => 'knights', 'name' => 'Рыцари', 'base_size' => 1],
  ];
};

$defaultUnitsFromPools = static function (array $pools) use ($arrierbanDomainUnitDefs): array {
  $defs = $arrierbanDomainUnitDefs();
  $out = [];
  foreach ($defs as $d) {
    $source = (string)$d['source'];
    $size = max(0, (int)($pools[$source] ?? 0));
    if ($size <= 0) continue;
    $out[] = ['source' => $source, 'unit_id' => (string)$d['id'], 'unit_name' => (string)$d['name'], 'size' => $size, 'base_size' => (int)$d['base_size']];
  }
  return $out;
};

$composeArmies = static function (array $realmObj) use ($normalizeUnits): array {
  $result = [];
  $domain = $normalizeUnits(is_array($realmObj['arrierban_units'] ?? null) ? $realmObj['arrierban_units'] : []);
  if (count($domain)) {
    $size = 0; foreach ($domain as $u) $size += (int)$u['size'];
    $musterPid = (int)($realmObj['capital_pid'] ?? 0);
    $result[] = ['army_id' => 'domain', 'army_name' => 'Доменная армия', 'army_kind' => 'domain', 'location_pid' => $musterPid, 'muster_pid' => $musterPid, 'units' => $domain, 'size' => $size];
  }
  foreach ((is_array($realmObj['arrierban_vassal_armies'] ?? null) ? $realmObj['arrierban_vassal_armies'] : []) as $idx => $a) {
    if (!is_array($a)) continue;
    $units = $normalizeUnits(is_array($a['units'] ?? null) ? $a['units'] : []);
    if (!count($units)) continue;
    $size = 0; foreach ($units as $u) $size += (int)$u['size'];
    $armyId = trim((string)($a['army_id'] ?? ''));
    if ($armyId === '') $armyId = 'feudal_' . ($idx + 1);
    $musterPid = (int)($a['muster_pid'] ?? 0);
    $result[] = ['army_id' => $armyId, 'army_name' => (string)($a['army_name'] ?? ('Феодальная армия ' . ($idx + 1))), 'army_kind' => (string)($a['army_kind'] ?? 'vassal'), 'location_pid' => $musterPid, 'muster_pid' => $musterPid, 'units' => $units, 'size' => $size];
  }
  return $result;
};

$applyArmiesToRealm = static function (array $armiesFlat) use (&$realm, $sanitizeArmy): void {
  $domainUnits = [];
  $vassal = [];
  foreach ($armiesFlat as $row) {
    if (!is_array($row)) continue;
    $kind = trim((string)($row['army_kind'] ?? ''));
    if ($kind === 'domain') {
      $domainUnits = $sanitizeArmy($row)['units'];
      continue;
    }
    $clean = $sanitizeArmy($row);
    $aid = trim((string)($clean['army_id'] ?? ''));
    if ($aid === '') $aid = 'feudal_' . (count($vassal) + 1);
    $vassal[] = [
      'army_id' => $aid,
      'army_name' => (string)($clean['army_name'] ?? ('Феодальная армия ' . (count($vassal) + 1))),
      'army_kind' => (string)($clean['army_kind'] ?? 'vassal'),
      'muster_pid' => (int)($clean['muster_pid'] ?? 0),
      'units' => $clean['units'],
    ];
  }
  $realm['arrierban_units'] = $domainUnits;
  $realm['arrierban_vassal_armies'] = $vassal;
  $realm['arrierban_vassal_units'] = [];
  foreach ($vassal as $a) {
    foreach ((array)($a['units'] ?? []) as $u) $realm['arrierban_vassal_units'][] = $u;
  }
  $realm['arrierban_active'] = (count($domainUnits) + count($realm['arrierban_vassal_units'])) > 0;
};

$getCurrentWarTurnYear = static function (): int {
  $path = api_repo_root() . '/data/turns/index.json';
  if (!is_file($path)) return 0;
  $raw = (string)@file_get_contents($path);
  $idx = json_decode($raw, true);
  if (!is_array($idx) || !is_array($idx['turns'] ?? null)) return 0;
  $max = 0;
  foreach ($idx['turns'] as $t) {
    $y = (int)$t;
    if ($y > $max) $max = $y;
  }
  return max(0, $max);
};

$syncRealmWarArmies = static function (?string $movedArmyId = null, ?int $newPid = null) use (&$state, $entityType, $entityId, &$realm, $composeArmies, $getCurrentWarTurnYear): array {
  $turnYear = $getCurrentWarTurnYear();
  $prefix = $entityType . ':' . $entityId . ':';
  $existing = [];
  $keep = [];
  foreach ((array)($state['war_armies'] ?? []) as $row) {
    if (!is_array($row)) continue;
    $wid = trim((string)($row['war_army_id'] ?? ''));
    if ($wid === '' || strpos($wid, $prefix) !== 0) {
      $keep[] = $row;
      continue;
    }
    $existing[$wid] = $row;
  }

  $rows = [];
  foreach ($composeArmies($realm) as $army) {
    $aid = trim((string)($army['army_id'] ?? ''));
    if ($aid === '') continue;
    $wid = $prefix . $aid;
    $prev = $existing[$wid] ?? [];
    $pid = (int)($prev['current_pid'] ?? $army['muster_pid'] ?? $army['location_pid'] ?? 0);
    $moved = ((int)($prev['moved_turn_year'] ?? 0) === $turnYear) ? (bool)($prev['moved_this_turn'] ?? false) : false;
    if ($movedArmyId !== null && $movedArmyId === $aid && $newPid !== null) {
      $pid = $newPid;
      $moved = true;
    }
    $rows[] = [
      'war_army_id' => $wid,
      'realm_type' => $entityType,
      'realm_id' => $entityId,
      'realm_name' => (string)($realm['name'] ?? $entityId),
      'army_kind' => (string)($army['army_kind'] ?? 'vassal'),
      'army_id' => $aid,
      'army_name' => (string)($army['army_name'] ?? $aid),
      'current_pid' => $pid,
      'moved_this_turn' => $moved,
      'moved_turn_year' => $moved ? $turnYear : null,
    ];
  }
  $state['war_armies'] = array_values(array_merge($keep, array_filter($rows, static fn($r) => (int)($r['current_pid'] ?? 0) > 0)));
  return $state['war_armies'];
};

$findArmyIdx = static function (array $list, string $armyId): int {
  foreach ($list as $idx => $a) {
    if (!is_array($a)) continue;
    if ((string)($a['army_id'] ?? '') === $armyId) return (int)$idx;
  }
  return -1;
};

if ($action === 'muster') {
  $mode = trim((string)($payload['muster_mode'] ?? 'domain'));
  if ($mode === 'royal') {
    if ($entityType !== 'great_houses') api_json_response(['error' => 'muster_mode_not_allowed', 'mode' => $mode], 400, api_state_mtime());
    $kingdomId = '';
    foreach (($state['kingdoms'] ?? []) as $kid => $k) {
      if (is_array($k) && trim((string)($k['ruling_house_id'] ?? '')) === $entityId) { $kingdomId = (string)$kid; break; }
    }
    if ($kingdomId === '') api_json_response(['error' => 'muster_mode_not_allowed', 'mode' => $mode], 400, api_state_mtime());
    $calc = $calculateArrierbanForRealm('kingdoms', $kingdomId, (array)($state['kingdoms'][$kingdomId] ?? []));
  } else {
    if ($mode === 'vassal' && $entityType !== 'great_houses' && $entityType !== 'kingdoms') {
      api_json_response(['error' => 'muster_mode_not_allowed', 'mode' => $mode], 400, api_state_mtime());
    }
    $calc = $calculateArrierbanForRealm($entityType, $entityId, $realm);
  }

  $domainUnits = $defaultUnitsFromPools((array)($calc['pools'] ?? []));
  if (!count($domainUnits)) api_json_response(['error' => 'muster_empty'], 400, api_state_mtime());

  $vassalArmies = [];
  if ($mode !== 'domain') {
    foreach ((array)($calc['supportingSources'] ?? []) as $src) {
      if (!is_array($src)) continue;
      $id = trim((string)($src['id'] ?? ''));
      if ($id === '') continue;
      $vassalArmies[] = [
        'army_id' => preg_replace('/[^a-zA-Z0-9_:\-]/', '_', $id),
        'army_name' => (string)($src['name'] ?? $id),
        'army_kind' => (string)($src['kind'] ?? 'vassal'),
        'muster_pid' => (int)($src['muster_pid'] ?? 0),
        'units' => $defaultUnitsFromPools((array)($calc['pools'] ?? [])),
      ];
    }
  }

  $realm['arrierban_units'] = $domainUnits;
  $realm['arrierban_vassal_armies'] = $vassalArmies;
  $realm['arrierban_vassal_units'] = [];
  foreach ($vassalArmies as $a) {
    foreach ((array)($a['units'] ?? []) as $u) $realm['arrierban_vassal_units'][] = $u;
  }
  $realm['arrierban_active'] = true;

  $flat = $composeArmies($realm);

  $syncRealmWarArmies();
} elseif ($action === 'move' || $action === 'disband' || $action === 'save_armies') {
  $flat = $composeArmies($realm);

  if ($action === 'move') {
    $armyId = trim((string)($payload['army_id'] ?? ''));
    $toPid = (int)($payload['to_pid'] ?? 0);
    if ($armyId === '' || !isset($owned[$toPid])) api_json_response(['error' => 'invalid_move_payload'], 400, api_state_mtime());
    $idx = $findArmyIdx($flat, $armyId);
    if ($idx < 0) api_json_response(['error' => 'army_not_found'], 404, api_state_mtime());

    // same turn move lock as war_armies in admin engine
    $turnYear = $getCurrentWarTurnYear();
    foreach ((array)($state['war_armies'] ?? []) as $wa) {
      if (!is_array($wa)) continue;
      if (trim((string)($wa['realm_type'] ?? '')) !== $entityType) continue;
      if (trim((string)($wa['realm_id'] ?? '')) !== $entityId) continue;
      if (trim((string)($wa['army_id'] ?? '')) !== $armyId) continue;
      $moved = ((int)($wa['moved_turn_year'] ?? 0) === $turnYear) ? (bool)($wa['moved_this_turn'] ?? false) : false;
      if ($moved) api_json_response(['error' => 'army_already_moved_this_turn'], 400, api_state_mtime());
    }

    $flat[$idx]['muster_pid'] = $toPid;
    $flat[$idx]['location_pid'] = $toPid;
    $applyArmiesToRealm($flat);
    $syncRealmWarArmies($armyId, $toPid);
  } elseif ($action === 'disband') {
    $armyId = trim((string)($payload['army_id'] ?? ''));
    $idx = $findArmyIdx($flat, $armyId);
    if ($idx < 0) api_json_response(['error' => 'army_not_found'], 404, api_state_mtime());
    array_splice($flat, $idx, 1);
    $applyArmiesToRealm($flat);
    $syncRealmWarArmies();
  } else {
    $rows = $payload['armies'] ?? null;
    if (!is_array($rows)) api_json_response(['error' => 'invalid_armies_payload'], 400, api_state_mtime());
    foreach ($rows as &$row) {
      if (!is_array($row)) $row = [];
      $pid = (int)($row['location_pid'] ?? $row['muster_pid'] ?? 0);
      if ($pid > 0 && !isset($owned[$pid])) api_json_response(['error' => 'army_location_not_owned'], 400, api_state_mtime());
      $row['muster_pid'] = $pid;
    }
    unset($row);
    $applyArmiesToRealm($rows);
    $syncRealmWarArmies();
  }
} else {
  api_json_response(['error' => 'unsupported_action'], 400, api_state_mtime());
}

if (!api_save_state($state)) api_json_response(['error' => 'write_failed'], 500, api_state_mtime());
$armiesOut = player_compose_armies_from_realm($realm);
api_json_response(['ok' => true, 'armies' => array_values($armiesOut)], 200, api_state_mtime());
