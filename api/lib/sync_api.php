<?php

declare(strict_types=1);

require_once __DIR__ . '/state_api.php';
require_once __DIR__ . '/wiki_api.php';

const SYNC_SCHEMA_VERSION = '1.0.0';
const SYNC_ENTITY_TYPES = [
  'map','province','realm','great_house','minor_house','free_city','special_territory','emblem','asset',
  'wiki_page','genealogy_node','genealogy_edge','diplomacy','order','turn','telegraph','battle','war',
  'player_admin','settings','layer'
];
const SYNC_OPERATIONS = ['upsert','patch','delete','asset_upsert','asset_delete'];
const SYNC_LAYER_MODES = ['provinces','realms','great_houses','minor_houses','free_cities','special_territories'];

function sync_dir(): string { return api_repo_root() . '/data/sync'; }
function sync_revisions_path(): string { return sync_dir() . '/revisions.jsonl'; }
function sync_meta_path(): string { return sync_dir() . '/state_meta.json'; }
function sync_client_ack_path(): string { return sync_dir() . '/client_ack.json'; }
function sync_client_changes_path(): string { return sync_dir() . '/client_changes.json'; }
function sync_lock_path(): string { return sync_dir() . '/sync.lock'; }

function sync_now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

function sync_bootstrap(): void {
  if (!is_dir(sync_dir())) @mkdir(sync_dir(), 0775, true);
  if (!is_file(sync_revisions_path())) @file_put_contents(sync_revisions_path(), '');
  if (!is_file(sync_meta_path())) {
    $now = sync_now();
    sync_atomic_write_json_unlocked(sync_meta_path(), [
      'server_revision' => 0,
      'snapshot_revision' => 0,
      'oldest_available_revision' => 0,
      'schema_version' => SYNC_SCHEMA_VERSION,
      'generated_utc' => $now,
    ]);
  }
  if (!is_file(sync_client_ack_path())) sync_atomic_write_json_unlocked(sync_client_ack_path(), []);
  if (!is_file(sync_client_changes_path())) sync_atomic_write_json_unlocked(sync_client_changes_path(), []);
}

function sync_cors(): void {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Client-Id');
  header('Content-Type: application/json; charset=utf-8');
}

function sync_handle_options(): void {
  sync_cors();
  if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
  }
}

function sync_json(array $payload, int $status = 200): void {
  sync_cors();
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($body === false) {
    http_response_code(500);
    echo '{"ok":false,"error":"write_failed","message":"JSON encode failed"}';
    exit;
  }
  http_response_code($status);
  echo $body;
  exit;
}

function sync_error(string $code, string $message, int $status = 400, array $extra = []): void {
  $meta = sync_load_meta();
  sync_json(['ok' => false, 'error' => $code, 'message' => $message, 'server_revision' => (int)($meta['server_revision'] ?? 0)] + $extra, $status);
}

function sync_require_method(array $methods): void {
  $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
  if (!in_array($method, $methods, true)) sync_error('method_not_allowed', 'Method not allowed', 405, ['allowed' => $methods]);
}

function sync_read_json_file(string $path, array $fallback = []): array {
  if (!is_file($path)) return $fallback;
  $raw = @file_get_contents($path);
  if (!is_string($raw) || trim($raw) === '') return $fallback;
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : $fallback;
}

function sync_atomic_write_json_unlocked(string $path, array $payload): bool {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
  $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
  $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($raw === false) return false;
  if (@file_put_contents($tmp, $raw, LOCK_EX) === false) return false;
  if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
  return true;
}

function sync_with_lock(callable $fn) {
  sync_bootstrap();
  $fh = @fopen(sync_lock_path(), 'c+');
  if (!is_resource($fh)) sync_error('lock_failed', 'Unable to open sync lock', 500);
  if (!@flock($fh, LOCK_EX)) { @fclose($fh); sync_error('lock_failed', 'Unable to acquire sync lock', 500); }
  try { return $fn(); }
  finally { @flock($fh, LOCK_UN); @fclose($fh); }
}

function sync_load_meta(): array {
  sync_bootstrap();
  $meta = sync_read_json_file(sync_meta_path(), []);
  $meta['server_revision'] = max(0, (int)($meta['server_revision'] ?? 0));
  $meta['snapshot_revision'] = max(0, (int)($meta['snapshot_revision'] ?? $meta['server_revision']));
  $meta['oldest_available_revision'] = max(0, (int)($meta['oldest_available_revision'] ?? 0));
  $meta['schema_version'] = (string)($meta['schema_version'] ?? SYNC_SCHEMA_VERSION);
  $meta['generated_utc'] = (string)($meta['generated_utc'] ?? sync_now());
  return $meta;
}

function sync_save_meta(array $meta): bool { return sync_atomic_write_json_unlocked(sync_meta_path(), $meta); }

function sync_normalize_for_json($value) {
  if (!is_array($value)) return $value;
  $keys = array_keys($value);
  $isList = $keys === range(0, count($value) - 1);
  if ($isList) return array_map('sync_normalize_for_json', $value);
  ksort($value, SORT_STRING);
  foreach ($value as $k => $v) $value[$k] = sync_normalize_for_json($v);
  return $value;
}

function sync_checksum_json($value): string {
  $normalized = sync_normalize_for_json($value);
  return hash('sha256', (string)json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function sync_file_checksum(string $path): string { return is_file($path) ? hash_file('sha256', $path) : ''; }

function sync_load_revisions(?int $after = null, int $limit = 1000, ?int $cursorRevision = null): array {
  sync_bootstrap();
  $start = $cursorRevision ?? $after ?? -1;
  $rows = [];
  $fh = @fopen(sync_revisions_path(), 'r');
  if (!is_resource($fh)) return [];
  while (($line = fgets($fh)) !== false) {
    $line = trim($line); if ($line === '') continue;
    $row = json_decode($line, true); if (!is_array($row)) continue;
    $rev = (int)($row['revision'] ?? 0);
    if ($rev <= $start) continue;
    $rows[] = $row;
    if (count($rows) >= $limit + 1) break;
  }
  fclose($fh);
  return $rows;
}

function sync_last_revision_for(string $type, ?string $id = null): int {
  $last = 0;
  foreach (sync_load_revisions(null, 1000000) as $row) {
    if ((string)($row['entity_type'] ?? '') !== $type) continue;
    if ($id !== null && (string)($row['entity_id'] ?? '') !== $id) continue;
    $last = max($last, (int)($row['revision'] ?? 0));
  }
  return $last;
}

function sync_bucket_for_entity_type(string $type): ?string {
  return match ($type) {
    'province' => 'provinces',
    'realm' => 'kingdoms',
    'great_house' => 'great_houses',
    'minor_house' => 'minor_houses',
    'free_city' => 'free_cities',
    'special_territory' => 'special_territories',
    'settings' => 'settings',
    default => null,
  };
}

function sync_entity_collection(string $type, ?array $state = null): array {
  if (!in_array($type, SYNC_ENTITY_TYPES, true)) sync_error('unknown_entity_type', 'Unknown entity type', 400);
  $state = $state ?? api_load_state();
  $bucket = sync_bucket_for_entity_type($type);
  if ($bucket !== null) return is_array($state[$bucket] ?? null) ? $state[$bucket] : [];
  if ($type === 'map') return ['current' => ['schema_version' => $state['schema_version'] ?? null, 'generated_utc' => $state['generated_utc'] ?? null, 'terrain_types' => $state['terrain_types'] ?? []]];
  if ($type === 'wiki_page') {
    $rows = [];
    foreach (api_wiki_list_pages($state) as $page) $rows[(string)($page['page_key'] ?? count($rows))] = $page;
    return $rows;
  }
  if ($type === 'genealogy_node') {
    $g = sync_read_json_file(api_repo_root() . '/data/genealogy_tree.json', ['characters' => []]); $rows = [];
    foreach (($g['characters'] ?? []) as $row) if (is_array($row)) $rows[(string)($row['id'] ?? count($rows))] = $row;
    return $rows;
  }
  if ($type === 'genealogy_edge') {
    $g = sync_read_json_file(api_repo_root() . '/data/genealogy_tree.json', ['relationships' => []]); $rows = [];
    foreach (($g['relationships'] ?? []) as $row) if (is_array($row)) $rows[(string)($row['id'] ?? count($rows))] = $row;
    return $rows;
  }
  if ($type === 'order') return sync_index_rows(sync_read_json_file(api_repo_root() . '/data/orders/orders.json', []), 'orders');
  if ($type === 'turn') return sync_index_rows(sync_read_json_file(api_repo_root() . '/data/turns/index.json', []), 'turns');
  if ($type === 'battle' || $type === 'war') return sync_index_rows(sync_read_json_file(api_repo_root() . '/data/war_battles.json', []), null);
  if ($type === 'player_admin') return sync_index_rows(sync_read_json_file(api_repo_root() . '/data/player_admin_tokens.json', []), null);
  if ($type === 'diplomacy') return ['store' => sync_load_group_files(api_repo_root() . '/data/diplomacy')];
  if ($type === 'telegraph') return ['store' => sync_load_group_files(api_repo_root() . '/data/telegraph')];
  if ($type === 'emblem') return sync_index_rows(sync_read_json_file(api_repo_root() . '/data/emblem_assets.json', []), 'assets');
  if ($type === 'asset') return sync_assets_by_id();
  if ($type === 'layer') {
    $out = [];
    foreach (SYNC_LAYER_MODES as $mode) $out[$mode] = api_build_layer_payload($state, $mode === 'realms' ? 'kingdoms' : $mode);
    return $out;
  }
  return [];
}

function sync_index_rows(array $data, ?string $preferredKey): array {
  $rows = $preferredKey !== null && is_array($data[$preferredKey] ?? null) ? $data[$preferredKey] : $data;
  if (!is_array($rows)) return [];
  $out = [];
  foreach ($rows as $k => $row) {
    $id = is_array($row) ? trim((string)($row['id'] ?? $row['uid'] ?? $row['year'] ?? $k)) : (string)$k;
    if ($id === '') $id = (string)$k;
    $out[$id] = $row;
  }
  return $out;
}

function sync_load_group_files(string $dir): array {
  $out = [];
  if (!is_dir($dir)) return $out;
  foreach (glob($dir . '/*.json') ?: [] as $path) $out[basename($path)] = sync_read_json_file($path, []);
  ksort($out, SORT_STRING);
  return $out;
}

function sync_asset_list(): array {
  $root = api_repo_root();
  $assets = [];
  $add = static function(string $id, string $type, string $url, string $path) use (&$assets): void {
    if (!is_file($path)) return;
    $assets[] = ['id' => $id, 'type' => $type, 'url' => $url, 'revision' => sync_last_revision_for('asset', $id), 'size' => (int)filesize($path), 'checksum' => sync_file_checksum($path)];
  };
  $add('map.png', 'map', '/map.png', $root . '/map.png');
  $add('provinces_id.png', 'map_mask', '/provinces_id.png', $root . '/provinces_id.png');
  foreach (glob($root . '/data/emblems/*') ?: [] as $path) {
    if (!is_file($path)) continue;
    $name = basename($path);
    $add('emblems/' . $name, 'emblem', '/api/assets/emblems/' . rawurlencode($name), $path);
  }
  return $assets;
}

function sync_assets_by_id(): array { $out = []; foreach (sync_asset_list() as $a) $out[(string)$a['id']] = $a; return $out; }

function sync_manifest(): array {
  $meta = sync_load_meta();
  $state = api_load_state();
  $entities = [];
  foreach (SYNC_ENTITY_TYPES as $type) {
    if ($type === 'asset') continue;
    $collection = sync_entity_collection($type, $state);
    $rev = sync_last_revision_for($type);
    $entities[$type] = ['count' => count($collection), 'revision' => $rev > 0 ? $rev : (int)$meta['snapshot_revision'], 'checksum' => sync_checksum_json($collection)];
  }
  return ['ok' => true, 'server_revision' => (int)$meta['server_revision'], 'entities' => $entities, 'assets' => sync_asset_list()];
}

function sync_map_version(): string {
  $path = api_repo_root() . '/api/map/version.php';
  return gmdate('Y-m-d', api_file_mtime(api_state_path()));
}

function sync_snapshot(string $profile, int $limit, ?string $cursor, bool $includeAssets): array {
  $meta = sync_load_meta();
  $state = api_load_state();
  $data = [
    'provinces' => array_values(sync_entity_collection('province', $state)),
    'realms' => sync_entity_collection('realm', $state),
    'great_houses' => sync_entity_collection('great_house', $state),
    'minor_houses' => sync_entity_collection('minor_house', $state),
    'free_cities' => sync_entity_collection('free_city', $state),
    'special_territories' => sync_entity_collection('special_territory', $state),
    'wiki' => sync_entity_collection('wiki_page', $state),
    'genealogy' => ['nodes' => sync_entity_collection('genealogy_node', $state), 'edges' => sync_entity_collection('genealogy_edge', $state)],
    'diplomacy' => sync_entity_collection('diplomacy', $state),
    'orders' => array_values(sync_entity_collection('order', $state)),
    'turns' => array_values(sync_entity_collection('turn', $state)),
    'telegraph' => sync_entity_collection('telegraph', $state),
    'battles' => array_values(sync_entity_collection('battle', $state)),
    'wars' => array_values(sync_entity_collection('war', $state)),
    'player_admin' => sync_entity_collection('player_admin', $state),
    'settings' => sync_entity_collection('settings', $state),
    'layers' => sync_entity_collection('layer', $state),
  ];
  if ($profile === 'compact') {
    foreach ($data['provinces'] as &$p) if (is_array($p)) unset($p['province_card_image'], $p['province_card_base_image'], $p['emblem_svg']);
    unset($p);
  }
  return ['ok' => true, 'snapshot_revision' => (int)$meta['server_revision'], 'generated_utc' => sync_now(), 'schema_version' => SYNC_SCHEMA_VERSION, 'cursor' => null, 'has_more' => false, 'data' => $data, 'assets' => $includeAssets ? sync_asset_list() : []];
}

function sync_parse_revision_param(string $name, bool $required = true): int {
  if (!isset($_GET[$name])) { if ($required) sync_error('invalid_revision', 'Missing revision parameter', 400); return 0; }
  $raw = trim((string)$_GET[$name]);
  if ($raw === '' || !ctype_digit($raw)) sync_error('invalid_revision', 'Revision must be a non-negative integer', 400);
  return (int)$raw;
}

function sync_delta_payload(int $since, int $limit, ?string $cursor, bool $assetsOnly = false): array {
  $meta = sync_load_meta();
  $serverRev = (int)$meta['server_revision'];
  $oldest = (int)$meta['oldest_available_revision'];
  if ($since < $oldest) sync_json(['ok' => false, 'error' => 'snapshot_required', 'message' => 'Requested revision is older than available change journal', 'oldest_available_revision' => $oldest, 'server_revision' => $serverRev], 409);
  $cursorRev = ($cursor !== null && ctype_digit($cursor)) ? (int)$cursor : null;
  $rows = sync_load_revisions($since, $limit, $cursorRev);
  $hasMore = count($rows) > $limit;
  if ($hasMore) $rows = array_slice($rows, 0, $limit);
  usort($rows, static fn($a, $b) => ((int)($a['revision'] ?? 0)) <=> ((int)($b['revision'] ?? 0)));
  $changes = [];
  $assets = [];
  foreach ($rows as $row) {
    $op = (string)($row['operation'] ?? '');
    if (str_starts_with($op, 'asset_')) $assets[] = sync_asset_delta_from_revision($row);
    elseif (!$assetsOnly) $changes[] = $row;
  }
  $next = $hasMore && $rows ? (string)((int)($rows[count($rows)-1]['revision'] ?? 0)) : null;
  return ['ok' => true, 'from_revision' => $since, 'to_revision' => $serverRev, 'has_more' => $hasMore, 'next_cursor' => $next, 'changes' => $changes, 'assets' => $assets];
}

function sync_asset_delta_from_revision(array $row): array {
  $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
  $id = (string)($row['entity_id'] ?? $payload['id'] ?? '');
  $asset = sync_assets_by_id()[$id] ?? [];
  $out = ['revision' => (int)($row['revision'] ?? 0), 'operation' => (string)($row['operation'] ?? 'asset_upsert'), 'id' => $id, 'type' => (string)($payload['type'] ?? $asset['type'] ?? 'asset')];
  foreach (['url','checksum','size'] as $k) if (isset($asset[$k])) $out[$k] = $asset[$k];
  foreach (['url','checksum','size'] as $k) if (isset($payload[$k])) $out[$k] = $payload[$k];
  return $out;
}

function sync_get_entity(string $type, string $id): array {
  $collection = sync_entity_collection($type);
  if (!array_key_exists($id, $collection)) sync_error('entity_not_found', 'Entity not found', 404);
  $data = $collection[$id];
  $meta = sync_load_meta();
  $rev = sync_last_revision_for($type, $id);
  return ['ok' => true, 'entity_type' => $type, 'entity_id' => $id, 'revision' => $rev > 0 ? $rev : (int)$meta['snapshot_revision'], 'checksum' => sync_checksum_json($data), 'data' => $data];
}

function sync_layers_delta(int $since): array {
  $delta = sync_delta_payload($since, 100000, null, false);
  $changed = [];
  foreach (($delta['changes'] ?? []) as $row) {
    $type = (string)($row['entity_type'] ?? '');
    if (in_array($type, ['province','realm','great_house','minor_house','free_city','special_territory','layer'], true)) {
      foreach (SYNC_LAYER_MODES as $mode) $changed[$mode] = true;
    }
  }
  $state = api_load_state();
  $layers = [];
  foreach (array_keys($changed) as $mode) {
    $actual = $mode === 'realms' ? 'kingdoms' : $mode;
    $payload = api_build_layer_payload($state, $actual);
    $layers[] = ['mode' => $mode, 'revision' => (int)$delta['to_revision'], 'checksum' => sync_checksum_json($payload), 'data' => $payload];
  }
  return ['ok' => true, 'from_revision' => $since, 'to_revision' => (int)$delta['to_revision'], 'layers' => $layers];
}

function sync_read_body(): array {
  $raw = (string)file_get_contents('php://input');
  $decoded = trim($raw) === '' ? null : json_decode($raw, true);
  if (!is_array($decoded)) sync_error('invalid_payload', 'Invalid JSON payload', 400);
  return $decoded;
}

function sync_push(array $payload): array {
  return sync_with_lock(function () use ($payload): array {
    $clientId = trim((string)($payload['client_id'] ?? ''));
    $baseRevision = $payload['base_revision'] ?? null;
    $changes = $payload['changes'] ?? null;
    if ($clientId === '' || !is_int($baseRevision) && !(is_string($baseRevision) && ctype_digit($baseRevision)) || !is_array($changes)) sync_error('invalid_payload', 'client_id, base_revision and changes are required', 400);
    $baseRevision = (int)$baseRevision;
    $author = trim((string)($payload['author'] ?? $clientId));
    $meta = sync_load_meta();
    $serverRevision = (int)$meta['server_revision'];
    if ($baseRevision > $serverRevision) sync_error('invalid_revision', 'base_revision is newer than server_revision', 400);

    $clientChanges = sync_read_json_file(sync_client_changes_path(), []);
    $accepted = [];
    $pending = [];
    foreach ($changes as $idx => $change) {
      if (!is_array($change)) sync_error('invalid_payload', 'Invalid change entry', 400, ['index' => $idx]);
      $cid = trim((string)($change['client_change_id'] ?? ''));
      $type = trim((string)($change['entity_type'] ?? ''));
      $id = trim((string)($change['entity_id'] ?? ''));
      $op = trim((string)($change['operation'] ?? ''));
      if ($cid === '' || $type === '' || $id === '' || !in_array($op, SYNC_OPERATIONS, true)) sync_error('invalid_payload', 'Invalid change fields', 400, ['index' => $idx]);
      if (!in_array($type, SYNC_ENTITY_TYPES, true)) sync_error('unknown_entity_type', 'Unknown entity type', 400, ['index' => $idx]);
      if (isset($clientChanges[$cid])) { $accepted[] = ['client_change_id' => $cid, 'server_revision' => (int)($clientChanges[$cid]['server_revision'] ?? 0), 'duplicate' => true]; continue; }
      $last = sync_last_revision_for($type, $id);
      if ($last > $baseRevision) {
        $serverEntity = null;
        try { $serverEntity = sync_entity_collection($type)[$id] ?? null; } catch (Throwable $_) { $serverEntity = null; }
        sync_json(['ok' => false, 'error' => 'conflict', 'server_revision' => $serverRevision, 'conflicts' => [[
          'client_change_id' => $cid, 'entity_type' => $type, 'entity_id' => $id, 'reason' => 'entity_modified_after_base_revision', 'server_entity' => $serverEntity, 'client_payload' => $change['payload'] ?? null,
        ]]], 409);
      }
      $pending[] = $change;
    }

    $state = api_load_state();
    $newState = $state;
    foreach ($pending as $change) {
      $res = sync_apply_change_to_state($newState, $change);
      if (!$res['ok']) sync_error((string)$res['error'], (string)($res['message'] ?? 'Change cannot be applied'), 400);
      $newState = $res['state'];
    }

    if ($pending && !api_atomic_write_json(api_state_path(), $newState)) sync_error('write_failed', 'Unable to write server state', 500);

    $fh = @fopen(sync_revisions_path(), 'ab');
    if (!is_resource($fh)) sync_error('write_failed', 'Unable to open revision journal', 500);
    foreach ($pending as $change) {
      $serverRevision++;
      $type = (string)$change['entity_type']; $id = (string)$change['entity_id'];
      $entity = sync_entity_collection($type, $newState)[$id] ?? ($change['payload'] ?? []);
      $row = [
        'revision' => $serverRevision,
        'timestamp' => sync_now(),
        'entity_type' => $type,
        'entity_id' => $id,
        'operation' => (string)$change['operation'],
        'payload' => is_array($change['payload'] ?? null) ? $change['payload'] : [],
        'checksum' => sync_checksum_json($entity),
        'author' => $author,
        'source' => 'push',
        'client_id' => $clientId,
        'client_change_id' => (string)$change['client_change_id'],
      ];
      $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
      if (@fwrite($fh, $line) === false) { @fclose($fh); sync_error('write_failed', 'Unable to append revision journal', 500); }
      $clientChanges[(string)$change['client_change_id']] = ['server_revision' => $serverRevision, 'client_id' => $clientId, 'accepted_at' => $row['timestamp']];
      $accepted[] = ['client_change_id' => (string)$change['client_change_id'], 'server_revision' => $serverRevision];
    }
    @fclose($fh);
    $meta['server_revision'] = $serverRevision;
    $meta['snapshot_revision'] = max((int)($meta['snapshot_revision'] ?? 0), $serverRevision);
    $meta['oldest_available_revision'] = min((int)($meta['oldest_available_revision'] ?? 0), $serverRevision);
    $meta['generated_utc'] = sync_now();
    if (!sync_save_meta($meta) || !sync_atomic_write_json_unlocked(sync_client_changes_path(), $clientChanges)) sync_error('write_failed', 'Unable to write sync metadata', 500);
    return ['ok' => true, 'accepted' => $accepted, 'rejected' => [], 'server_revision' => $serverRevision];
  });
}

function sync_apply_change_to_state(array $state, array $change): array {
  $type = (string)$change['entity_type']; $id = (string)$change['entity_id']; $op = (string)$change['operation'];
  $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];
  $bucket = sync_bucket_for_entity_type($type);
  if ($bucket === null) return ['ok' => false, 'error' => 'invalid_payload', 'message' => 'Push for this entity type is read-only in current implementation'];
  if (!isset($state[$bucket]) || !is_array($state[$bucket])) $state[$bucket] = [];
  if ($op === 'delete') { unset($state[$bucket][$id]); return ['ok' => true, 'state' => $state]; }
  if ($op === 'patch') {
    if (!is_array($state[$bucket][$id] ?? null)) return ['ok' => false, 'error' => 'entity_not_found', 'message' => 'Cannot patch missing entity'];
    $state[$bucket][$id] = array_replace_recursive($state[$bucket][$id], $payload);
    return ['ok' => true, 'state' => $state];
  }
  if ($op === 'upsert') { $state[$bucket][$id] = array_replace_recursive(is_array($state[$bucket][$id] ?? null) ? $state[$bucket][$id] : [], $payload); return ['ok' => true, 'state' => $state]; }
  return ['ok' => false, 'error' => 'invalid_payload', 'message' => 'Unsupported operation for JSON entity'];
}


function sync_record_external_change(string $entityType, string $entityId, string $operation, array $payload, $entityData = null, string $author = 'server'): bool {
  if (!in_array($entityType, SYNC_ENTITY_TYPES, true) || !in_array($operation, SYNC_OPERATIONS, true)) return false;
  return (bool)sync_with_lock(function () use ($entityType, $entityId, $operation, $payload, $entityData, $author): bool {
    $meta = sync_load_meta();
    $serverRevision = (int)($meta['server_revision'] ?? 0) + 1;
    $row = [
      'revision' => $serverRevision,
      'timestamp' => sync_now(),
      'entity_type' => $entityType,
      'entity_id' => $entityId,
      'operation' => $operation,
      'payload' => $payload,
      'checksum' => sync_checksum_json($entityData ?? $payload),
      'author' => $author,
      'source' => 'server',
    ];
    $fh = @fopen(sync_revisions_path(), 'ab');
    if (!is_resource($fh)) return false;
    $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $ok = @fwrite($fh, $line) !== false;
    @fclose($fh);
    if (!$ok) return false;
    $meta['server_revision'] = $serverRevision;
    $meta['snapshot_revision'] = max((int)($meta['snapshot_revision'] ?? 0), $serverRevision);
    if ((int)($meta['oldest_available_revision'] ?? 0) === 0) $meta['oldest_available_revision'] = 0;
    $meta['generated_utc'] = sync_now();
    return sync_save_meta($meta);
  });
}

function sync_ack(array $payload): array {
  return sync_with_lock(function () use ($payload): array {
    $clientId = trim((string)($payload['client_id'] ?? ''));
    $applied = $payload['applied_revision'] ?? null;
    if ($clientId === '' || (!is_int($applied) && !(is_string($applied) && ctype_digit($applied)))) sync_error('invalid_payload', 'client_id and applied_revision are required', 400);
    $acks = sync_read_json_file(sync_client_ack_path(), []);
    $acks[$clientId] = [
      'last_seen' => sync_now(),
      'applied_revision' => max((int)($acks[$clientId]['applied_revision'] ?? 0), (int)$applied),
      'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ($payload['user_agent'] ?? '')),
      'client_timestamp' => (string)($payload['timestamp'] ?? ''),
    ];
    if (!sync_atomic_write_json_unlocked(sync_client_ack_path(), $acks)) sync_error('write_failed', 'Unable to write client acknowledgements', 500);
    $meta = sync_load_meta();
    return ['ok' => true, 'server_revision' => (int)$meta['server_revision']];
  });
}

function sync_clients(): array {
  $acks = sync_read_json_file(sync_client_ack_path(), []);
  $clients = [];
  foreach ($acks as $clientId => $row) if (is_array($row)) $clients[] = ['client_id' => (string)$clientId, 'last_seen' => (string)($row['last_seen'] ?? ''), 'applied_revision' => (int)($row['applied_revision'] ?? 0), 'user_agent' => (string)($row['user_agent'] ?? '')];
  usort($clients, static fn($a, $b) => strcmp((string)$a['client_id'], (string)$b['client_id']));
  return ['ok' => true, 'clients' => $clients];
}

function sync_endpoint_boot(): void {
  error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
  sync_handle_options();
  sync_bootstrap();
}
