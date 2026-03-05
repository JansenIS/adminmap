<?php

declare(strict_types=1);

require_once __DIR__ . '/state_api.php';

function turn_api_meta(): array {
  return ['api_version' => 'v1', 'schema_version' => 'stage2'];
}

function turn_api_response(array $payload, int $status = 200): void {
  if (!isset($payload['meta']) || !is_array($payload['meta'])) {
    $payload['meta'] = turn_api_meta();
  }
  api_json_response($payload, $status, turn_api_mtime());
}

function turn_api_base_dir(): string {
  return api_repo_root() . '/data/turns';
}

function turn_api_index_path(): string {
  return turn_api_base_dir() . '/index.json';
}

function turn_api_turn_path(int $year): string {
  return turn_api_base_dir() . '/turn_' . $year . '.json';
}

function turn_api_snapshot_path(int $year, string $phase): string {
  return turn_api_base_dir() . '/snapshots/turn_' . $year . '_' . $phase . '.json';
}


function turn_api_rulesets_path(): string {
  return api_repo_root() . '/data/turn_rulesets.json';
}

function turn_api_default_ruleset(): array {
  return [
    'version' => 'v2.0',
    'economy' => [
      'base_income_per_hex' => 640.0,
      'base_expense_per_hex' => 210.0,
      'population_income_per_capita' => 0.11,
      'city_population_income_per_capita' => 0.28,
      'coastal_trade_bonus' => 0.33,
      'river_trade_bonus' => 0.24,
      'terrain_income_coefficients' => [
        'равнины' => 1.00,
        'холмы' => 0.92,
        'горы' => 0.80,
        'лес' => 0.88,
        'болота' => 0.74,
        'степь' => 0.97,
        'пустоши' => 0.63,
        'побережье' => 1.28,
        'остров' => 1.12,
        'город' => 1.65,
        'руины' => 0.42,
        'озёра/реки' => 1.20,
      ],
      'terrain_expense_coefficients' => [
        'равнины' => 1.00,
        'холмы' => 1.06,
        'горы' => 1.18,
        'лес' => 1.09,
        'болота' => 1.15,
        'степь' => 0.96,
        'пустоши' => 1.08,
        'побережье' => 1.12,
        'остров' => 1.19,
        'город' => 1.30,
        'руины' => 0.91,
        'озёра/реки' => 1.05,
      ],
      'income_random_swing' => 0.12,
      'expense_random_swing' => 0.10,
    ],
    'treasury' => [
      'tax_rate' => 0.35,
      'province_reserve_rate' => 0.06,
      'province_opening_income_share' => 0.20,
      'entity_subsidy_rate' => 0.12,
      'entity_transfer_rate' => 0.01,
      'entity_transfer_cap' => 5.0,
      'province_entity_tax_rate' => 0.12,
      'great_to_kingdom_rate' => 0.18,
      'kingdom_vassal_house_tax_rate' => 0.28,
      'royal_callup_vassal_upkeep_share' => 0.8,
      'army_upkeep_per_strength' => 60.0,
    ],
  ];
}

function turn_api_load_rulesets(): array {
  $defaults = ['default' => turn_api_default_ruleset(), 'by_version' => []];
  $path = turn_api_rulesets_path();
  if (!is_file($path)) return $defaults;
  $raw = @file_get_contents($path);
  $decoded = is_string($raw) ? json_decode($raw, true) : null;
  if (!is_array($decoded)) return $defaults;

  $default = $decoded['default'] ?? null;
  $byVersion = $decoded['by_version'] ?? null;
  if (!is_array($default) || !is_array($byVersion)) return $defaults;
  return ['default' => $default, 'by_version' => $byVersion];
}

function turn_api_ruleset_for_turn(array $turn): array {
  $rulesets = turn_api_load_rulesets();
  $version = trim((string)($turn['ruleset_version'] ?? ''));
  $selected = null;
  if ($version !== '' && is_array($rulesets['by_version'][$version] ?? null)) {
    $selected = $rulesets['by_version'][$version];
  }
  if (!is_array($selected)) $selected = $rulesets['default'];

  $merged = array_replace_recursive(turn_api_default_ruleset(), $selected);
  $merged['version'] = $version !== '' ? $version : (string)($merged['version'] ?? 'v1.2');
  return $merged;
}

function turn_api_ensure_store(): void {
  $base = turn_api_base_dir();
  if (!is_dir($base)) @mkdir($base, 0775, true);
  if (!is_dir($base . '/snapshots')) @mkdir($base . '/snapshots', 0775, true);
  if (!is_dir($base . '/overlays')) @mkdir($base . '/overlays', 0775, true);

  $index = turn_api_index_path();
  if (!is_file($index)) {
    $seed = ['turns' => [], 'updated_at' => gmdate('c')];
    api_atomic_write_json($index, $seed);
  }
}

function turn_api_mtime(): int {
  turn_api_ensure_store();
  $times = [
    (int)@filemtime(turn_api_index_path()),
    (int)@filemtime(api_state_path()),
    (int)@filemtime(api_repo_root() . '/map.png'),
    (int)@filemtime(api_repo_root() . '/provinces_id.png'),
  ];
  $max = 0;
  foreach ($times as $t) if ($t > $max) $max = $t;
  return $max > 0 ? $max : time();
}

function turn_api_load_index(): array {
  turn_api_ensure_store();
  $raw = @file_get_contents(turn_api_index_path());
  $decoded = is_string($raw) ? json_decode($raw, true) : null;
  if (!is_array($decoded)) return ['turns' => [], 'updated_at' => gmdate('c')];
  if (!is_array($decoded['turns'] ?? null)) $decoded['turns'] = [];
  return $decoded;
}

function turn_api_save_index(array $index): bool {
  $index['updated_at'] = gmdate('c');
  return api_atomic_write_json(turn_api_index_path(), $index);
}

function turn_api_load_turn(int $year): ?array {
  $path = turn_api_turn_path($year);
  if (!is_file($path)) return null;
  $raw = @file_get_contents($path);
  $decoded = is_string($raw) ? json_decode($raw, true) : null;
  return is_array($decoded) ? $decoded : null;
}

function turn_api_save_turn(array $turn): bool {
  $year = (int)($turn['year'] ?? 0);
  if ($year <= 0) return false;
  $turn['updated_at'] = gmdate('c');
  $turn['version'] = turn_api_turn_version($turn);
  return api_atomic_write_json(turn_api_turn_path($year), $turn);
}

function turn_api_load_snapshot(int $year, string $phase): ?array {
  $path = turn_api_snapshot_path($year, $phase);
  if (!is_file($path)) return null;
  $raw = @file_get_contents($path);
  $decoded = is_string($raw) ? json_decode($raw, true) : null;
  return is_array($decoded) ? $decoded : null;
}

function turn_api_save_snapshot(int $year, string $phase, array $payload): ?array {
  $payloadNormalized = [
    'phase' => $phase,
    'year' => $year,
    'saved_at' => gmdate('c'),
    'payload' => $payload,
  ];
  $checksumEncoded = json_encode([
    'phase' => $phase,
    'year' => $year,
    'payload' => $payload,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($checksumEncoded === false) return null;
  $checksum = hash('sha256', $checksumEncoded);
  $payloadNormalized['checksum'] = $checksum;
  $ok = api_atomic_write_json(turn_api_snapshot_path($year, $phase), $payloadNormalized);
  if (!$ok) return null;

  return [
    'phase' => $phase,
    'checksum' => $checksum,
    'snapshot_ref' => 'data/turns/snapshots/turn_' . $year . '_' . $phase . '.json',
  ];
}

function turn_api_turn_version(array $turn): string {
  return hash('sha256', json_encode([
    'year' => (int)($turn['year'] ?? 0),
    'status' => (string)($turn['status'] ?? ''),
    'ruleset_version' => (string)($turn['ruleset_version'] ?? ''),
    'economy' => $turn['economy'] ?? null,
    'entity_state' => $turn['entity_state'] ?? null,
    'economy_state' => $turn['economy_state'] ?? null,
    'entity_treasury' => $turn['entity_treasury'] ?? null,
    'province_treasury' => $turn['province_treasury'] ?? null,
    'treasury_ledger' => $turn['treasury_ledger'] ?? null,
    'treaties' => $turn['treaties'] ?? null,
    'snapshot_start' => $turn['snapshot_start']['checksum'] ?? null,
    'snapshot_end' => $turn['snapshot_end']['checksum'] ?? null,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function turn_api_request_payload(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') return [];
  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) turn_api_response(['error' => 'invalid_json'], 400);
  return $decoded;
}

function turn_api_if_match(array $turn, array $payload = [], bool $required = true): array {
  $header = trim((string)($_SERVER['HTTP_IF_MATCH'] ?? ''));
  $body = trim((string)($payload['if_match'] ?? ''));
  $provided = $header !== '' ? $header : $body;
  $expected = (string)($turn['version'] ?? turn_api_turn_version($turn));
  if ($required && $provided === '') return ['ok' => false, 'error' => 'if_match_required', 'expected' => $expected, 'provided' => ''];
  if ($provided !== '' && !hash_equals($expected, $provided)) return ['ok' => false, 'error' => 'version_conflict', 'expected' => $expected, 'provided' => $provided];
  return ['ok' => true, 'expected' => $expected, 'provided' => $provided];
}

function turn_api_terrain_key(string $terrain): string {
  $value = mb_strtolower(trim($terrain));
  return $value === '' ? 'равнины' : $value;
}

function turn_api_seeded_noise(int $year, int $pid, string $salt): float {
  $hash = hash('sha256', $year . '|' . $pid . '|' . $salt);
  $chunk = substr($hash, 0, 8);
  $fraction = (float)hexdec($chunk) / 4294967295.0;
  return ($fraction * 2.0) - 1.0;
}

function turn_api_hex_counts_by_pid(): array {
  $repo = api_repo_root();
  $hexDataPath = $repo . '/hexmap/data.js';
  $counts = [];

  if (is_file($hexDataPath)) {
    $raw = @file_get_contents($hexDataPath);
    if (is_string($raw) && preg_match('/"provinces"\s*:\s*(\[.*?\])\s*,\s*"hexes"/s', $raw, $m)) {
      $decoded = json_decode($m[1], true);
      if (is_array($decoded)) {
        foreach ($decoded as $row) {
          if (!is_array($row)) continue;
          $pid = (int)($row['id'] ?? 0);
          $hexCount = (int)($row['hexCount'] ?? 0);
          if ($pid <= 0 || $hexCount <= 0) continue;
          $counts[$pid] = $hexCount;
        }
      }
    }
  }

  if (!$counts) {
    $provMetaPath = $repo . '/provinces.json';
    if (is_file($provMetaPath)) {
      $raw = @file_get_contents($provMetaPath);
      $decoded = is_string($raw) ? json_decode($raw, true) : null;
      if (is_array($decoded) && is_array($decoded['provinces'] ?? null)) {
        foreach ($decoded['provinces'] as $row) {
          if (!is_array($row)) continue;
          $pid = (int)($row['pid'] ?? 0);
          $areaPx = (int)($row['area_px'] ?? 0);
          if ($pid <= 0 || $areaPx <= 0) continue;
          $counts[$pid] = max(1, (int)round($areaPx / 12));
        }
      }
    }
  }

  $remapPath = $repo . '/data/hexmap_pid_remap.json';
  $remapped = [];
  $remap = [];
  if (is_file($remapPath)) {
    $raw = @file_get_contents($remapPath);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded) && is_array($decoded['pid_remap'] ?? null)) {
      $remap = $decoded['pid_remap'];
    }
  }

  foreach ($counts as $sourcePid => $hexCount) {
    $targetPid = (int)($remap[(string)$sourcePid] ?? $sourcePid);
    if ($targetPid <= 0) continue;
    if (!isset($remapped[$targetPid])) $remapped[$targetPid] = 0;
    $remapped[$targetPid] += max(0, (int)$hexCount);
  }
  return $remapped;
}

function turn_api_realm_entity_rows(array $state): array {
  $rows = [];
  foreach (['kingdoms', 'great_houses', 'minor_houses', 'free_cities', 'special_territories'] as $type) {
    foreach (($state[$type] ?? []) as $idx => $realm) {
      if (!is_array($realm)) continue;
      $id = trim((string)($realm['id'] ?? ($realm['name'] ?? (string)$idx)));
      if ($id === '') continue;
      $rows[] = [
        'entity_id' => $type . ':' . $id,
        'name' => (string)($realm['name'] ?? $id),
        'type' => $type,
        'ruler' => (string)($realm['ruler'] ?? ''),
      ];
    }
  }
  usort($rows, static fn($a, $b) => strcmp((string)$a['entity_id'], (string)$b['entity_id']));
  return $rows;
}

function turn_api_compute_entity_state(array $state, int $year): array {
  $entities = turn_api_realm_entity_rows($state);
  $provinceCountByOwner = [];
  foreach (($state['provinces'] ?? []) as $prov) {
    if (!is_array($prov)) continue;
    $owner = trim((string)($prov['owner'] ?? ''));
    if ($owner === '') continue;
    if (!isset($provinceCountByOwner[$owner])) $provinceCountByOwner[$owner] = 0;
    $provinceCountByOwner[$owner]++;
  }

  $out = [];
  foreach ($entities as $row) {
    $name = (string)($row['name'] ?? '');
    $provincesOwned = (int)($provinceCountByOwner[$name] ?? 0);
    $base = 100 + ($provincesOwned * 15) + (($year % 7) * 3);
    $out[] = [
      'turn_year' => $year,
      'entity_id' => $row['entity_id'],
      'entity_name' => $name,
      'entity_type' => $row['type'],
      'ruler' => $row['ruler'],
      'provinces_owned' => $provincesOwned,
      'treasury_main' => $base,
      'treasury_reserve' => round($base * 0.15, 2),
    ];
  }
  return $out;
}

function turn_api_state_treaties(array $state): array {
  $rows = $state['treaties'] ?? null;
  if (!is_array($rows)) return [];

  $out = [];
  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $id = trim((string)($row['id'] ?? ''));
    if ($id === '') continue;
    $out[] = $row;
  }
  return $out;
}

function turn_api_treaty_modifiers(array $row): array {
  $mods = is_array($row['modifiers'] ?? null) ? (array)$row['modifiers'] : [];
  return [
    'tax_modifier' => is_numeric($mods['tax_modifier'] ?? null) ? (float)$mods['tax_modifier'] : 0.0,
    'trade_flow' => is_numeric($mods['trade_flow'] ?? null) ? (float)$mods['trade_flow'] : 0.0,
    'tariffs' => is_numeric($mods['tariffs'] ?? null) ? (float)$mods['tariffs'] : 0.0,
    'subsidies' => is_numeric($mods['subsidies'] ?? null) ? (float)$mods['subsidies'] : 0.0,
  ];
}

function turn_api_treaty_status_for_year(array $row, int $year): string {
  $effectiveYear = (int)($row['effective_year'] ?? 0);
  $durationYears = (int)($row['duration_years'] ?? 0);
  $breachYear = (int)($row['breach_year'] ?? 0);

  if ($effectiveYear <= 0 || $year < $effectiveYear) return 'draft';
  if ($breachYear > 0 && $year >= $breachYear) return 'breached';
  if ($durationYears > 0 && $year >= ($effectiveYear + $durationYears)) return 'expired';
  return 'active';
}

function turn_api_treaty_effects_by_entity(array $state, int $year): array {
  $entityRows = turn_api_realm_entity_rows($state);
  $entityNames = [];
  foreach ($entityRows as $entityRow) {
    if (!is_array($entityRow)) continue;
    $entityNames[(string)($entityRow['name'] ?? '')] = (string)($entityRow['entity_id'] ?? '');
  }

  $effects = [];
  foreach (turn_api_state_treaties($state) as $treaty) {
    $status = turn_api_treaty_status_for_year($treaty, $year);
    if ($status !== 'active') continue;

    $sidesRaw = is_array($treaty['sides'] ?? null) ? (array)$treaty['sides'] : [];
    $sides = [];
    foreach ($sidesRaw as $sideRaw) {
      $side = trim((string)$sideRaw);
      if ($side === '') continue;
      if (isset($entityNames[$side]) && $entityNames[$side] !== '') $side = (string)$entityNames[$side];
      $sides[$side] = true;
    }
    if (count($sides) < 2) continue;

    $mods = turn_api_treaty_modifiers($treaty);
    foreach (array_keys($sides) as $entityId) {
      if (!isset($effects[$entityId])) {
        $effects[$entityId] = [
          'tax_modifier' => 0.0,
          'trade_flow' => 0.0,
          'tariffs' => 0.0,
          'subsidies' => 0.0,
          'treaty_ids' => [],
        ];
      }
      $effects[$entityId]['tax_modifier'] += $mods['tax_modifier'];
      $effects[$entityId]['trade_flow'] += $mods['trade_flow'];
      $effects[$entityId]['tariffs'] += $mods['tariffs'];
      $effects[$entityId]['subsidies'] += $mods['subsidies'];
      $effects[$entityId]['treaty_ids'][] = (string)$treaty['id'];
    }
  }

  foreach ($effects as $entityId => $effect) {
    $effects[$entityId]['tax_modifier'] = round((float)$effect['tax_modifier'], 4);
    $effects[$entityId]['trade_flow'] = round((float)$effect['trade_flow'], 4);
    $effects[$entityId]['tariffs'] = round((float)$effect['tariffs'], 4);
    $effects[$entityId]['subsidies'] = round((float)$effect['subsidies'], 4);
    $effects[$entityId]['treaty_ids'] = array_values(array_unique(array_map('strval', (array)$effect['treaty_ids'])));
  }

  return $effects;
}

function turn_api_treaty_lifecycle_events(array $state, int $year): array {
  $events = [];
  foreach (turn_api_state_treaties($state) as $treaty) {
    $id = (string)($treaty['id'] ?? '');
    if ($id === '') continue;
    $status = turn_api_treaty_status_for_year($treaty, $year);
    $prevStatus = turn_api_treaty_status_for_year($treaty, $year - 1);
    if ($status === $prevStatus) continue;

    $events[] = [
      'category' => 'diplomacy',
      'event_type' => 'treaty_status_changed',
      'payload' => [
        'turn_year' => $year,
        'treaty_id' => $id,
        'treaty_type' => (string)($treaty['type'] ?? ''),
        'previous_status' => $prevStatus,
        'status' => $status,
      ],
      'occurred_at' => gmdate('c'),
    ];
  }
  return $events;
}

function turn_api_compute_economy_state(array $state, int $year, array $ruleset): array {
  $hexCountsByPid = turn_api_hex_counts_by_pid();
  $entityRows = turn_api_realm_entity_rows($state);
  $ownerToEntityId = [];
  foreach ($entityRows as $entityRow) {
    if (!is_array($entityRow)) continue;
    $ownerToEntityId[(string)($entityRow['name'] ?? '')] = (string)($entityRow['entity_id'] ?? '');
  }
  $treatyEffectsByEntity = turn_api_treaty_effects_by_entity($state, $year);
  $ecoCfg = (array)($ruleset['economy'] ?? []);
  $terrainIncome = (array)($ecoCfg['terrain_income_coefficients'] ?? []);
  $terrainExpense = (array)($ecoCfg['terrain_expense_coefficients'] ?? []);
  $out = [];
  foreach (($state['provinces'] ?? []) as $idx => $prov) {
    if (!is_array($prov)) continue;
    $pid = (int)($prov['pid'] ?? $idx);
    if ($pid <= 0) continue;
    $terrainRaw = (string)($prov['terrain'] ?? '');
    $terrain = turn_api_terrain_key($terrainRaw);
    $hexCount = max(1, (int)($hexCountsByPid[$pid] ?? 1));
    $population = max(0, (int)($prov['population'] ?? 0));

    $incomeCoef = (float)($terrainIncome[$terrain] ?? 1.0);
    $expenseCoef = (float)($terrainExpense[$terrain] ?? 1.0);
    $isCity = $terrain === 'город';
    $isCoast = $terrain === 'побережье' || $terrain === 'остров';
    $isRiver = $terrain === 'озёра/реки';
    $tradeBonus = 1.0;
    if ($isCoast) $tradeBonus += (float)($ecoCfg['coastal_trade_bonus'] ?? 0.0);
    if ($isRiver) $tradeBonus += (float)($ecoCfg['river_trade_bonus'] ?? 0.0);

    $incomePerHex = (float)($ecoCfg['base_income_per_hex'] ?? 520.0);
    $expensePerHex = (float)($ecoCfg['base_expense_per_hex'] ?? 290.0);
    $incomeFromHexes = $incomePerHex * $hexCount * $incomeCoef * $tradeBonus;
    $expenseFromHexes = $expensePerHex * $hexCount * $expenseCoef;

    $perCapita = $isCity
      ? (float)($ecoCfg['city_population_income_per_capita'] ?? 0.22)
      : (float)($ecoCfg['population_income_per_capita'] ?? 0.09);
    $incomeFromPopulation = $population * $perCapita;

    $incomeNoise = turn_api_seeded_noise($year, $pid, 'income');
    $expenseNoise = turn_api_seeded_noise($year, $pid, 'expense');
    $incomeRandomSwing = (float)($ecoCfg['income_random_swing'] ?? 0.16);
    $expenseRandomSwing = (float)($ecoCfg['expense_random_swing'] ?? 0.12);

    $income = ($incomeFromHexes + $incomeFromPopulation) * (1.0 + ($incomeNoise * $incomeRandomSwing));
    $expense = $expenseFromHexes * (1.0 + ($expenseNoise * $expenseRandomSwing));

    $owner = trim((string)($prov['owner'] ?? ''));
    $entityId = (string)($ownerToEntityId[$owner] ?? '');
    $treatyMods = is_array($treatyEffectsByEntity[$entityId] ?? null) ? $treatyEffectsByEntity[$entityId] : null;
    if (is_array($treatyMods)) {
      $tariffRate = max(0.0, min(0.95, (float)($treatyMods['tariffs'] ?? 0.0)));
      $incomeMult = (1.0 + (float)($treatyMods['tax_modifier'] ?? 0.0))
        * (1.0 + (float)($treatyMods['trade_flow'] ?? 0.0))
        * (1.0 + (float)($treatyMods['subsidies'] ?? 0.0))
        * (1.0 - $tariffRate);
      $income = $income * max(0.0, $incomeMult);
    }

    $arrierbanIncomePenalty = max(0.0, min(1.0, ((float)($prov['arrierban_income_penalty'] ?? 0.0)) * 10.0));
    if ($arrierbanIncomePenalty > 0.0) {
      $income = $income * (1.0 - $arrierbanIncomePenalty);
    }

    $out[] = [
      'turn_year' => $year,
      'province_pid' => $pid,
      'income' => round($income, 2),
      'expense' => round($expense, 2),
      'balance_delta' => round($income - $expense, 2),
      'modifiers' => [
        'terrain' => $terrainRaw,
        'terrain_key' => $terrain,
        'ruleset_version' => (string)($ruleset['version'] ?? ''),
        'hex_count' => $hexCount,
        'population' => $population,
        'coastal_trade' => $isCoast,
        'river_trade' => $isRiver,
        'income_noise' => round($incomeNoise, 4),
        'expense_noise' => round($expenseNoise, 4),
        'arrierban_income_penalty' => round($arrierbanIncomePenalty, 4),
        'treaty_effects' => $treatyMods ? [
          'entity_id' => $entityId,
          'tax_modifier' => round((float)($treatyMods['tax_modifier'] ?? 0.0), 4),
          'trade_flow' => round((float)($treatyMods['trade_flow'] ?? 0.0), 4),
          'tariffs' => round((float)($treatyMods['tariffs'] ?? 0.0), 4),
          'subsidies' => round((float)($treatyMods['subsidies'] ?? 0.0), 4),
          'treaty_ids' => array_values((array)($treatyMods['treaty_ids'] ?? [])),
        ] : null,
      ],
    ];
  }
  usort($out, static fn($a, $b) => ((int)$a['province_pid'] <=> (int)$b['province_pid']));
  return $out;
}

function turn_api_generate_start_population_and_treasury(array &$state): array {
  $hexCountsByPid = turn_api_hex_counts_by_pid();
  $updated = 0;
  $populationTotal = 0;
  $treasuryTotal = 0.0;

  foreach (($state['provinces'] ?? []) as $idx => $prov) {
    if (!is_array($prov)) continue;
    $pid = (int)($prov['pid'] ?? $idx);
    if ($pid <= 0) continue;
    $hexCount = max(1, (int)($hexCountsByPid[$pid] ?? 1));
    $terrain = turn_api_terrain_key((string)($prov['terrain'] ?? ''));
    $isCity = $terrain === 'город';

    $basePerHex = 500 + (int)round((turn_api_seeded_noise(1, $pid, 'population_per_hex') + 1.0) * 250.0);
    $population = (int)round(($hexCount * $basePerHex) / 5);
    if ($isCity) {
      $cityMult = 4.0 + ((turn_api_seeded_noise(1, $pid, 'city_pop_boost') + 1.0) * 2.0);
      $population = (int)round($population * $cityMult);
    }

    $treasuryBase = ($population * ($isCity ? 1.55 : 0.72)) + ($hexCount * (900 + (turn_api_seeded_noise(1, $pid, 'treasury_hex') * 280.0)));
    $treasury = max(500.0, round($treasuryBase, 2));

    $state['provinces'][(string)$idx]['population'] = (int)$population;
    $state['provinces'][(string)$idx]['treasury'] = $treasury;

    $updated++;
    $populationTotal += (int)$population;
    $treasuryTotal += $treasury;
  }

  return [
    'updated' => $updated,
    'population_total' => $populationTotal,
    'treasury_total' => round($treasuryTotal, 2),
  ];
}

function turn_api_compute_economy_summary(array $economyState): array {
  $records = count($economyState);
  $income = 0.0;
  $expense = 0.0;
  foreach ($economyState as $row) {
    $income += (float)($row['income'] ?? 0.0);
    $expense += (float)($row['expense'] ?? 0.0);
  }
  return [
    'status' => 'processed',
    'checkpoint' => 'checkpoint:economy_applied',
    'records' => $records,
    'income_total' => round($income, 2),
    'expense_total' => round($expense, 2),
    'balance_delta' => round($income - $expense, 2),
    'checksum' => hash('sha256', json_encode($economyState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
  ];
}

function turn_api_source_state_for_new_turn(int $sourceYear, bool $preferMapState = false): array {
  if ($preferMapState) {
    return api_load_state();
  }
  if ($sourceYear > 0) {
    $source = turn_api_load_turn($sourceYear);
    if (is_array($source) && (($source['status'] ?? '') === 'published')) {
      $snap = turn_api_load_snapshot($sourceYear, 'end');
      if (is_array($snap) && is_array($snap['payload']['world_state'] ?? null)) {
        return $snap['payload']['world_state'];
      }
    }
  }
  return api_load_state();
}

function turn_api_build_base_turn(int $sourceYear, int $targetYear, string $rulesetVersion, bool $preferMapState = false): array {
  $worldState = turn_api_source_state_for_new_turn($sourceYear, $preferMapState);
  $treaties = turn_api_state_treaties($worldState);
  $previousEntityTreasury = [];
  $previousProvinceTreasury = [];
  if ($sourceYear > 0) {
    $sourceSnap = turn_api_load_snapshot($sourceYear, 'end');
    if (is_array($sourceSnap) && is_array($sourceSnap['payload'] ?? null)) {
      $payload = (array)$sourceSnap['payload'];
      if (is_array($payload['entity_treasury'] ?? null)) {
        $previousEntityTreasury = array_values((array)$payload['entity_treasury']);
      }
      if (is_array($payload['province_treasury'] ?? null)) {
        $previousProvinceTreasury = array_values((array)$payload['province_treasury']);
      }
    }
  }
  $startSnapshotRef = turn_api_save_snapshot($targetYear, 'start', [
    'world_state' => $worldState,
    'entity_state' => turn_api_compute_entity_state($worldState, $targetYear),
    'economy_state' => turn_api_compute_economy_state($worldState, $targetYear, turn_api_ruleset_for_turn(['ruleset_version' => $rulesetVersion])),
    'treaties' => $treaties,
    'source_turn_year' => $sourceYear,
    'previous_entity_treasury' => $previousEntityTreasury,
    'previous_province_treasury' => $previousProvinceTreasury,
  ]);
  if (!is_array($startSnapshotRef)) {
    turn_api_response(['error' => 'snapshot_write_failed', 'phase' => 'start'], 500);
  }

  return [
    'id' => 'turn-' . $targetYear,
    'year' => $targetYear,
    'status' => 'draft',
    'ruleset_version' => $rulesetVersion,
    'source_turn_year' => $sourceYear,
    'created_at' => gmdate('c'),
    'snapshot_start' => $startSnapshotRef,
    'snapshot_end' => null,
    'entity_state' => ['status' => 'captured_start', 'records' => count(turn_api_compute_entity_state($worldState, $targetYear))],
    'economy_state' => ['status' => 'captured_start', 'records' => count(turn_api_compute_economy_state($worldState, $targetYear, turn_api_ruleset_for_turn(['ruleset_version' => $rulesetVersion])))],
    'economy' => ['status' => 'not_processed', 'checkpoint' => null, 'records' => 0, 'checksum' => null],
    'entity_treasury' => ['status' => 'not_processed', 'records' => 0, 'checksum' => null],
    'province_treasury' => ['status' => 'not_processed', 'records' => 0, 'checksum' => null],
    'treasury_ledger' => ['status' => 'not_processed', 'records' => 0, 'checksum' => null],
    'treaties' => [
      'status' => 'captured_start',
      'records' => count($treaties),
      'active_records' => count(array_filter($treaties, static fn($row) => turn_api_treaty_status_for_year((array)$row, $targetYear) === 'active')),
      'checksum' => hash('sha256', json_encode($treaties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    ],
    'map_artifacts' => [],
    'events' => [],
  ];
}

function turn_api_compute_economy_for_turn(array $turn): array {
  $year = (int)($turn['year'] ?? 0);
  $snap = turn_api_load_snapshot($year, 'start');
  $payload = (is_array($snap) && is_array($snap['payload'] ?? null)) ? (array)$snap['payload'] : [];
  $state = is_array($payload['world_state'] ?? null) ? $payload['world_state'] : api_load_state();
  $previousEntityClosingById = turn_api_previous_entity_closing_by_id(is_array($payload['previous_entity_treasury'] ?? null) ? (array)$payload['previous_entity_treasury'] : []);
  $previousProvinceClosingByPid = turn_api_previous_province_closing_by_pid(is_array($payload['previous_province_treasury'] ?? null) ? (array)$payload['previous_province_treasury'] : []);

  $ruleset = turn_api_ruleset_for_turn($turn);
  $entityState = turn_api_compute_entity_state($state, $year);
  $treaties = turn_api_state_treaties($state);
  $economyState = turn_api_compute_economy_state($state, $year, $ruleset);
  $economy = turn_api_compute_economy_summary($economyState);
  $economy['ruleset_version'] = (string)($ruleset['version'] ?? '');
  $treasury = turn_api_compute_treasury($state, $entityState, $economyState, $year, $ruleset, $previousEntityClosingById, $previousProvinceClosingByPid);

  return [
    'entity_state' => $entityState,
    'economy_state' => $economyState,
    'economy' => $economy,
    'entity_treasury_rows' => $treasury['entity_treasury_rows'],
    'province_treasury_rows' => $treasury['province_treasury_rows'],
    'ledger_rows' => $treasury['ledger_rows'],
    'treasury_summary' => $treasury['summary'],
    'treaties' => $treaties,
    'treaty_events' => turn_api_treaty_lifecycle_events($state, $year),
  ];
}

function turn_api_build_map_artifacts(): array {
  $repo = api_repo_root();
  $out = [];
  $artifacts = [
    ['kind' => 'classic_map', 'path' => 'map.png', 'content_type' => 'image/png'],
    ['kind' => 'hex_map', 'path' => 'provinces_id.png', 'content_type' => 'image/png'],
  ];
  foreach ($artifacts as $item) {
    $full = $repo . '/' . $item['path'];
    if (!is_file($full)) continue;
    $out[] = [
      'kind' => $item['kind'],
      'artifact_ref' => $item['path'],
      'content_type' => $item['content_type'],
      'checksum' => hash_file('sha256', $full) ?: null,
    ];
  }
  return $out;
}



function turn_api_previous_entity_closing_by_id(array $rows): array {
  $out = [];
  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $entityId = trim((string)($row['entity_id'] ?? ''));
    if ($entityId === '') continue;
    $closing = $row['closing_balance'] ?? null;
    if (!is_numeric($closing)) continue;
    $out[$entityId] = round((float)$closing, 2);
  }
  return $out;
}

function turn_api_previous_province_closing_by_pid(array $rows): array {
  $out = [];
  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $pid = (int)($row['province_pid'] ?? 0);
    if ($pid <= 0) continue;
    $closing = $row['closing_balance'] ?? null;
    if (!is_numeric($closing)) continue;
    $out[$pid] = round((float)$closing, 2);
  }
  return $out;
}

function turn_api_compute_treasury(
  array $state,
  array $entityState,
  array $economyState,
  int $year,
  array $ruleset,
  array $previousEntityClosingById = [],
  array $previousProvinceClosingByPid = []
): array {
  $kingdomRulingHouseById = [];
  $kingdomVassalHousesById = [];
  foreach (($state['kingdoms'] ?? []) as $kingdomId => $kingdom) {
    if (!is_array($kingdom)) continue;
    $kid = trim((string)$kingdomId);
    if ($kid === '') continue;

    $rulingHouseId = trim((string)($kingdom['ruling_house_id'] ?? ''));
    if ($rulingHouseId !== '') {
      $kingdomRulingHouseById[$kid] = $rulingHouseId;
    }

    $vassalIds = [];
    foreach ((array)($kingdom['vassal_house_ids'] ?? []) as $ghIdRaw) {
      $ghId = trim((string)$ghIdRaw);
      if ($ghId !== '') $vassalIds[$ghId] = true;
    }
    if ($vassalIds !== []) $kingdomVassalHousesById[$kid] = array_keys($vassalIds);
  }

  $entityRows = [];
  $entityMetaById = [];
  foreach ($entityState as $row) {
    if (!is_array($row)) continue;
    $entityId = (string)($row['entity_id'] ?? '');
    if ($entityId === '') continue;
    if ((string)($row['entity_type'] ?? '') === 'kingdoms') continue;
    $opening = isset($previousEntityClosingById[$entityId]) && is_numeric($previousEntityClosingById[$entityId])
      ? round((float)$previousEntityClosingById[$entityId], 2)
      : round((float)($row['treasury_main'] ?? 0), 2);
    $entityRows[$entityId] = [
      'turn_year' => $year,
      'entity_id' => $entityId,
      'entity_name' => (string)($row['entity_name'] ?? ''),
      'entity_type' => (string)($row['entity_type'] ?? ''),
      'opening_balance' => $opening,
      'income_tax' => 0.0,
      'subsidies_out' => 0.0,
      'army_upkeep_out' => 0.0,
      'transfers_in' => 0.0,
      'transfers_out' => 0.0,
      'closing_balance' => 0.0,
    ];
    $entityMetaById[$entityId] = [
      'entity_name' => (string)($row['entity_name'] ?? ''),
      'entity_type' => (string)($row['entity_type'] ?? ''),
    ];
  }

  $ensureEntityRow = static function (array &$rows, array &$meta, string $entityId, string $entityName, string $entityType, int $year): void {
    if ($entityId === '') return;
    if (!isset($rows[$entityId])) {
      $rows[$entityId] = [
        'turn_year' => $year,
        'entity_id' => $entityId,
        'entity_name' => $entityName,
        'entity_type' => $entityType,
        'opening_balance' => 0.0,
        'income_tax' => 0.0,
        'subsidies_out' => 0.0,
        'army_upkeep_out' => 0.0,
        'transfers_in' => 0.0,
        'transfers_out' => 0.0,
        'closing_balance' => 0.0,
      ];
    }
    if ((string)($rows[$entityId]['entity_type'] ?? '') === '' && $entityType !== '') {
      $rows[$entityId]['entity_type'] = $entityType;
    }
    $meta[$entityId] = ['entity_name' => $entityName, 'entity_type' => $entityType];
  };

  $ownerToEntityId = [];
  foreach ($entityRows as $eid => $row) {
    $ownerToEntityId[(string)($row['entity_name'] ?? '')] = $eid;
  }

  $tCfg = (array)($ruleset['treasury'] ?? []);
  $defaultProvinceTaxRate = (float)($tCfg['province_entity_tax_rate'] ?? 0.10);
  $minorCapitalIncomeShare = (float)($tCfg['minor_capital_income_share'] ?? 1.0);
  $minorProvinceIncomeShare = (float)($tCfg['minor_province_income_share'] ?? 0.10);
  $minorToGreatRate = (float)($tCfg['minor_to_great_rate'] ?? 0.10);
  $greatDomainIncomeShare = (float)($tCfg['great_domain_income_share'] ?? 1.0);
  $greatToKingdomRate = (float)($tCfg['great_to_kingdom_rate'] ?? 0.10);
  $kingdomVassalHouseTaxRate = (float)($tCfg['kingdom_vassal_house_tax_rate'] ?? $greatToKingdomRate);
  $royalCallupVassalUpkeepShare = max(0.0, min(1.0, (float)($tCfg['royal_callup_vassal_upkeep_share'] ?? 0.7)));

  $armyUpkeepPerStrength = max(0.0, (float)($tCfg['army_upkeep_per_strength'] ?? 1.15));

  $addUpkeepShare = static function (array &$shares, string $entityId, float $amount): void {
    if ($entityId === '' || $amount <= 0.0) return;
    $shares[$entityId] = ((float)($shares[$entityId] ?? 0.0)) + $amount;
  };

  $resolveVassalEntityId = static function (string $bucketType, string $summonerKey, string $armyId): string {
    if ($bucketType === 'great_houses') {
      if (strpos($armyId, 'vassal:') === 0) {
        $vassalId = trim(substr($armyId, strlen('vassal:')));
        if ($vassalId === '') return '';
        return 'minor_houses:' . $summonerKey . '::' . $vassalId;
      }
      if (strpos($armyId, 'vassal_great_house:') === 0) {
        $greatHouseId = trim(substr($armyId, strlen('vassal_great_house:')));
        if ($greatHouseId === '') return '';
        return 'great_houses:' . $greatHouseId;
      }
      return '';
    }
    if ($bucketType === 'kingdoms') {
      if (strpos($armyId, 'vassal_great_house:') !== 0) return '';
      $greatHouseId = trim(substr($armyId, strlen('vassal_great_house:')));
      if ($greatHouseId === '') return '';
      return 'great_houses:' . $greatHouseId;
    }
    return '';
  };

  $computeArmyUpkeepShares = static function (array $realm, string $bucketType, string $summonerKey, float $upkeepPerStrength) use ($addUpkeepShare, $resolveVassalEntityId, $kingdomRulingHouseById, $royalCallupVassalUpkeepShare): array {
    $shares = [];
    $summonerEntityId = $bucketType . ':' . $summonerKey;
    if ($bucketType === 'kingdoms') {
      $rulingHouseId = (string)($kingdomRulingHouseById[$summonerKey] ?? '');
      if ($rulingHouseId !== '') $summonerEntityId = 'great_houses:' . $rulingHouseId;
    }

    foreach ((array)($realm['arrierban_units'] ?? []) as $unit) {
      if (!is_array($unit)) continue;
      $size = max(0.0, (float)($unit['size'] ?? 0.0));
      if ($size <= 0.0) continue;
      $addUpkeepShare($shares, $summonerEntityId, $size * $upkeepPerStrength);
    }

    $vassalArmies = (array)($realm['arrierban_vassal_armies'] ?? []);
    if ($vassalArmies !== []) {
      foreach ($vassalArmies as $army) {
        if (!is_array($army)) continue;
        $armyKind = trim((string)($army['army_kind'] ?? ''));
        $armyId = trim((string)($army['army_id'] ?? ''));
        $vassalEntityId = $resolveVassalEntityId($bucketType, $summonerKey, $armyId);
        foreach ((array)($army['units'] ?? []) as $unit) {
          if (!is_array($unit)) continue;
          $size = max(0.0, (float)($unit['size'] ?? 0.0));
          if ($size <= 0.0) continue;
          $unitUpkeep = $size * $upkeepPerStrength;
          if ($armyKind === 'vassal' && $vassalEntityId !== '') {
            $vassalShare = ($bucketType === 'kingdoms') ? $royalCallupVassalUpkeepShare : 0.5;
            $summonerShare = max(0.0, 1.0 - $vassalShare);
            $addUpkeepShare($shares, $summonerEntityId, $unitUpkeep * $summonerShare);
            $addUpkeepShare($shares, $vassalEntityId, $unitUpkeep * $vassalShare);
          } else {
            $addUpkeepShare($shares, $summonerEntityId, $unitUpkeep);
          }
        }
      }
    } else {
      foreach ((array)($realm['arrierban_vassal_units'] ?? []) as $unit) {
        if (!is_array($unit)) continue;
        $size = max(0.0, (float)($unit['size'] ?? 0.0));
        if ($size <= 0.0) continue;
        $addUpkeepShare($shares, $summonerEntityId, $size * $upkeepPerStrength);
      }
    }

    return $shares;
  };

  $provinceByPid = [];
  foreach (($state['provinces'] ?? []) as $prov) {
    if (!is_array($prov)) continue;
    $pid = (int)($prov['pid'] ?? 0);
    if ($pid <= 0) continue;
    $provinceByPid[$pid] = $prov;
  }

  $greatLayerById = [];
  $minorByKey = [];
  foreach (($state['great_houses'] ?? []) as $ghId => $gh) {
    if (!is_array($gh)) continue;
    $greatId = trim((string)$ghId);
    if ($greatId === '') continue;
    $greatEntityId = 'great_houses:' . $greatId;
    $greatName = (string)($gh['name'] ?? $greatId);
    $ensureEntityRow($entityRows, $entityMetaById, $greatEntityId, $greatName, 'great_houses', $year);

    $layer = is_array($gh['minor_house_layer'] ?? null) ? (array)$gh['minor_house_layer'] : [];
    $domainPids = [];
    foreach ((array)($layer['domain_pids'] ?? []) as $pidRaw) {
      $pid = (int)$pidRaw;
      if ($pid > 0) $domainPids[$pid] = true;
    }
    $greatLayerById[$greatId] = ['domain_pids' => $domainPids, 'vassals' => (array)($layer['vassals'] ?? [])];

    foreach ((array)($layer['vassals'] ?? []) as $idx => $vassal) {
      if (!is_array($vassal)) continue;
      $minorId = trim((string)($vassal['id'] ?? ('vassal_' . ($idx + 1))));
      if ($minorId === '') continue;
      $minorEntityId = 'minor_houses:' . $greatId . '::' . $minorId;
      $minorName = (string)($vassal['name'] ?? $minorId);
      $minorCapitalPid = (int)($vassal['capital_pid'] ?? 0);
      $minorProvincePids = [];
      foreach ((array)($vassal['province_pids'] ?? []) as $pidRaw) {
        $pid = (int)$pidRaw;
        if ($pid > 0) $minorProvincePids[$pid] = true;
      }
      $minorKey = $greatId . '::' . $minorId;
      $minorByKey[$minorKey] = [
        'entity_id' => $minorEntityId,
        'entity_name' => $minorName,
        'great_house_id' => $greatId,
        'capital_pid' => $minorCapitalPid,
        'province_pids' => $minorProvincePids,
      ];
      $ensureEntityRow($entityRows, $entityMetaById, $minorEntityId, $minorName, 'minor_houses', $year);
    }
  }

  $minorByProvincePid = [];
  foreach ($minorByKey as $minorKey => $minor) {
    foreach ((array)($minor['province_pids'] ?? []) as $pid => $_) {
      $minorByProvincePid[(int)$pid] = $minorKey;
    }
  }

  $provinceRows = [];
  $ledger = [];
  $ledgerSeq = 0;
  $minorGrossIncome = [];
  $greatGrossIncome = [];
  $greatOverlordHouseById = [];
  foreach ($economyState as $eco) {
    if (!is_array($eco)) continue;
    $pid = (int)($eco['province_pid'] ?? 0);
    if ($pid <= 0) continue;
    $income = (float)($eco['income'] ?? 0.0);
    $expense = (float)($eco['expense'] ?? 0.0);
    $reserveAdd = round($income * (float)($tCfg['province_reserve_rate'] ?? 0.10), 2);

    $owner = '';
    $provinceName = '';
    $terrain = '';
    $provinceTaxRate = null;
    foreach (($state['provinces'] ?? []) as $prov) {
      if (!is_array($prov)) continue;
      if ((int)($prov['pid'] ?? 0) !== $pid) continue;
      $owner = (string)($prov['owner'] ?? '');
      $provinceName = (string)($prov['name'] ?? '');
      $terrain = (string)($prov['terrain'] ?? '');
      if (array_key_exists('tax_rate', $prov) && is_numeric($prov['tax_rate'])) {
        $provinceTaxRate = (float)$prov['tax_rate'];
      }
      break;
    }

    $greatHouseId = trim((string)($provinceByPid[$pid]['great_house_id'] ?? ''));
    $minorHouseId = trim((string)($provinceByPid[$pid]['minor_house_id'] ?? ''));
    $kingdomId = trim((string)($provinceByPid[$pid]['kingdom_id'] ?? ''));
    if ($greatHouseId !== '' && $kingdomId !== '' && !isset($greatOverlordHouseById[$greatHouseId])) {
      $overlordHouseId = (string)($kingdomRulingHouseById[$kingdomId] ?? '');
      $explicit = (array)($kingdomVassalHousesById[$kingdomId] ?? []);
      if ($overlordHouseId !== '' && $overlordHouseId !== $greatHouseId && in_array($greatHouseId, $explicit, true)) {
        $greatOverlordHouseById[$greatHouseId] = $overlordHouseId;
      }
    }

    $minorKey = '';
    if ($greatHouseId !== '' && $minorHouseId !== '') {
      $candidate = $greatHouseId . '::' . $minorHouseId;
      if (isset($minorByKey[$candidate])) $minorKey = $candidate;
    }
    if ($minorKey === '' && isset($minorByProvincePid[$pid])) $minorKey = (string)$minorByProvincePid[$pid];

    $defaultTaxRate = $defaultProvinceTaxRate;
    $taxRate = ($provinceTaxRate !== null && is_finite($provinceTaxRate) && $provinceTaxRate >= 0.0) ? $provinceTaxRate : $defaultTaxRate;
    $tax = round($income * $taxRate, 2);

    $targetEntityId = '';
    if ($minorKey !== '' && isset($minorByKey[$minorKey])) {
      $targetEntityId = (string)$minorByKey[$minorKey]['entity_id'];
    } elseif ($greatHouseId !== '') {
      $targetEntityId = 'great_houses:' . $greatHouseId;
      $ensureEntityRow($entityRows, $entityMetaById, $targetEntityId, $greatHouseId, 'great_houses', $year);
    } else {
      $targetEntityId = (string)($ownerToEntityId[$owner] ?? '');
    }
    $appliedTax = 0.0;
    $entityIncomeSharePaid = 0.0;
    if ($targetEntityId !== '' && isset($entityRows[$targetEntityId])) {
      $appliedTax = $tax;
      $entityRows[$targetEntityId]['income_tax'] = round(((float)$entityRows[$targetEntityId]['income_tax']) + $appliedTax, 2);
      $ledgerSeq++;
      $ledger[] = [
        'turn_year' => $year,
        'entry_id' => 'L-' . $year . '-P2E-' . str_pad((string)$ledgerSeq, 6, '0', STR_PAD_LEFT),
        'type' => 'province_to_entity_tax',
        'from' => 'province:' . $pid,
        'to' => 'entity:' . $targetEntityId,
        'amount' => $appliedTax,
        'debit_account' => 'entity:' . $targetEntityId,
        'credit_account' => 'province:' . $pid,
        'reason' => 'tax_collection',
      ];

      if (isset($entityMetaById[$targetEntityId]) && (($entityMetaById[$targetEntityId]['entity_type'] ?? '') === 'minor_houses')) {
        $minorGrossIncome[$targetEntityId] = round(((float)($minorGrossIncome[$targetEntityId] ?? 0.0)) + $appliedTax, 2);
      }
      if (isset($entityMetaById[$targetEntityId]) && (($entityMetaById[$targetEntityId]['entity_type'] ?? '') === 'great_houses')) {
        $greatGrossIncome[$targetEntityId] = round(((float)($greatGrossIncome[$targetEntityId] ?? 0.0)) + $appliedTax, 2);
      }
    }

    $entityShareBase = max(0.0, round($income - $expense - $reserveAdd - $appliedTax, 2));
    if ($minorKey !== '' && isset($minorByKey[$minorKey])) {
      $minor = $minorByKey[$minorKey];
      $isMinorCapital = ((int)($minor['capital_pid'] ?? 0) > 0) && ((int)$minor['capital_pid'] === $pid);
      $minorShare = round($entityShareBase * ($isMinorCapital ? $minorCapitalIncomeShare : $minorProvinceIncomeShare), 2);
      if ($minorShare > 0) {
        $minorEntityId = (string)$minor['entity_id'];
        $entityIncomeSharePaid = round($entityIncomeSharePaid + $minorShare, 2);
        $entityRows[$minorEntityId]['income_tax'] = round(((float)$entityRows[$minorEntityId]['income_tax']) + $minorShare, 2);
        $minorGrossIncome[$minorEntityId] = round(((float)($minorGrossIncome[$minorEntityId] ?? 0.0)) + $minorShare, 2);
        $ledgerSeq++;
        $ledger[] = [
          'turn_year' => $year,
          'entry_id' => 'L-' . $year . '-P2M-' . str_pad((string)$ledgerSeq, 6, '0', STR_PAD_LEFT),
          'type' => 'province_to_minor_income_share',
          'from' => 'province:' . $pid,
          'to' => 'entity:' . $minorEntityId,
          'amount' => $minorShare,
          'debit_account' => 'entity:' . $minorEntityId,
          'credit_account' => 'province:' . $pid,
          'reason' => $isMinorCapital ? 'minor_capital_income' : 'minor_vassal_income_share',
        ];
      }
    } elseif ($greatHouseId !== '') {
      $greatEntityId = 'great_houses:' . $greatHouseId;
      $isUnassigned = ($minorHouseId === '' || $minorKey === '');
      $inDomain = $isUnassigned;
      if (isset($greatLayerById[$greatHouseId])) {
        $inDomain = isset($greatLayerById[$greatHouseId]['domain_pids'][$pid]) || $isUnassigned;
      }
      if ($inDomain) {
        $greatShare = round($entityShareBase * $greatDomainIncomeShare, 2);
        if ($greatShare > 0 && isset($entityRows[$greatEntityId])) {
          $entityIncomeSharePaid = round($entityIncomeSharePaid + $greatShare, 2);
          $entityRows[$greatEntityId]['income_tax'] = round(((float)$entityRows[$greatEntityId]['income_tax']) + $greatShare, 2);
          $greatGrossIncome[$greatEntityId] = round(((float)($greatGrossIncome[$greatEntityId] ?? 0.0)) + $greatShare, 2);
          $ledgerSeq++;
          $ledger[] = [
            'turn_year' => $year,
            'entry_id' => 'L-' . $year . '-P2G-' . str_pad((string)$ledgerSeq, 6, '0', STR_PAD_LEFT),
            'type' => 'province_to_great_domain_income',
            'from' => 'province:' . $pid,
            'to' => 'entity:' . $greatEntityId,
            'amount' => $greatShare,
            'debit_account' => 'entity:' . $greatEntityId,
            'credit_account' => 'province:' . $pid,
            'reason' => 'great_domain_income',
          ];
        }
      }
    }

    $net = round($income - $expense - $appliedTax - $entityIncomeSharePaid - $reserveAdd, 2);
    $openingShare = (float)((($ruleset['treasury'] ?? [])['province_opening_income_share'] ?? 0.2));
    $openingBalance = isset($previousProvinceClosingByPid[$pid]) && is_numeric($previousProvinceClosingByPid[$pid])
      ? round((float)$previousProvinceClosingByPid[$pid], 2)
      : round($income * $openingShare, 2);
    $provinceRows[] = [
      'turn_year' => $year,
      'province_pid' => $pid,
      'province_name' => $provinceName,
      'owner_name' => $owner,
      'opening_balance' => $openingBalance,
      'income' => round($income, 2),
      'expense' => round($expense, 2),
      'tax_paid_to_entity' => $appliedTax,
      'entity_income_share_paid' => $entityIncomeSharePaid,
      'tax_rate' => round($taxRate, 6),
      'reserve_add' => $reserveAdd,
      'closing_balance' => round($openingBalance + $net, 2),
      'terrain' => $terrain,
    ];
  }

  foreach ($minorByKey as $minor) {
    $minorEntityId = (string)($minor['entity_id'] ?? '');
    if ($minorEntityId === '' || !isset($entityRows[$minorEntityId])) continue;
    $greatHouseId = (string)($minor['great_house_id'] ?? '');
    if ($greatHouseId === '') continue;
    $greatEntityId = 'great_houses:' . $greatHouseId;
    if (!isset($entityRows[$greatEntityId])) continue;
    $minorIncomeBase = (float)($minorGrossIncome[$minorEntityId] ?? 0.0);
    $tribute = round($minorIncomeBase * $minorToGreatRate, 2);
    if ($tribute <= 0) continue;
    $entityRows[$minorEntityId]['transfers_out'] = round(((float)$entityRows[$minorEntityId]['transfers_out']) + $tribute, 2);
    $entityRows[$greatEntityId]['transfers_in'] = round(((float)$entityRows[$greatEntityId]['transfers_in']) + $tribute, 2);
    $greatGrossIncome[$greatEntityId] = round(((float)($greatGrossIncome[$greatEntityId] ?? 0.0)) + $tribute, 2);
    $ledgerSeq++;
    $ledger[] = [
      'turn_year' => $year,
      'entry_id' => 'L-' . $year . '-M2G-' . str_pad((string)$ledgerSeq, 6, '0', STR_PAD_LEFT),
      'type' => 'minor_to_great_tribute',
      'from' => 'entity:' . $minorEntityId,
      'to' => 'entity:' . $greatEntityId,
      'amount' => $tribute,
      'debit_account' => 'entity:' . $greatEntityId,
      'credit_account' => 'entity:' . $minorEntityId,
      'reason' => 'vassal_tribute',
    ];
  }

  foreach ($kingdomVassalHousesById as $kid => $vassals) {
    $overlordHouseId = (string)($kingdomRulingHouseById[$kid] ?? '');
    if ($overlordHouseId === '') continue;
    foreach ($vassals as $greatHouseId) {
      if ($greatHouseId === '' || $greatHouseId === $overlordHouseId) continue;
      if (!isset($greatOverlordHouseById[$greatHouseId])) $greatOverlordHouseById[$greatHouseId] = $overlordHouseId;
    }
  }

  foreach ($greatGrossIncome as $greatEntityId => $greatIncomeBase) {
    if (!isset($entityRows[$greatEntityId])) continue;
    $greatHouseId = preg_replace('/^great_houses:/', '', (string)$greatEntityId);
    $overlordHouseId = (string)($greatOverlordHouseById[$greatHouseId] ?? '');
    if ($overlordHouseId === '') continue;
    $overlordEntityId = 'great_houses:' . $overlordHouseId;
    if (!isset($entityRows[$overlordEntityId])) {
      $overlordName = (string)((($state['great_houses'] ?? [])[$overlordHouseId]['name'] ?? $overlordHouseId));
      $ensureEntityRow($entityRows, $entityMetaById, $overlordEntityId, $overlordName, 'great_houses', $year);
    }
        $isExplicitKingdomVassal = isset($greatOverlordHouseById[$greatHouseId]);
    $tributeRate = $isExplicitKingdomVassal ? $kingdomVassalHouseTaxRate : $greatToKingdomRate;
    $payment = round((float)$greatIncomeBase * $tributeRate, 2);
    if ($payment <= 0) continue;
    $entityRows[$greatEntityId]['transfers_out'] = round(((float)$entityRows[$greatEntityId]['transfers_out']) + $payment, 2);
    $entityRows[$overlordEntityId]['transfers_in'] = round(((float)$entityRows[$overlordEntityId]['transfers_in']) + $payment, 2);
    $ledgerSeq++;
    $ledger[] = [
      'turn_year' => $year,
      'entry_id' => 'L-' . $year . '-G2K-' . str_pad((string)$ledgerSeq, 6, '0', STR_PAD_LEFT),
      'type' => 'great_to_ruling_house_tithe',
      'from' => 'entity:' . $greatEntityId,
      'to' => 'entity:' . $overlordEntityId,
      'amount' => $payment,
      'debit_account' => 'entity:' . $overlordEntityId,
      'credit_account' => 'entity:' . $greatEntityId,
      'reason' => 'royal_tithe',
    ];
  }


  foreach (['kingdoms', 'great_houses', 'minor_houses', 'free_cities', 'special_territories'] as $bucketType) {
    $bucket = (array)($state[$bucketType] ?? []);
    foreach ($bucket as $entityKey => $realm) {
      if (!is_array($realm)) continue;
      if (empty($realm['arrierban_active'])) continue;
      $summonerKey = trim((string)$entityKey);
      if ($summonerKey === '') continue;
      $shares = $computeArmyUpkeepShares($realm, $bucketType, $summonerKey, $armyUpkeepPerStrength);
      foreach ($shares as $payerEntityId => $rawAmount) {
        if (!isset($entityRows[$payerEntityId])) continue;
        $upkeep = round((float)$rawAmount, 2);
        if ($upkeep <= 0.0) continue;
        $entityRows[$payerEntityId]['army_upkeep_out'] = round(((float)$entityRows[$payerEntityId]['army_upkeep_out']) + $upkeep, 2);
        $ledgerSeq++;
        $ledger[] = [
          'turn_year' => $year,
          'entry_id' => 'L-' . $year . '-UPK-' . str_pad((string)$ledgerSeq, 6, '0', STR_PAD_LEFT),
          'type' => 'entity_army_upkeep',
          'from' => 'entity:' . $payerEntityId,
          'to' => 'sink:army_maintenance',
          'amount' => $upkeep,
          'debit_account' => 'expense:army_maintenance',
          'credit_account' => 'entity:' . $payerEntityId,
          'reason' => 'arrierban_upkeep',
        ];
      }
    }
  }

  usort($provinceRows, static fn($a, $b) => ((int)$a['province_pid'] <=> (int)$b['province_pid']));

  $entityIds = array_keys($entityRows);
  sort($entityIds, SORT_STRING);

  foreach ($entityIds as $eid) {
    $opening = (float)($entityRows[$eid]['opening_balance'] ?? 0.0);
    $incomeTax = (float)($entityRows[$eid]['income_tax'] ?? 0.0);
    $subsidiesOut = (float)($entityRows[$eid]['subsidies_out'] ?? 0.0);
    $armyUpkeepOut = (float)($entityRows[$eid]['army_upkeep_out'] ?? 0.0);
    $transfersIn = (float)($entityRows[$eid]['transfers_in'] ?? 0.0);
    $transfersOut = (float)($entityRows[$eid]['transfers_out'] ?? 0.0);
    $entityRows[$eid]['closing_balance'] = round($opening + $incomeTax + $transfersIn - $subsidiesOut - $armyUpkeepOut - $transfersOut, 2);
  }

  $entityOut = array_values($entityRows);
  usort($entityOut, static fn($a, $b) => strcmp((string)$a['entity_id'], (string)$b['entity_id']));
  usort($ledger, static fn($a, $b) => strcmp((string)$a['entry_id'], (string)$b['entry_id']));

  $summary = [
    'entity_records' => count($entityOut),
    'province_records' => count($provinceRows),
    'ledger_records' => count($ledger),
    'entity_treasury_checksum' => hash('sha256', json_encode($entityOut, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    'province_treasury_checksum' => hash('sha256', json_encode($provinceRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    'ledger_checksum' => hash('sha256', json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    'ruleset_version' => (string)($ruleset['version'] ?? ''),
  ];

  return [
    'entity_treasury_rows' => $entityOut,
    'province_treasury_rows' => $provinceRows,
    'ledger_rows' => $ledger,
    'summary' => $summary,
  ];
}

function turn_api_compute_overlay_payload(array $entityState, array $economyState, array $economySummary): array {
  $topEntities = $entityState;
  usort($topEntities, static fn($a, $b) => ((float)($b['treasury_main'] ?? 0) <=> (float)($a['treasury_main'] ?? 0)));
  $topEntities = array_slice($topEntities, 0, 20);

  $topProvinces = $economyState;
  usort($topProvinces, static fn($a, $b) => ((float)($b['balance_delta'] ?? 0) <=> (float)($a['balance_delta'] ?? 0)));
  $topProvinces = array_slice($topProvinces, 0, 40);

  return [
    'type' => 'economy_overlay_v1',
    'economy_summary' => $economySummary,
    'top_entities' => $topEntities,
    'top_provinces' => $topProvinces,
  ];
}

function turn_api_write_overlay_artifact(int $year, array $overlayPayload): ?array {
  $path = turn_api_base_dir() . '/overlays/turn_' . $year . '_economy_overlay.json';
  $ok = api_atomic_write_json($path, $overlayPayload);
  if (!$ok) return null;
  return [
    'kind' => 'overlay_economy',
    'artifact_ref' => 'data/turns/overlays/turn_' . $year . '_economy_overlay.json',
    'content_type' => 'application/json',
    'checksum' => hash_file('sha256', $path) ?: null,
  ];
}

function turn_api_has_published_successor(int $year): bool {
  $index = turn_api_load_index();
  foreach (($index['turns'] ?? []) as $candidateYear) {
    $candidateYear = (int)$candidateYear;
    if ($candidateYear <= $year) continue;
    $turn = turn_api_load_turn($candidateYear);
    if (is_array($turn) && (($turn['status'] ?? '') === 'published')) return true;
  }
  return false;
}

function turn_api_compact_records(array $rows, int $limit = 25): array {
  if (count($rows) <= $limit) return $rows;
  return array_slice($rows, 0, $limit);
}
