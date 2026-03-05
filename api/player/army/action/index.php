<?php

declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/lib/player_api.php';
require_once dirname(__DIR__, 3) . '/lib/turn_api.php';

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

$canSummonPalatinesAndPreventors = static function (string $mode): bool {
  return $mode === 'kingdoms' || $mode === 'great_houses';
};

$unitCatalog = [
  'militia' => ['name' => 'Крестьянское ополчение', 'base_size' => 1000],
  'militia_tr' => ['name' => 'Тренированное крестьянское ополчение', 'base_size' => 1000],
  'shot' => ['name' => 'Ополчение с электропневматикой', 'base_size' => 500],
  'pikes' => ['name' => 'Ополчение с электрокопьями', 'base_size' => 500],
  'assault150' => ['name' => 'Штурмовая баталия', 'base_size' => 150],
  'bikes' => ['name' => 'Рейтары', 'base_size' => 50],
  'dragoons' => ['name' => 'Драгуны', 'base_size' => 50],
  'ulans' => ['name' => 'Уланы', 'base_size' => 50],
  'foot_nehts' => ['name' => 'Пешие нехты', 'base_size' => 100],
  'foot_knights' => ['name' => 'Пешие рыцари', 'base_size' => 100],
  'moto_knights' => ['name' => 'Мотоконные рыцари', 'base_size' => 20],
  'palatines' => ['name' => 'Палатинские всадники', 'base_size' => 20],
  'preventors100' => ['name' => 'Пешие превенторы', 'base_size' => 100],
];

$collectProvinceIdsForArrierban = static function (string $mode, string $id, array $realmObj) use ($state): array {
  $pids = [];
  if (is_array($realmObj['province_pids'] ?? null)) {
    foreach ($realmObj['province_pids'] as $raw) { $pid = (int)$raw; if ($pid > 0) $pids[$pid] = true; }
  }

  if ($mode === 'kingdoms') {
    $rulingHouseId = trim((string)($realmObj['ruling_house_id'] ?? ''));
    $rulingHouse = ($rulingHouseId !== '' && is_array($state['great_houses'][$rulingHouseId] ?? null)) ? $state['great_houses'][$rulingHouseId] : null;
    $layer = is_array($rulingHouse['minor_house_layer'] ?? null) ? $rulingHouse['minor_house_layer'] : null;
    if ($layer) {
      foreach ((is_array($layer['domain_pids'] ?? null) ? $layer['domain_pids'] : []) as $raw) {
        $pid = (int)$raw;
        if ($pid > 0) $pids[$pid] = true;
      }
    }
    return array_keys($pids);
  }

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
    foreach (($state['great_houses'] ?? []) as $gh) {
      if (!is_array($gh)) continue;
      $layer = is_array($gh['minor_house_layer'] ?? null) ? $gh['minor_house_layer'] : null;
      if (!$layer || !is_array($layer['vassals'] ?? null)) continue;
      foreach ($layer['vassals'] as $vassal) {
        if (!is_array($vassal)) continue;
        if (trim((string)($vassal['id'] ?? '')) !== $id) continue;
        foreach ((is_array($vassal['province_pids'] ?? null) ? $vassal['province_pids'] : []) as $raw) {
          $pid = (int)$raw;
          if ($pid > 0) $pids[$pid] = true;
        }
      }
    }
    foreach (($state['provinces'] ?? []) as $pd) {
      if (!is_array($pd)) continue;
      $pid = (int)($pd['pid'] ?? 0);
      if ($pid <= 0) continue;
      if (trim((string)($pd['minor_house_id'] ?? '')) === $id) $pids[$pid] = true;
    }
  }
  return array_keys($pids);
};

$hexCountsByPid = turn_api_hex_counts_by_pid();

$calculateArrierbanForRealm = static function (string $mode, string $id, array $realmObj) use ($state, $collectProvinceIdsForArrierban, $hexCountsByPid): array {
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
    if ($hex <= 0) $hex = max(0, (int)($hexCountsByPid[(string)$pid] ?? 0));
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
  if ($mode === 'kingdoms') {
    $rulingHouseId = trim((string)($realmObj['ruling_house_id'] ?? ''));
    $seenGreat = [];
    if ($rulingHouseId !== '') $seenGreat[$rulingHouseId] = true;

    $explicitVassalIds = [];
    if (is_array($realmObj['vassal_house_ids'] ?? null)) {
      foreach ($realmObj['vassal_house_ids'] as $raw) {
        $ghId = trim((string)$raw);
        if ($ghId !== '') $explicitVassalIds[$ghId] = true;
      }
    }
    foreach (array_keys($explicitVassalIds) as $ghId) {
      if (isset($seenGreat[$ghId])) continue;
      $gh = $state['great_houses'][$ghId] ?? null;
      if (!is_array($gh)) continue;
      if ((mt_rand() / mt_getrandmax()) * 100 >= $triggerChance) continue;
      $seenGreat[$ghId] = true;
      $supportingSources[] = ['id' => 'vassal_great_house:' . $ghId, 'name' => (string)($gh['name'] ?? $ghId), 'kind' => 'vassal', 'muster_pid' => (int)($gh['capital_pid'] ?? $gh['capital_key'] ?? 0)];
    }

    foreach (($state['provinces'] ?? []) as $pd) {
      if (!is_array($pd)) continue;
      if (trim((string)($pd['kingdom_id'] ?? '')) !== $id) continue;
      $ghId = trim((string)($pd['great_house_id'] ?? ''));
      if ($ghId === '' || isset($seenGreat[$ghId])) continue;
      $gh = $state['great_houses'][$ghId] ?? null;
      if (!is_array($gh)) continue;
      if ((mt_rand() / mt_getrandmax()) * 100 >= $triggerChance) continue;
      $seenGreat[$ghId] = true;
      $supportingSources[] = ['id' => 'vassal_great_house:' . $ghId, 'name' => (string)($gh['name'] ?? $ghId), 'kind' => 'vassal', 'muster_pid' => (int)($gh['capital_pid'] ?? $gh['capital_key'] ?? 0)];
    }
  }
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
      $assigned = [];
      foreach ((is_array($layer['domain_pids'] ?? null) ? $layer['domain_pids'] : []) as $raw) {
        $pid = (int)$raw;
        if ($pid > 0) $assigned[$pid] = true;
      }
      foreach ($layer['vassals'] as $v) {
        if (!is_array($v)) continue;
        foreach ((is_array($v['province_pids'] ?? null) ? $v['province_pids'] : []) as $raw) {
          $pid = (int)$raw;
          if ($pid > 0) $assigned[$pid] = true;
        }
      }
      foreach (($state['provinces'] ?? []) as $pd) {
        if (!is_array($pd)) continue;
        if (trim((string)($pd['great_house_id'] ?? '')) !== $id) continue;
        $pid = (int)($pd['pid'] ?? 0);
        if ($pid <= 0 || isset($assigned[$pid])) continue;
        if ((mt_rand() / mt_getrandmax()) * 100 >= $triggerChance) continue;
        $realmCapitalPid = (int)($realmObj['capital_pid'] ?? 0);
        if ($realmCapitalPid <= 0 && count($domainPids)) $realmCapitalPid = (int)$domainPids[0];
        $supportingSources[] = ['id' => 'unassigned:' . $pid, 'name' => 'Неназначенная провинция ' . $pid, 'kind' => 'unassigned', 'muster_pid' => $realmCapitalPid];
      }
    }
  }

  return ['realm' => $realmObj, 'domainPids' => $domainPids, 'pools' => $pools, 'supportingSources' => $supportingSources];
};

$arrierbanDomainUnitDefs = static function (string $mode) use ($unitCatalog, $canSummonPalatinesAndPreventors): array {
  $mk = static function (string $source, string $id) use ($unitCatalog): array {
    $cfg = $unitCatalog[$id] ?? ['name' => $id, 'base_size' => 1];
    return ['source' => $source, 'id' => $id, 'name' => (string)$cfg['name'], 'base_size' => max(1, (int)($cfg['base_size'] ?? 1))];
  };
  $defs = [
    $mk('militia', 'militia'), $mk('militia', 'militia_tr'),
    $mk('sergeants', 'shot'), $mk('sergeants', 'pikes'), $mk('sergeants', 'assault150'),
    $mk('nehts', 'bikes'), $mk('nehts', 'dragoons'), $mk('nehts', 'ulans'), $mk('nehts', 'foot_nehts'),
    $mk('knights', 'foot_knights'), $mk('knights', 'moto_knights'),
  ];
  if ($canSummonPalatinesAndPreventors($mode)) {
    $defs[] = $mk('knights', 'palatines');
    $defs[] = $mk('knights', 'preventors100');
  }
  return $defs;
};

$defaultUnitsFromPools = static function (string $mode, array $pools) use ($arrierbanDomainUnitDefs): array {
  $defs = $arrierbanDomainUnitDefs($mode);
  $picked = [];
  $out = [];
  foreach ($defs as $d) {
    $source = (string)$d['source'];
    if (isset($picked[$source])) continue;
    $size = max(0, (int)($pools[$source] ?? 0));
    if ($size <= 0) continue;
    $picked[$source] = true;
    $out[] = ['source' => $source, 'unit_id' => (string)$d['id'], 'unit_name' => (string)$d['name'], 'size' => $size, 'base_size' => (int)$d['base_size']];
  }
  return $out;
};

$collectUnitsFromPayload = static function (string $mode, $rows, array $pools) use ($arrierbanDomainUnitDefs): array {
  if (!is_array($rows) || !count($rows)) return [];
  $defs = [];
  foreach ($arrierbanDomainUnitDefs($mode) as $def) {
    if (!is_array($def)) continue;
    $defs[trim((string)($def['id'] ?? ''))] = $def;
  }
  $used = ['militia' => 0, 'sergeants' => 0, 'nehts' => 0, 'knights' => 0];
  $out = [];
  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $unitId = trim((string)($row['unit_id'] ?? ''));
    $def = $defs[$unitId] ?? null;
    if (!is_array($def)) continue;
    $source = trim((string)($def['source'] ?? ''));
    $pool = max(0, (int)($pools[$source] ?? 0));
    $left = max(0, $pool - (int)($used[$source] ?? 0));
    if ($left <= 0) continue;
    $size = max(0, (int)($row['size'] ?? 0));
    if ($size <= 0) continue;
    $clamped = min($left, $size);
    $used[$source] = (int)($used[$source] ?? 0) + $clamped;
    $out[] = [
      'source' => $source,
      'unit_id' => $unitId,
      'unit_name' => (string)($def['name'] ?? $unitId),
      'size' => $clamped,
      'base_size' => max(1, (int)($def['base_size'] ?? 1)),
    ];
  }
  return $out;
};


$sanitizeSupportingSources = static function ($rows, int $fallbackPid = 0): array {
  $out = [];
  if (!is_array($rows)) return $out;
  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $id = trim((string)($row['id'] ?? ''));
    if ($id === '') continue;
    $musterPid = (int)($row['muster_pid'] ?? 0);
    if ($musterPid <= 0) $musterPid = max(0, $fallbackPid);
    $out[] = [
      'id' => $id,
      'name' => (string)($row['name'] ?? $id),
      'kind' => (string)($row['kind'] ?? 'vassal'),
      'muster_pid' => $musterPid,
    ];
  }
  return $out;
};

$arrierbanRandomVassalArmies = static function (string $mode, array $calc) use ($unitCatalog, $canSummonPalatinesAndPreventors): array {
  $ids = ['militia', 'militia_tr', 'shot', 'pikes', 'assault150', 'bikes', 'dragoons', 'ulans', 'foot_nehts', 'foot_knights', 'moto_knights'];
  if ($canSummonPalatinesAndPreventors($mode)) {
    $ids[] = 'palatines';
    $ids[] = 'preventors100';
  }
  $armies = [];
  foreach ((array)($calc['supportingSources'] ?? []) as $src) {
    if (!is_array($src)) continue;
    $units = [];
    $unitCount = 1 + random_int(0, 1);
    for ($i = 0; $i < $unitCount; $i++) {
      $id = $ids[random_int(0, max(0, count($ids) - 1))];
      $cfg = $unitCatalog[$id] ?? null;
      if (!is_array($cfg)) continue;
      $base = max(1, (int)($cfg['base_size'] ?? 1));
      $size = max((int)ceil($base * 0.1), (int)round($base * (0.8 + (mt_rand() / mt_getrandmax()) * 0.6)));
      $units[] = ['source' => 'vassal_random', 'unit_id' => $id, 'unit_name' => (string)($cfg['name'] ?? $id), 'size' => $size, 'base_size' => $base];
    }
    $armies[] = [
      'army_id' => (string)($src['id'] ?? ('feudal_' . (count($armies) + 1))),
      'army_name' => (string)($src['name'] ?? ('Феодальная армия ' . (count($armies) + 1))),
      'army_kind' => (string)($src['kind'] ?? 'vassal'),
      'muster_pid' => max(0, (int)($src['muster_pid'] ?? 0)),
      'units' => $units,
    ];
  }
  return $armies;
};

$realmDefaultArmyPid = static function (string $mode, string $id, array $realmObj) use ($state): int {
  $fieldByType = [
    'kingdoms' => 'kingdom_id',
    'great_houses' => 'great_house_id',
    'minor_houses' => 'minor_house_id',
    'free_cities' => 'free_city_id',
    'special_territories' => 'special_territory_id',
  ];
  $field = $fieldByType[$mode] ?? '';
  $capitalPid = (int)($realmObj['capital_pid'] ?? 0);
  if ($capitalPid <= 0 && is_array($realmObj['province_pids'] ?? null) && count($realmObj['province_pids'])) {
    $capitalPid = (int)$realmObj['province_pids'][0];
  }
  if ($capitalPid <= 0 && $mode === 'minor_houses') {
    foreach (($state['great_houses'] ?? []) as $gh) {
      if (!is_array($gh)) continue;
      $layer = is_array($gh['minor_house_layer'] ?? null) ? $gh['minor_house_layer'] : null;
      if (!$layer || !is_array($layer['vassals'] ?? null)) continue;
      foreach ($layer['vassals'] as $vassal) {
        if (!is_array($vassal)) continue;
        if (trim((string)($vassal['id'] ?? '')) !== $id) continue;
        if (is_array($vassal['province_pids'] ?? null) && count($vassal['province_pids'])) {
          $capitalPid = (int)$vassal['province_pids'][0];
          if ($capitalPid > 0) break 2;
        }
      }
    }
  }
  if ($capitalPid <= 0 && $field !== '') {
    foreach (($state['provinces'] ?? []) as $pd) {
      if (!is_array($pd)) continue;
      if (trim((string)($pd[$field] ?? '')) !== $id) continue;
      $capitalPid = (int)($pd['pid'] ?? 0);
      if ($capitalPid > 0) break;
    }
  }
  return max(0, $capitalPid);
};

$composeArmies = static function (array $realmObj) use ($normalizeUnits, $entityType, $entityId, $realmDefaultArmyPid): array {
  $result = [];
  $domain = $normalizeUnits(is_array($realmObj['arrierban_units'] ?? null) ? $realmObj['arrierban_units'] : []);
  if (count($domain)) {
    $size = 0; foreach ($domain as $u) $size += (int)$u['size'];
    $musterPid = $realmDefaultArmyPid($entityType, $entityId, $realmObj);
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


$distributeLevyByPopulation = static function (array $domainPids, int $totalLevy) use (&$state): array {
  $rows = [];
  $totalPopulation = 0;
  foreach ($domainPids as $pid) {
    $pd =& $state['provinces'][(string)$pid];
    if (!is_array($pd)) continue;
    $population = max(0, (int)($pd['population'] ?? 0));
    $rows[] = ['pid' => (int)$pid, 'population' => $population, 'levy' => 0];
    $totalPopulation += $population;
  }
  if (!count($rows) || $totalLevy <= 0 || $totalPopulation <= 0) return $rows;

  $assigned = 0;
  foreach ($rows as &$row) {
    $raw = ($totalLevy * $row['population']) / $totalPopulation;
    $row['levy'] = min($row['population'], (int)floor($raw));
    $assigned += $row['levy'];
  }
  unset($row);

  $remaining = max(0, $totalLevy - $assigned);
  if ($remaining > 0) {
    $sortable = [];
    foreach ($rows as $idx => $row) {
      $frac = $row['population'] > 0 ? (($totalLevy * $row['population']) / $totalPopulation) - $row['levy'] : 0.0;
      $sortable[] = ['idx' => $idx, 'frac' => $frac];
    }
    usort($sortable, static fn($a, $b) => $b['frac'] <=> $a['frac']);
    foreach ($sortable as $item) {
      if ($remaining <= 0) break;
      $idx = (int)$item['idx'];
      $cap = max(0, $rows[$idx]['population'] - $rows[$idx]['levy']);
      if ($cap <= 0) continue;
      $add = min($cap, $remaining);
      $rows[$idx]['levy'] += $add;
      $remaining -= $add;
    }
  }

  return $rows;
};

$applyArrierbanToProvinces = static function (array $calc, array $domainUnits, array $vassalUnits) use (&$state, $distributeLevyByPopulation): array {
  $totalLevy = 0;
  foreach ($domainUnits as $u) $totalLevy += max(0, (int)($u['size'] ?? 0));
  foreach ($vassalUnits as $u) $totalLevy += max(0, (int)($u['size'] ?? 0));

  $rows = $distributeLevyByPopulation((array)($calc['domainPids'] ?? []), $totalLevy);
  foreach ($rows as $row) {
    $pid = (int)($row['pid'] ?? 0);
    $population = (int)($row['population'] ?? 0);
    $levy = (int)($row['levy'] ?? 0);
    if ($pid <= 0 || $population <= 0 || $levy <= 0) continue;
    $pd =& $state['provinces'][(string)$pid];
    if (!is_array($pd)) continue;
    $pd['population'] = max(0, (int)floor($population - $levy));
    $pd['arrierban_income_penalty'] = max(0, min(1, ($levy / $population) * 10));
    $pd['arrierban_levy'] = max(0, (int)($pd['arrierban_levy'] ?? 0)) + $levy;
    $pd['arrierban_raised'] = true;
  }

  return ['totalLevy' => $totalLevy, 'rows' => $rows];
};

$getRealmArrierbanProvinceRows = static function () use (&$state, $entityType, $entityId): array {
  $fieldByType = [
    'kingdoms' => 'kingdom_id',
    'great_houses' => 'great_house_id',
    'minor_houses' => 'minor_house_id',
    'free_cities' => 'free_city_id',
    'special_territories' => 'special_territory_id',
  ];
  $field = $fieldByType[$entityType] ?? '';
  if ($field === '') return [];
  $rows = [];
  foreach (($state['provinces'] ?? []) as $pd) {
    if (!is_array($pd)) continue;
    if (trim((string)($pd[$field] ?? '')) !== $entityId) continue;
    $levy = max(0, (int)floor((float)($pd['arrierban_levy'] ?? 0)));
    if ($levy <= 0) continue;
    $rows[] = ['pid' => (int)($pd['pid'] ?? 0), 'levy' => $levy, 'population' => (int)($pd['population'] ?? 0)];
  }
  usort($rows, static fn(array $a, array $b): int => ((int)$a['pid']) <=> ((int)$b['pid']));
  return $rows;
};

$distributeEvenLoss = static function (int $totalLoss, array $capacities): array {
  $losses = array_fill(0, count($capacities), 0);
  $remaining = max(0, $totalLoss);
  $active = [];
  foreach ($capacities as $idx => $cap) {
    if ((int)$cap > 0) $active[] = (int)$idx;
  }
  while ($remaining > 0 && count($active)) {
    $share = max(1, (int)floor($remaining / count($active)));
    $next = [];
    foreach ($active as $idx) {
      $cap = max(0, (int)$capacities[$idx] - (int)$losses[$idx]);
      if ($cap <= 0) continue;
      $add = min($cap, $share, $remaining);
      if ($add <= 0) continue;
      $losses[$idx] += $add;
      $remaining -= $add;
      if ($losses[$idx] < (int)$capacities[$idx]) $next[] = $idx;
      if ($remaining <= 0) break;
    }
    $active = $next;
  }
  return $losses;
};

$dismissArrierbanWithLosses = static function (int $requestedLosses) use (&$realm, &$state, $getRealmArrierbanProvinceRows, $distributeEvenLoss): array {
  $rows = $getRealmArrierbanProvinceRows();
  $mobilizedTotal = 0;
  foreach ($rows as $row) $mobilizedTotal += max(0, (int)($row['levy'] ?? 0));

  $fieldTotal = 0;
  foreach ((is_array($realm['arrierban_units'] ?? null) ? $realm['arrierban_units'] : []) as $row) $fieldTotal += max(0, (int)($row['size'] ?? 0));
  foreach ((is_array($realm['arrierban_vassal_units'] ?? null) ? $realm['arrierban_vassal_units'] : []) as $row) $fieldTotal += max(0, (int)($row['size'] ?? 0));
  $impliedLosses = max(0, $mobilizedTotal - $fieldTotal);
  $losses = min($mobilizedTotal, max($impliedLosses, max(0, $requestedLosses)));

  $caps = [];
  foreach ($rows as $row) $caps[] = max(0, (int)($row['levy'] ?? 0));
  $lossByProvince = $distributeEvenLoss($losses, $caps);

  $returnedTotal = 0;
  foreach ($rows as $idx => $row) {
    $pid = (int)($row['pid'] ?? 0);
    if ($pid <= 0 || !is_array($state['provinces'][(string)$pid] ?? null)) continue;
    $levy = max(0, (int)($row['levy'] ?? 0));
    $returned = max(0, $levy - max(0, (int)($lossByProvince[$idx] ?? 0)));
    $pd =& $state['provinces'][(string)$pid];
    $pd['population'] = max(0, (int)floor((float)($pd['population'] ?? 0) + $returned));
    $pd['arrierban_levy'] = 0;
    $pd['arrierban_income_penalty'] = 0;
    $pd['arrierban_raised'] = false;
    $returnedTotal += $returned;
  }

  $realm['arrierban_units'] = [];
  $realm['arrierban_vassal_armies'] = [];
  $realm['arrierban_vassal_units'] = [];
  $realm['arrierban_active'] = false;
  $realm['arrierban_domain_only'] = false;

  return [
    'mobilizedTotal' => $mobilizedTotal,
    'fieldTotal' => $fieldTotal,
    'impliedLosses' => $impliedLosses,
    'losses' => $losses,
    'returnedTotal' => $returnedTotal,
    'provinces' => count($rows),
  ];
};

$findArmyIdx = static function (array $list, string $armyId): int {
  foreach ($list as $idx => $a) {
    if (!is_array($a)) continue;
    if ((string)($a['army_id'] ?? '') === $armyId) return (int)$idx;
  }
  return -1;
};

if ($action === 'muster_plan' || $action === 'muster') {
  if ((bool)($realm['arrierban_active'] ?? false)) {
    api_json_response(['error' => 'arrierban_already_active'], 400, api_state_mtime());
  }
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

  if ($action === 'muster_plan') {
    api_json_response([
      'ok' => true,
      'mode' => $mode,
      'pools' => (array)($calc['pools'] ?? []),
      'supporting_sources' => array_values((array)($calc['supportingSources'] ?? [])),
      'domain_unit_defs' => $arrierbanDomainUnitDefs($mode === 'royal' ? 'kingdoms' : $entityType),
      'default_units' => $defaultUnitsFromPools($mode === 'royal' ? 'kingdoms' : $entityType, (array)($calc['pools'] ?? [])),
    ], 200, api_state_mtime());
  }

  $ruleMode = ($mode === 'royal') ? 'kingdoms' : $entityType;
  $domainUnits = $collectUnitsFromPayload($ruleMode, $payload['muster_units'] ?? null, (array)($calc['pools'] ?? []));
  if (!count($domainUnits)) $domainUnits = $defaultUnitsFromPools($ruleMode, (array)($calc['pools'] ?? []));
  if (!count($domainUnits)) api_json_response(['error' => 'muster_empty'], 400, api_state_mtime());

  $vassalArmies = [];
  if ($mode !== 'domain') {
    $realmFallbackPid = $realmDefaultArmyPid($entityType, $entityId, $realm);
    $forcedSources = $sanitizeSupportingSources($payload['supporting_sources'] ?? null, $realmFallbackPid);
    if (count($forcedSources)) {
      $calcForArmies = $calc;
      $calcForArmies['supportingSources'] = $forcedSources;
      $vassalArmies = $arrierbanRandomVassalArmies($ruleMode, $calcForArmies);
    } else {
      $calcForArmies = $calc;
      $calcForArmies['supportingSources'] = $sanitizeSupportingSources($calc['supportingSources'] ?? [], $realmFallbackPid);
      $vassalArmies = $arrierbanRandomVassalArmies($ruleMode, $calcForArmies);
    }
  }

  $realm['arrierban_units'] = $domainUnits;
  $realm['arrierban_vassal_armies'] = $vassalArmies;
  $realm['arrierban_vassal_units'] = [];
  foreach ($vassalArmies as $a) {
    foreach ((array)($a['units'] ?? []) as $u) $realm['arrierban_vassal_units'][] = $u;
  }
  $realm['arrierban_active'] = true;
  $realm['arrierban_domain_only'] = ($mode === 'domain');

  $applied = $applyArrierbanToProvinces($calc, $domainUnits, $realm['arrierban_vassal_units']);

  $flat = $composeArmies($realm);

  $syncRealmWarArmies();
} elseif ($action === 'move' || $action === 'disband' || $action === 'save_armies' || $action === 'dismiss_arrierban') {
  $flat = $composeArmies($realm);

  if ($action === 'move') {
    $armyId = trim((string)($payload['army_id'] ?? ''));
    $toPid = (int)($payload['to_pid'] ?? 0);
    if ($armyId === '' || $toPid <= 0) api_json_response(['error' => 'invalid_move_payload'], 400, api_state_mtime());
    $idx = $findArmyIdx($flat, $armyId);
    if ($idx < 0) api_json_response(['error' => 'army_not_found'], 404, api_state_mtime());

    $reachablePidsRaw = $payload['reachable_pids'] ?? null;
    if (!is_array($reachablePidsRaw) || !count($reachablePidsRaw)) {
      api_json_response(['error' => 'reachable_pids_required'], 400, api_state_mtime());
    }
    $reachablePids = [];
    foreach ($reachablePidsRaw as $rawPid) {
      $pid = (int)$rawPid;
      if ($pid > 0) $reachablePids[$pid] = true;
    }
    if (!isset($reachablePids[$toPid])) {
      api_json_response(['error' => 'move_not_reachable'], 400, api_state_mtime());
    }

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
  } elseif ($action === 'dismiss_arrierban') {
    if (!(bool)($realm['arrierban_active'] ?? false)) api_json_response(['error' => 'arrierban_not_active'], 400, api_state_mtime());
    $requestedLosses = max(0, (int)($payload['losses'] ?? 0));
    $dismissed = $dismissArrierbanWithLosses($requestedLosses);
    $syncRealmWarArmies();
  } else {
    $rows = $payload['armies'] ?? null;
    if (!is_array($rows)) api_json_response(['error' => 'invalid_armies_payload'], 400, api_state_mtime());
    foreach ($rows as &$row) {
      if (!is_array($row)) $row = [];
      $pid = (int)($row['location_pid'] ?? $row['muster_pid'] ?? 0);
      if ($pid <= 0) api_json_response(['error' => 'army_location_invalid'], 400, api_state_mtime());
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
api_json_response(['ok' => true, 'armies' => array_values($armiesOut), 'applied' => $applied ?? null, 'dismissed' => $dismissed ?? null], 200, api_state_mtime());
