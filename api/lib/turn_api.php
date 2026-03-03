<?php

declare(strict_types=1);

require_once __DIR__ . '/state_api.php';

function turn_api_meta(): array {
  return ['api_version' => 'v1', 'schema_version' => 'stage1'];
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

function turn_api_compute_economy_state(array $state, int $year): array {
  $out = [];
  foreach (($state['provinces'] ?? []) as $idx => $prov) {
    if (!is_array($prov)) continue;
    $pid = (int)($prov['pid'] ?? $idx);
    if ($pid <= 0) continue;
    $terrain = strtolower((string)($prov['terrain'] ?? ''));
    $baseIncome = 10.0;
    if ($terrain === 'mountain') $baseIncome = 8.0;
    if ($terrain === 'plains') $baseIncome = 12.0;
    if ($terrain === 'sea' || $terrain === 'ocean') $baseIncome = 6.0;

    $income = $baseIncome + ($year % 5);
    $expense = 4.0 + (($year % 3) * 0.5);
    $out[] = [
      'turn_year' => $year,
      'province_pid' => $pid,
      'income' => round($income, 2),
      'expense' => round($expense, 2),
      'balance_delta' => round($income - $expense, 2),
      'modifiers' => ['terrain' => $terrain, 'year_mod' => ($year % 5)],
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
    'economy_state' => turn_api_compute_economy_state($worldState, $targetYear),
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
    'economy_state' => ['status' => 'captured_start', 'records' => count(turn_api_compute_economy_state($worldState, $targetYear))],
    'economy' => ['status' => 'not_processed', 'checkpoint' => null, 'records' => 0, 'checksum' => null],
    'map_artifacts' => [],
    'events' => [],
  ];
}

function turn_api_compute_economy_for_turn(array $turn): array {
  $year = (int)($turn['year'] ?? 0);
  $snap = turn_api_load_snapshot($year, 'start');
  $state = (is_array($snap) && is_array($snap['payload']['world_state'] ?? null)) ? $snap['payload']['world_state'] : api_load_state();

  $entityState = turn_api_compute_entity_state($state, $year);
  $economyState = turn_api_compute_economy_state($state, $year);
  $economy = turn_api_compute_economy_summary($economyState);

  return ['entity_state' => $entityState, 'economy_state' => $economyState, 'economy' => $economy];
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
