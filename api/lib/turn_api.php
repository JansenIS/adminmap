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
    'version' => 'v1.2',
    'economy' => [
      'base_income_default' => 10.0,
      'base_income_mountain' => 8.0,
      'base_income_plains' => 12.0,
      'base_income_sea' => 6.0,
      'year_mod_cycle' => 5,
      'expense_base' => 4.0,
      'expense_year_step' => 0.5,
      'expense_year_cycle' => 3,
    ],
    'treasury' => [
      'tax_rate' => 0.35,
      'province_reserve_rate' => 0.10,
      'province_opening_income_share' => 0.20,
      'entity_subsidy_rate' => 0.12,
      'entity_transfer_rate' => 0.01,
      'entity_transfer_cap' => 5.0,
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

function turn_api_realm_entity_rows(array $state): array {
  $rows = [];
  foreach (['kingdoms', 'great_houses', 'minor_houses', 'free_cities'] as $type) {
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

function turn_api_compute_economy_state(array $state, int $year, array $ruleset): array {
  $out = [];
  foreach (($state['provinces'] ?? []) as $idx => $prov) {
    if (!is_array($prov)) continue;
    $pid = (int)($prov['pid'] ?? $idx);
    if ($pid <= 0) continue;
    $terrain = strtolower((string)($prov['terrain'] ?? ''));
    $ecoCfg = (array)($ruleset['economy'] ?? []);
    $baseIncome = (float)($ecoCfg['base_income_default'] ?? 10.0);
    if ($terrain === 'mountain') $baseIncome = (float)($ecoCfg['base_income_mountain'] ?? 8.0);
    if ($terrain === 'plains') $baseIncome = (float)($ecoCfg['base_income_plains'] ?? 12.0);
    if ($terrain === 'sea' || $terrain === 'ocean') $baseIncome = (float)($ecoCfg['base_income_sea'] ?? 6.0);

    $yearModCycle = max(1, (int)($ecoCfg['year_mod_cycle'] ?? 5));
    $expenseCycle = max(1, (int)($ecoCfg['expense_year_cycle'] ?? 3));
    $income = $baseIncome + ($year % $yearModCycle);
    $expense = (float)($ecoCfg['expense_base'] ?? 4.0) + (($year % $expenseCycle) * (float)($ecoCfg['expense_year_step'] ?? 0.5));
    $out[] = [
      'turn_year' => $year,
      'province_pid' => $pid,
      'income' => round($income, 2),
      'expense' => round($expense, 2),
      'balance_delta' => round($income - $expense, 2),
      'modifiers' => ['terrain' => $terrain, 'ruleset_version' => (string)($ruleset['version'] ?? ''), 'year_mod' => ($year % max(1, (int)(($ruleset['economy']['year_mod_cycle'] ?? 5))))],
    ];
  }
  usort($out, static fn($a, $b) => ((int)$a['province_pid'] <=> (int)$b['province_pid']));
  return $out;
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

function turn_api_source_state_for_new_turn(int $sourceYear): array {
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

function turn_api_build_base_turn(int $sourceYear, int $targetYear, string $rulesetVersion): array {
  $worldState = turn_api_source_state_for_new_turn($sourceYear);
  $startSnapshotRef = turn_api_save_snapshot($targetYear, 'start', [
    'world_state' => $worldState,
    'entity_state' => turn_api_compute_entity_state($worldState, $targetYear),
    'economy_state' => turn_api_compute_economy_state($worldState, $targetYear, turn_api_ruleset_for_turn(['ruleset_version' => $rulesetVersion])),
    'source_turn_year' => $sourceYear,
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
    'map_artifacts' => [],
    'events' => [],
  ];
}

function turn_api_compute_economy_for_turn(array $turn): array {
  $year = (int)($turn['year'] ?? 0);
  $snap = turn_api_load_snapshot($year, 'start');
  $state = (is_array($snap) && is_array($snap['payload']['world_state'] ?? null)) ? $snap['payload']['world_state'] : api_load_state();

  $ruleset = turn_api_ruleset_for_turn($turn);
  $entityState = turn_api_compute_entity_state($state, $year);
  $economyState = turn_api_compute_economy_state($state, $year, $ruleset);
  $economy = turn_api_compute_economy_summary($economyState);
  $economy['ruleset_version'] = (string)($ruleset['version'] ?? '');
  $treasury = turn_api_compute_treasury($state, $entityState, $economyState, $year, $ruleset);

  return [
    'entity_state' => $entityState,
    'economy_state' => $economyState,
    'economy' => $economy,
    'entity_treasury_rows' => $treasury['entity_treasury_rows'],
    'province_treasury_rows' => $treasury['province_treasury_rows'],
    'ledger_rows' => $treasury['ledger_rows'],
    'treasury_summary' => $treasury['summary'],
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



function turn_api_compute_treasury(array $state, array $entityState, array $economyState, int $year, array $ruleset): array {
  $entityRows = [];
  foreach ($entityState as $row) {
    if (!is_array($row)) continue;
    $entityId = (string)($row['entity_id'] ?? '');
    if ($entityId === '') continue;
    $entityRows[$entityId] = [
      'turn_year' => $year,
      'entity_id' => $entityId,
      'entity_name' => (string)($row['entity_name'] ?? ''),
      'opening_balance' => round((float)($row['treasury_main'] ?? 0), 2),
      'income_tax' => 0.0,
      'subsidies_out' => 0.0,
      'transfers_in' => 0.0,
      'transfers_out' => 0.0,
      'closing_balance' => 0.0,
    ];
  }

  $ownerToEntityId = [];
  foreach ($entityRows as $eid => $row) {
    $ownerToEntityId[(string)($row['entity_name'] ?? '')] = $eid;
  }

  $provinceRows = [];
  $ledger = [];
  foreach ($economyState as $eco) {
    if (!is_array($eco)) continue;
    $pid = (int)($eco['province_pid'] ?? 0);
    if ($pid <= 0) continue;
    $income = (float)($eco['income'] ?? 0.0);
    $expense = (float)($eco['expense'] ?? 0.0);
    $tCfg = (array)($ruleset['treasury'] ?? []);
    $tax = round($income * (float)($tCfg['tax_rate'] ?? 0.35), 2);
    $reserveAdd = round($income * (float)($tCfg['province_reserve_rate'] ?? 0.10), 2);

    $owner = '';
    $provinceName = '';
    $terrain = '';
    foreach (($state['provinces'] ?? []) as $prov) {
      if (!is_array($prov)) continue;
      if ((int)($prov['pid'] ?? 0) !== $pid) continue;
      $owner = (string)($prov['owner'] ?? '');
      $provinceName = (string)($prov['name'] ?? '');
      $terrain = (string)($prov['terrain'] ?? '');
      break;
    }

    $targetEntityId = (string)($ownerToEntityId[$owner] ?? '');
    $appliedTax = 0.0;
    if ($targetEntityId !== '' && isset($entityRows[$targetEntityId])) {
      $appliedTax = $tax;
      $entityRows[$targetEntityId]['income_tax'] = round(((float)$entityRows[$targetEntityId]['income_tax']) + $appliedTax, 2);
      $ledger[] = [
        'turn_year' => $year,
        'entry_id' => 'L-' . $year . '-P2E-' . $pid,
        'type' => 'province_to_entity_tax',
        'from' => 'province:' . $pid,
        'to' => 'entity:' . $targetEntityId,
        'amount' => $appliedTax,
        'debit_account' => 'entity:' . $targetEntityId,
        'credit_account' => 'province:' . $pid,
        'reason' => 'tax_collection',
      ];
    }

    $net = round($income - $expense - $appliedTax - $reserveAdd, 2);
    $openingShare = (float)((($ruleset['treasury'] ?? [])['province_opening_income_share'] ?? 0.2));
    $openingBalance = round($income * $openingShare, 2);
    $provinceRows[] = [
      'turn_year' => $year,
      'province_pid' => $pid,
      'province_name' => $provinceName,
      'owner_name' => $owner,
      'opening_balance' => $openingBalance,
      'income' => round($income, 2),
      'expense' => round($expense, 2),
      'tax_paid_to_entity' => $appliedTax,
      'reserve_add' => $reserveAdd,
      'closing_balance' => round($openingBalance + $net, 2),
      'terrain' => $terrain,
    ];
  }

  usort($provinceRows, static fn($a, $b) => ((int)$a['province_pid'] <=> (int)$b['province_pid']));

  $entityIds = array_keys($entityRows);
  sort($entityIds, SORT_STRING);

  foreach ($entityIds as $eid) {
    $opening = (float)($entityRows[$eid]['opening_balance'] ?? 0.0);
    $incomeTax = (float)($entityRows[$eid]['income_tax'] ?? 0.0);
    $subsidy = round($incomeTax * (float)((($ruleset['treasury'] ?? [])['entity_subsidy_rate'] ?? 0.12)), 2);
    $entityRows[$eid]['subsidies_out'] = $subsidy;

    if ($subsidy > 0) {
      $ledger[] = [
        'turn_year' => $year,
        'entry_id' => 'L-' . $year . '-E2P-' . md5($eid),
        'type' => 'entity_to_province_subsidy',
        'from' => 'entity:' . $eid,
        'to' => 'provinces_of:' . $eid,
        'amount' => $subsidy,
        'debit_account' => 'provinces_of:' . $eid,
        'credit_account' => 'entity:' . $eid,
        'reason' => 'infrastructure_subsidy',
      ];
    }

    $entityRows[$eid]['closing_balance'] = round($opening + $incomeTax - $subsidy, 2);
  }

  if (count($entityIds) >= 2) {
    $first = $entityIds[0];
    $last = $entityIds[count($entityIds) - 1];
    $transfer = round(min((float)$entityRows[$first]['closing_balance'] * (float)((($ruleset['treasury'] ?? [])['entity_transfer_rate'] ?? 0.01)), (float)((($ruleset['treasury'] ?? [])['entity_transfer_cap'] ?? 5.0))), 2);
    if ($transfer > 0) {
      $entityRows[$first]['transfers_out'] = $transfer;
      $entityRows[$first]['closing_balance'] = round((float)$entityRows[$first]['closing_balance'] - $transfer, 2);
      $entityRows[$last]['transfers_in'] = $transfer;
      $entityRows[$last]['closing_balance'] = round((float)$entityRows[$last]['closing_balance'] + $transfer, 2);
      $ledger[] = [
        'turn_year' => $year,
        'entry_id' => 'L-' . $year . '-E2E-' . md5($first . '>' . $last),
        'type' => 'entity_to_entity_transfer',
        'from' => 'entity:' . $first,
        'to' => 'entity:' . $last,
        'amount' => $transfer,
        'debit_account' => 'entity:' . $last,
        'credit_account' => 'entity:' . $first,
        'reason' => 'obligation_payment',
      ];
    }
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
