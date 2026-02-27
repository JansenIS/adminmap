<?php

declare(strict_types=1);

@ini_set('memory_limit', '768M');


function api_response_meta(): array {
  return [
    'api_version' => '2026-02-backend-first-draft',
    'schema_version' => 1,
  ];
}

function api_json_response(array $payload, int $status = 200, ?int $sourceMtime = null): void {
  if (!isset($payload['meta']) || !is_array($payload['meta'])) {
    $payload['meta'] = api_response_meta();
  }
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($body === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"encode_failed"}';
    exit;
  }

  $etag = '"' . hash('sha256', $body) . '"';
  $lastModified = gmdate('D, d M Y H:i:s', $sourceMtime ?? time()) . ' GMT';

  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: public, max-age=5, stale-while-revalidate=30');
  header('ETag: ' . $etag);
  header('Last-Modified: ' . $lastModified);

  $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
  $ifModifiedSince = trim((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
  if ($ifNoneMatch === $etag || ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= ($sourceMtime ?? 0))) {
    http_response_code(304);
    exit;
  }

  http_response_code($status);
  echo $body;
  exit;
}


function api_repo_root(): string {
  return dirname(__DIR__, 2);
}

function api_state_path(): string {
  return api_repo_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'map_state.json';
}

function api_provinces_index_path(): string {
  return api_repo_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'provinces_index.json';
}

function api_load_state(): array {
  $path = api_state_path();
  $raw = @file_get_contents($path);
  if ($raw === false || $raw === '') {
    api_json_response(['error' => 'state_not_found'], 500);
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    api_json_response(['error' => 'state_decode_failed'], 500);
  }

  return $decoded;
}

function api_state_mtime(): int {
  return (int)@filemtime(api_state_path()) ?: time();
}

function api_build_provinces_index(array $state, array $refsByOwner = []): array {
  $rows = [];
  foreach (($state['provinces'] ?? []) as $pid => $pd) {
    if (!is_array($pd)) continue;
    $pidNum = (int)($pd['pid'] ?? $pid);
    if ($pidNum <= 0) continue;

    $item = [
      'pid' => $pidNum,
      'name' => (string)($pd['name'] ?? ''),
      'owner' => (string)($pd['owner'] ?? ''),
      'suzerain' => (string)($pd['suzerain'] ?? ''),
      'senior' => (string)($pd['senior'] ?? ''),
      'terrain' => (string)($pd['terrain'] ?? ''),
      'kingdom_id' => (string)($pd['kingdom_id'] ?? ''),
      'great_house_id' => (string)($pd['great_house_id'] ?? ''),
      'minor_house_id' => (string)($pd['minor_house_id'] ?? ''),
      'free_city_id' => (string)($pd['free_city_id'] ?? ''),
      'fill_rgba' => (is_array($pd['fill_rgba'] ?? null) && count($pd['fill_rgba']) === 4) ? array_values($pd['fill_rgba']) : null,
      'province_card_image' => (string)($pd['province_card_image'] ?? ''),
    ];

    if (isset($pd['emblem_asset_id']) && is_string($pd['emblem_asset_id']) && trim($pd['emblem_asset_id']) !== '') {
      $item['emblem_asset_id'] = trim($pd['emblem_asset_id']);
    } else {
      $key = 'province:' . $pidNum;
      if (isset($refsByOwner[$key]) && $refsByOwner[$key] !== '') {
        $item['emblem_asset_id'] = $refsByOwner[$key];
      }
    }

    $rows[$pidNum] = $item;
  }

  ksort($rows, SORT_NUMERIC);
  return [
    'generated_at' => gmdate('c'),
    'version' => api_state_version_hash($state),
    'items' => array_values($rows),
    'total' => count($rows),
  ];
}

function api_build_refs_by_owner_from_file_or_state(?array $state = null): array {
  $refs = [];
  $refsPath = api_repo_root() . '/data/emblem_refs.json';
  if (is_file($refsPath)) {
    $decodedRefs = json_decode((string)file_get_contents($refsPath), true);
    if (is_array($decodedRefs)) {
      foreach (($decodedRefs['refs'] ?? []) as $ref) {
        if (!is_array($ref)) continue;
        $refs[$ref['owner_type'] . ':' . $ref['owner_id']] = (string)($ref['asset_id'] ?? '');
      }
      return $refs;
    }
  }

  if (is_array($state)) {
    $migration = api_emblem_migration_from_state($state);
    foreach (($migration['refs'] ?? []) as $ref) {
      if (!is_array($ref)) continue;
      $refs[$ref['owner_type'] . ':' . $ref['owner_id']] = (string)($ref['asset_id'] ?? '');
    }
  }
  return $refs;
}

function api_get_or_build_provinces_index(?array $state = null): array {
  $indexPath = api_provinces_index_path();
  $statePath = api_state_path();
  $stateMtime = (int)@filemtime($statePath);
  $indexMtime = (int)@filemtime($indexPath);

  if ($indexMtime > 0 && $indexMtime >= $stateMtime) {
    $raw = @file_get_contents($indexPath);
    if (is_string($raw) && trim($raw) !== '') {
      $decoded = json_decode($raw, true);
      if (is_array($decoded) && is_array($decoded['items'] ?? null)) {
        return $decoded;
      }
    }
  }

  $state = $state ?? api_load_state();
  $refs = api_build_refs_by_owner_from_file_or_state($state);
  $index = api_build_provinces_index($state, $refs);
  api_atomic_write_json($indexPath, $index);
  return $index;
}

function api_limit_int(string $name, int $default, int $min, int $max): int {
  $val = isset($_GET[$name]) ? (int)$_GET[$name] : $default;
  return max($min, min($max, $val));
}

function api_normalize_svg_payload(string $source): ?array {
  $raw = trim($source);
  if ($raw === '') return null;

  if (preg_match('#^data:image/svg\+xml;base64,(.+)$#i', $raw, $m)) {
    $decoded = base64_decode($m[1], true);
    if ($decoded === false || trim($decoded) === '') return null;
    $raw = $decoded;
  }

  if (!preg_match('/<svg[\s>]/i', $raw)) return null;

  $normalized = preg_replace('/<script[\s\S]*?<\/script\s*>/i', '', $raw);
  $normalized = trim((string)$normalized);
  if ($normalized === '') return null;

  $box = ['w' => 2000.0, 'h' => 2400.0];
  if (preg_match('/\bviewBox\s*=\s*["\']\s*[0-9.\-eE]+\s+[0-9.\-eE]+\s+([0-9.\-eE]+)\s+([0-9.\-eE]+)\s*["\']/i', $normalized, $vb)) {
    $w = (float)$vb[1];
    $h = (float)$vb[2];
    if ($w > 0 && $h > 0) $box = ['w' => $w, 'h' => $h];
  }

  return ['svg' => $normalized, 'box' => $box];
}

function api_emblem_migration_from_state(array $state): array {
  $assets = [];
  $refs = [];

  $storeAsset = static function (string $kind, string $ownerType, string $ownerId, array $emblem, ?array $box, ?float $scale = null) use (&$assets, &$refs): void {
    $hash = hash('sha256', $emblem['svg']);
    $assetId = 'embl_' . substr($hash, 0, 20);
    if (!isset($assets[$assetId])) {
      $assets[$assetId] = [
        'id' => $assetId,
        'sha256' => $hash,
        'kind' => $kind,
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
        'content_type' => 'image/svg+xml',
        'svg' => $emblem['svg'],
        'width' => (float)$emblem['box']['w'],
        'height' => (float)$emblem['box']['h'],
        'created_at' => gmdate('c'),
      ];
    }
    $refs[$ownerType . ':' . $ownerId] = [
      'owner_type' => $ownerType,
      'owner_id' => $ownerId,
      'asset_id' => $assetId,
      'emblem_box' => $box,
      'emblem_scale' => $scale,
    ];
  };

  foreach (($state['provinces'] ?? []) as $pid => $province) {
    if (!is_array($province)) continue;
    $normalized = api_normalize_svg_payload((string)($province['emblem_svg'] ?? ''));
    if ($normalized === null) continue;
    $ownerId = (string)((int)($province['pid'] ?? $pid));
    $box = isset($province['emblem_box']) && is_array($province['emblem_box']) ? $province['emblem_box'] : null;
    $storeAsset('province_emblem', 'province', $ownerId, $normalized, $box, null);
  }

  foreach (['kingdoms', 'great_houses', 'minor_houses', 'free_cities'] as $realmType) {
    foreach (($state[$realmType] ?? []) as $realmId => $realm) {
      if (!is_array($realm)) continue;
      $normalized = api_normalize_svg_payload((string)($realm['emblem_svg'] ?? ''));
      if ($normalized === null) continue;
      $box = isset($realm['emblem_box']) && is_array($realm['emblem_box']) ? $realm['emblem_box'] : null;
      $scale = isset($realm['emblem_scale']) ? (float)$realm['emblem_scale'] : null;
            $ownerTypeMap = ['kingdoms' => 'kingdom', 'great_houses' => 'great_house', 'minor_houses' => 'minor_house', 'free_cities' => 'free_city'];
      $ownerType = $ownerTypeMap[$realmType] ?? $realmType;
      $storeAsset('realm_emblem', $ownerType, (string)$realmId, $normalized, $box, $scale);
    }
  }

  return ['assets' => array_values($assets), 'refs' => array_values($refs)];
}


function api_build_migrated_bundle(array $state, bool $includeLegacySvg = false): array {
  $migration = api_emblem_migration_from_state($state);
  $refsByOwner = [];
  foreach (($migration['refs'] ?? []) as $ref) {
    if (!is_array($ref)) continue;
    $ownerKey = (string)($ref['owner_type'] ?? '') . ':' . (string)($ref['owner_id'] ?? '');
    if ($ownerKey === ':') continue;
    $refsByOwner[$ownerKey] = (string)($ref['asset_id'] ?? '');
  }

  $converted = $state;
  $strippedCount = 0;

  foreach (($converted['provinces'] ?? []) as $pid => &$province) {
    if (!is_array($province)) continue;
    $ownerId = (string)((int)($province['pid'] ?? $pid));
    $ownerKey = 'province:' . $ownerId;
    if (isset($refsByOwner[$ownerKey]) && $refsByOwner[$ownerKey] !== '') {
      $province['emblem_asset_id'] = $refsByOwner[$ownerKey];
      if (!$includeLegacySvg && array_key_exists('emblem_svg', $province)) {
        unset($province['emblem_svg']);
        $strippedCount++;
      }
    }
  }
  unset($province);

  $ownerTypeMap = ['kingdoms' => 'kingdom', 'great_houses' => 'great_house', 'minor_houses' => 'minor_house', 'free_cities' => 'free_city'];
  foreach ($ownerTypeMap as $bucket => $ownerType) {
    foreach (($converted[$bucket] ?? []) as $realmId => &$realm) {
      if (!is_array($realm)) continue;
      $ownerKey = $ownerType . ':' . (string)$realmId;
      if (isset($refsByOwner[$ownerKey]) && $refsByOwner[$ownerKey] !== '') {
        $realm['emblem_asset_id'] = $refsByOwner[$ownerKey];
        if (!$includeLegacySvg && array_key_exists('emblem_svg', $realm)) {
          unset($realm['emblem_svg']);
          $strippedCount++;
        }
      }
    }
    unset($realm);
  }

  return [
    'migrated_state' => $converted,
    'emblem_assets' => $migration['assets'],
    'emblem_refs' => $migration['refs'],
    'stats' => [
      'assets_total' => count($migration['assets']),
      'refs_total' => count($migration['refs']),
      'stripped_legacy_svg_total' => $strippedCount,
      'include_legacy_svg' => $includeLegacySvg,
    ],
  ];
}


function api_validate_state_snapshot_shape(array $state): array {
  $expectedArrays = ['provinces', 'kingdoms', 'great_houses', 'minor_houses', 'free_cities', 'people', 'terrain_types'];
  foreach ($expectedArrays as $k) {
    if (array_key_exists($k, $state) && !is_array($state[$k])) {
      return ['ok' => false, 'error' => 'invalid_state_shape', 'field' => $k];
    }
  }

  if (isset($state['provinces']) && is_array($state['provinces'])) {
    foreach ($state['provinces'] as $pid => $pd) {
      if (!is_array($pd)) return ['ok' => false, 'error' => 'invalid_state_shape', 'field' => 'provinces.' . (string)$pid];
      if (isset($pd['pid']) && !is_numeric($pd['pid'])) return ['ok' => false, 'error' => 'invalid_state_shape', 'field' => 'provinces.' . (string)$pid . '.pid'];
      if (isset($pd['vassals']) && !is_array($pd['vassals'])) return ['ok' => false, 'error' => 'invalid_state_shape', 'field' => 'provinces.' . (string)$pid . '.vassals'];
      if (isset($pd['fill_rgba']) && !($pd['fill_rgba'] === null || (is_array($pd['fill_rgba']) && count($pd['fill_rgba']) === 4))) {
        return ['ok' => false, 'error' => 'invalid_state_shape', 'field' => 'provinces.' . (string)$pid . '.fill_rgba'];
      }
      if (isset($pd['emblem_box']) && !($pd['emblem_box'] === null || (is_array($pd['emblem_box']) && count($pd['emblem_box']) === 2))) {
        return ['ok' => false, 'error' => 'invalid_state_shape', 'field' => 'provinces.' . (string)$pid . '.emblem_box'];
      }
    }
  }

  foreach (['kingdoms', 'great_houses', 'minor_houses', 'free_cities'] as $bucket) {
    if (!isset($state[$bucket]) || !is_array($state[$bucket])) continue;
    foreach ($state[$bucket] as $id => $item) {
      if (!is_array($item)) return ['ok' => false, 'error' => 'invalid_state_shape', 'field' => $bucket . '.' . (string)$id];
      if (isset($item['name']) && !is_string($item['name'])) return ['ok' => false, 'error' => 'invalid_state_shape', 'field' => $bucket . '.' . (string)$id . '.name'];
      if (isset($item['color']) && !is_string($item['color'])) return ['ok' => false, 'error' => 'invalid_state_shape', 'field' => $bucket . '.' . (string)$id . '.color'];
      if (isset($item['capital_pid']) && !is_numeric($item['capital_pid'])) return ['ok' => false, 'error' => 'invalid_state_shape', 'field' => $bucket . '.' . (string)$id . '.capital_pid'];
      if (isset($item['province_pids']) && !is_array($item['province_pids'])) return ['ok' => false, 'error' => 'invalid_state_shape', 'field' => $bucket . '.' . (string)$id . '.province_pids'];
      if (isset($item['province_pids']) && is_array($item['province_pids'])) {
        foreach ($item['province_pids'] as $i => $pidVal) if (!is_numeric($pidVal)) return ['ok' => false, 'error' => 'invalid_state_shape', 'field' => $bucket . '.' . (string)$id . '.province_pids.' . (string)$i];
      }
    }
  }

  if (isset($state['people']) && is_array($state['people'])) {
    foreach ($state['people'] as $i => $p) {
      if (is_string($p)) continue;
      if (is_array($p)) {
        if (isset($p['name']) && !is_string($p['name'])) return ['ok' => false, 'error' => 'invalid_state_shape', 'field' => 'people.' . (string)$i . '.name'];
        continue;
      }
      return ['ok' => false, 'error' => 'invalid_state_shape', 'field' => 'people.' . (string)$i];
    }
  }

  return ['ok' => true];
}

function api_normalize_state_snapshot_for_backend(array $state): array {
  if (isset($state['people']) && is_array($state['people'])) {
    $out = [];
    $seen = [];
    foreach ($state['people'] as $person) {
      $name = '';
      if (is_string($person)) {
        $name = trim($person);
      } elseif (is_array($person)) {
        $name = trim((string)($person['name'] ?? ''));
      }
      if ($name === '') continue;
      $key = mb_strtolower($name, 'UTF-8');
      if (isset($seen[$key])) continue;
      $seen[$key] = true;
      $out[] = $name;
    }
    $state['people'] = $out;
  }

  if (isset($state['provinces']) && is_array($state['provinces'])) {
    foreach ($state['provinces'] as $pid => $province) {
      if (!is_array($province)) continue;
      if (isset($province['province_card_image']) && is_string($province['province_card_image']) && str_starts_with($province['province_card_image'], 'data:')) {
        unset($province['province_card_image']);
      }
      if (isset($province['province_card_base_image']) && is_string($province['province_card_base_image']) && str_starts_with($province['province_card_base_image'], 'data:')) {
        unset($province['province_card_base_image']);
      }
      $state['provinces'][$pid] = $province;
    }
  }

  return $state;
}

function api_validate_migration_apply_payload(array $payload): array {
  $allowedTop = ['state', 'replace_map_state', 'include_legacy_svg', 'if_match'];
  foreach ($payload as $k => $_v) {
    if (!in_array((string)$k, $allowedTop, true)) return ['ok' => false, 'error' => 'invalid_payload_field', 'field' => (string)$k];
  }
  if (array_key_exists('state', $payload) && !is_array($payload['state'])) {
    return ['ok' => false, 'error' => 'invalid_payload_type', 'field' => 'state'];
  }
  if (array_key_exists('state', $payload) && is_array($payload['state'])) {
    $shape = api_validate_state_snapshot_shape($payload['state']);
    if (!$shape['ok']) return $shape;
  }
  if (array_key_exists('if_match', $payload) && !is_string($payload['if_match'])) {
    return ['ok' => false, 'error' => 'invalid_payload_type', 'field' => 'if_match'];
  }
  foreach (['replace_map_state', 'include_legacy_svg'] as $bf) {
    if (array_key_exists($bf, $payload) && !is_bool($payload[$bf])) {
      return ['ok' => false, 'error' => 'invalid_payload_type', 'field' => $bf];
    }
  }
  return ['ok' => true];
}

function api_validate_migration_export_payload(array $payload): array {
  $allowedTop = ['state', 'include_legacy_svg'];
  foreach ($payload as $k => $_v) {
    if (!in_array((string)$k, $allowedTop, true)) return ['ok' => false, 'error' => 'invalid_payload_field', 'field' => (string)$k];
  }
  if (array_key_exists('state', $payload) && !is_array($payload['state'])) {
    return ['ok' => false, 'error' => 'invalid_payload_type', 'field' => 'state'];
  }
  if (array_key_exists('state', $payload) && is_array($payload['state'])) {
    $shape = api_validate_state_snapshot_shape($payload['state']);
    if (!$shape['ok']) return $shape;
  }
  if (array_key_exists('if_match', $payload)) {
    return ['ok' => false, 'error' => 'invalid_payload_field', 'field' => 'if_match'];
  }
  if (array_key_exists('include_legacy_svg', $payload) && !is_bool($payload['include_legacy_svg'])) {
    return ['ok' => false, 'error' => 'invalid_payload_type', 'field' => 'include_legacy_svg'];
  }
  return ['ok' => true];
}

function api_validate_emblems_persist_payload(array $payload): array {
  $allowedTop = ['migrate', 'if_match'];
  foreach ($payload as $k => $_v) {
    if (!in_array((string)$k, $allowedTop, true)) return ['ok' => false, 'error' => 'invalid_payload_field', 'field' => (string)$k];
  }
  if (array_key_exists('if_match', $payload) && !is_string($payload['if_match'])) {
    return ['ok' => false, 'error' => 'invalid_payload_type', 'field' => 'if_match'];
  }
  if (array_key_exists('migrate', $payload) && !is_bool($payload['migrate'])) {
    return ['ok' => false, 'error' => 'invalid_payload_type', 'field' => 'migrate'];
  }
  return ['ok' => true, 'migrate' => (bool)($payload['migrate'] ?? false)];
}

function api_validate_jobs_rebuild_payload(array $payload): array {
  $allowedTop = ['mode', 'max_attempts'];
  foreach ($payload as $k => $_v) {
    if (!in_array((string)$k, $allowedTop, true)) return ['ok' => false, 'error' => 'invalid_payload_field', 'field' => (string)$k];
  }
  if (array_key_exists('if_match', $payload)) {
    return ['ok' => false, 'error' => 'invalid_payload_field', 'field' => 'if_match'];
  }
  if (array_key_exists('mode', $payload) && !is_string($payload['mode'])) {
    return ['ok' => false, 'error' => 'invalid_payload_type', 'field' => 'mode'];
  }
  if (array_key_exists('max_attempts', $payload) && (!is_int($payload['max_attempts']) || $payload['max_attempts'] < 1 || $payload['max_attempts'] > 10)) {
    return ['ok' => false, 'error' => 'invalid_payload_type', 'field' => 'max_attempts'];
  }
  return ['ok' => true];
}

function api_validate_province_changes_schema(array $changes, string $prefix = 'changes'): array {
  $allowed = [
    'name', 'owner', 'suzerain', 'senior', 'terrain',
    'vassals', 'fill_rgba', 'emblem_svg', 'emblem_box', 'emblem_asset_id',
    'kingdom_id', 'great_house_id', 'minor_house_id', 'free_city_id',
    'province_card_image',
  ];
  foreach ($changes as $field => $value) {
    $f = (string)$field;
    if (!in_array($f, $allowed, true)) return ['ok' => false, 'error' => 'invalid_field', 'field' => $prefix . '.' . $f];
    if (in_array($f, ['name','owner','suzerain','senior','terrain','emblem_svg','emblem_asset_id','kingdom_id','great_house_id','minor_house_id','free_city_id','province_card_image'], true) && !is_string($value)) {
      return ['ok' => false, 'error' => 'invalid_type', 'field' => $prefix . '.' . $f];
    }
    if ($f === 'vassals') {
      if (!is_array($value)) return ['ok' => false, 'error' => 'invalid_type', 'field' => $prefix . '.vassals'];
      foreach ($value as $i => $v) {
        if (!is_scalar($v)) return ['ok' => false, 'error' => 'invalid_type', 'field' => $prefix . '.vassals.' . (string)$i];
      }
    }
    if ($f === 'fill_rgba') {
      if (!($value === null || (is_array($value) && count($value) === 4))) return ['ok' => false, 'error' => 'invalid_type', 'field' => $prefix . '.fill_rgba'];
      if (is_array($value)) {
        foreach ($value as $i => $c) if (!is_numeric($c)) return ['ok' => false, 'error' => 'invalid_type', 'field' => $prefix . '.fill_rgba.' . (string)$i];
      }
    }
    if ($f === 'emblem_box') {
      if (!($value === null || (is_array($value) && count($value) === 2))) return ['ok' => false, 'error' => 'invalid_type', 'field' => $prefix . '.emblem_box'];
      if (is_array($value)) {
        foreach ($value as $i => $c) if (!is_numeric($c)) return ['ok' => false, 'error' => 'invalid_type', 'field' => $prefix . '.emblem_box.' . (string)$i];
      }
    }
  }
  return ['ok' => true];
}

function api_validate_realm_changes_schema(array $changes, string $prefix = 'changes'): array {
  $allowed = ['name', 'color', 'capital_pid', 'emblem_scale', 'emblem_svg', 'emblem_box', 'province_pids'];
  foreach ($changes as $field => $value) {
    $f = (string)$field;
    if (!in_array($f, $allowed, true)) return ['ok' => false, 'error' => 'invalid_field', 'field' => $prefix . '.' . $f];
    if (in_array($f, ['name','color','emblem_svg'], true) && !is_string($value)) return ['ok' => false, 'error' => 'invalid_type', 'field' => $prefix . '.' . $f];
    if ($f === 'capital_pid' && !is_numeric($value)) return ['ok' => false, 'error' => 'invalid_type', 'field' => $prefix . '.capital_pid'];
    if ($f === 'emblem_scale' && !is_numeric($value)) return ['ok' => false, 'error' => 'invalid_type', 'field' => $prefix . '.emblem_scale'];
    if ($f === 'emblem_box') {
      if (!($value === null || (is_array($value) && count($value) === 2))) return ['ok' => false, 'error' => 'invalid_type', 'field' => $prefix . '.emblem_box'];
      if (is_array($value)) foreach ($value as $i => $c) if (!is_numeric($c)) return ['ok' => false, 'error' => 'invalid_type', 'field' => $prefix . '.emblem_box.' . (string)$i];
    }
    if ($f === 'province_pids') {
      if (!is_array($value)) return ['ok' => false, 'error' => 'invalid_type', 'field' => $prefix . '.province_pids'];
      foreach ($value as $i => $pid) if (!is_numeric($pid)) return ['ok' => false, 'error' => 'invalid_type', 'field' => $prefix . '.province_pids.' . (string)$i];
    }
  }
  return ['ok' => true];
}

function api_validate_province_patch_payload(array $payload): array {
  $allowedTop = ['pid', 'changes', 'if_match'];
  foreach ($payload as $k => $_v) {
    if (!in_array((string)$k, $allowedTop, true)) return ['ok' => false, 'error' => 'invalid_payload_field', 'field' => (string)$k];
  }
  if (array_key_exists('if_match', $payload) && !is_string($payload['if_match'])) {
    return ['ok' => false, 'error' => 'invalid_payload_type', 'field' => 'if_match'];
  }
  $pid = (int)($payload['pid'] ?? 0);
  if (array_key_exists('if_match', $payload) && !is_string($payload['if_match'])) {
    return ['ok' => false, 'error' => 'invalid_payload_type', 'field' => 'if_match'];
  }
  $changes = $payload['changes'] ?? null;
  if ($pid <= 0 || !is_array($changes)) {
    return ['ok' => false, 'error' => 'invalid_payload', 'required' => ['pid:int', 'changes:object', 'if_match:string(header or body)']];
  }
  $schema = api_validate_province_changes_schema($changes, 'changes');
  if (!$schema['ok']) return $schema;
  return ['ok' => true, 'pid' => $pid, 'changes' => $changes];
}

function api_validate_realm_patch_payload(array $payload): array {
  $allowedTop = ['type', 'id', 'changes', 'if_match'];
  foreach ($payload as $k => $_v) {
    if (!in_array((string)$k, $allowedTop, true)) return ['ok' => false, 'error' => 'invalid_payload_field', 'field' => (string)$k];
  }
  if (array_key_exists('if_match', $payload) && !is_string($payload['if_match'])) {
    return ['ok' => false, 'error' => 'invalid_payload_type', 'field' => 'if_match'];
  }
  $type = trim((string)($payload['type'] ?? ''));
  $id = trim((string)($payload['id'] ?? ''));
  $changes = $payload['changes'] ?? null;
  if ($type === '' || $id === '' || !is_array($changes)) {
    return ['ok' => false, 'error' => 'invalid_payload', 'required' => ['type:string', 'id:string', 'changes:object', 'if_match:string(header or body)']];
  }
  $schema = api_validate_realm_changes_schema($changes, 'changes');
  if (!$schema['ok']) return $schema;
  return ['ok' => true, 'type' => $type, 'id' => $id, 'changes' => $changes];
}

function api_validate_changes_apply_payload(array $payload): array {
  $allowedTop = ['changes', 'if_match'];
  foreach ($payload as $k => $_v) {
    if (!in_array((string)$k, $allowedTop, true)) return ['ok' => false, 'error' => 'invalid_payload_field', 'field' => (string)$k];
  }
  $changes = $payload['changes'] ?? null;
  if (!is_array($changes)) {
    return ['ok' => false, 'error' => 'invalid_payload', 'required' => ['changes:list', 'if_match:string(header or body)']];
  }
  foreach ($changes as $idx => $entry) {
    if (!is_array($entry)) return ['ok' => false, 'error' => 'invalid_change_entry', 'index' => $idx];
    $kind = (string)($entry['kind'] ?? '');
    $allowedEntry = $kind === 'province' ? ['kind','pid','changes'] : ($kind === 'realm' ? ['kind','type','id','changes'] : ['kind','changes']);
    foreach ($entry as $k => $_v) {
      if (!in_array((string)$k, $allowedEntry, true)) return ['ok' => false, 'error' => 'invalid_change_field', 'index' => $idx, 'field' => 'changes.' . (string)$idx . '.' . (string)$k];
    }
    if (!in_array($kind, ['province', 'realm'], true)) return ['ok' => false, 'error' => 'invalid_change_kind', 'index' => $idx];
    if (!is_array($entry['changes'] ?? null)) return ['ok' => false, 'error' => 'invalid_change_changes', 'index' => $idx];
    if ($kind === 'province') {
      if ((int)($entry['pid'] ?? 0) <= 0) return ['ok' => false, 'error' => 'invalid_change_pid', 'index' => $idx];
      $schema = api_validate_province_changes_schema((array)$entry['changes'], 'changes.' . (string)$idx . '.changes');
      if (!$schema['ok']) return ['ok' => false, 'error' => (string)$schema['error'], 'index' => $idx, 'field' => (string)($schema['field'] ?? '')];
    }
    if ($kind === 'realm') {
      if (trim((string)($entry['type'] ?? '')) === '' || trim((string)($entry['id'] ?? '')) === '') return ['ok' => false, 'error' => 'invalid_change_realm_identity', 'index' => $idx];
      $schema = api_validate_realm_changes_schema((array)$entry['changes'], 'changes.' . (string)$idx . '.changes');
      if (!$schema['ok']) return ['ok' => false, 'error' => (string)$schema['error'], 'index' => $idx, 'field' => (string)($schema['field'] ?? '')];
    }
  }
  return ['ok' => true, 'changes' => $changes];
}

function api_patch_province(array $state, int $pid, array $changes): array {
  $key = (string)$pid;
  if (!isset($state['provinces']) || !is_array($state['provinces']) || !isset($state['provinces'][$key]) || !is_array($state['provinces'][$key])) {
    return ['ok' => false, 'error' => 'not_found'];
  }

  $allowed = [
    'name', 'owner', 'suzerain', 'senior', 'terrain',
    'vassals', 'fill_rgba', 'emblem_svg', 'emblem_box', 'emblem_asset_id',
    'kingdom_id', 'great_house_id', 'minor_house_id', 'free_city_id',
    'province_card_image',
  ];

  foreach ($changes as $field => $value) {
    if (!in_array((string)$field, $allowed, true)) {
      return ['ok' => false, 'error' => 'invalid_field', 'field' => (string)$field];
    }
    if (in_array((string)$field, ['name','owner','suzerain','senior','terrain','emblem_svg','emblem_asset_id','kingdom_id','great_house_id','minor_house_id','free_city_id','province_card_image'], true) && !is_string($value)) {
      return ['ok' => false, 'error' => 'invalid_type', 'field' => (string)$field];
    }
    if ($field === 'vassals' && !is_array($value)) return ['ok' => false, 'error' => 'invalid_type', 'field' => 'vassals'];
    if ($field === 'fill_rgba' && !($value === null || (is_array($value) && count($value) === 4))) return ['ok' => false, 'error' => 'invalid_type', 'field' => 'fill_rgba'];
    if ($field === 'emblem_box' && !($value === null || (is_array($value) && count($value) === 2))) return ['ok' => false, 'error' => 'invalid_type', 'field' => 'emblem_box'];
  }

  $updated = 0;
  foreach ($changes as $field => $value) {
    if ($field === 'vassals') {
      $value = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $value), static fn($v) => $v !== ''));
    }

    if ($field === 'fill_rgba') {
      if ($value === null) {
        $state['provinces'][$key]['fill_rgba'] = null;
        $updated++;
        continue;
      }
      $value = [
        max(0, min(255, (int)$value[0])),
        max(0, min(255, (int)$value[1])),
        max(0, min(255, (int)$value[2])),
        max(0, min(255, (int)$value[3])),
      ];
    }

    if ($field === 'emblem_box') {
      if ($value === null) {
        $state['provinces'][$key]['emblem_box'] = null;
        $updated++;
        continue;
      }
      $value = [(float)$value[0], (float)$value[1]];
    }

    if (is_string($value)) $value = trim($value);
    $state['provinces'][$key][$field] = $value;
    $updated++;
  }

  $state['generated_utc'] = gmdate('c');
  return ['ok' => true, 'state' => $state, 'updated_fields' => $updated];
}


function api_patch_realm(array $state, string $type, string $id, array $changes): array {
  $allowedTypes = ['kingdoms', 'great_houses', 'minor_houses', 'free_cities'];
  if (!in_array($type, $allowedTypes, true)) return ['ok' => false, 'error' => 'invalid_type'];
  if (!isset($state[$type]) || !is_array($state[$type]) || !isset($state[$type][$id]) || !is_array($state[$type][$id])) {
    return ['ok' => false, 'error' => 'not_found'];
  }

  $allowed = ['name', 'color', 'capital_pid', 'emblem_scale', 'emblem_svg', 'emblem_box', 'province_pids'];
  foreach ($changes as $field => $value) {
    if (!in_array((string)$field, $allowed, true)) return ['ok' => false, 'error' => 'invalid_field', 'field' => (string)$field];
    if (in_array((string)$field, ['name','color','emblem_svg'], true) && !is_string($value)) return ['ok' => false, 'error' => 'invalid_type', 'field' => (string)$field];
    if ($field === 'capital_pid' && !is_numeric($value)) return ['ok' => false, 'error' => 'invalid_type', 'field' => 'capital_pid'];
    if ($field === 'emblem_scale' && !is_numeric($value)) return ['ok' => false, 'error' => 'invalid_type', 'field' => 'emblem_scale'];
    if ($field === 'emblem_box' && !($value === null || (is_array($value) && count($value) === 2))) return ['ok' => false, 'error' => 'invalid_type', 'field' => 'emblem_box'];
    if ($field === 'province_pids' && !is_array($value)) return ['ok' => false, 'error' => 'invalid_type', 'field' => 'province_pids'];
  }

  $updated = 0;
  foreach ($changes as $field => $value) {
    if ($field === 'capital_pid') {
      $value = max(0, (int)$value);
    }

    if ($field === 'emblem_scale') {
      $value = max(0.2, min(3.0, (float)$value));
    }

    if ($field === 'emblem_box') {
      if ($value === null) {
        $state[$type][$id]['emblem_box'] = null;
        $updated++;
        continue;
      }
      $value = [(float)$value[0], (float)$value[1]];
    }

    if ($field === 'province_pids') {
      $value = array_values(array_unique(array_map(static fn($v) => max(0, (int)$v), $value)));
      $value = array_values(array_filter($value, static fn($v) => $v > 0));
    }

    if (is_string($value)) $value = trim($value);
    $state[$type][$id][$field] = $value;
    $updated++;
  }

  $state['generated_utc'] = gmdate('c');
  return ['ok' => true, 'state' => $state, 'updated_fields' => $updated];
}

function api_write_migrated_bundle(array $bundle, bool $replaceMapState): array {
  $root = api_repo_root();
  $dataDir = $root . '/data';
  $mapPath = $dataDir . '/map_state.json';
  $mapMigratedPath = $dataDir . '/map_state.migrated.json';
  $assetsPath = $dataDir . '/emblem_assets.json';
  $refsPath = $dataDir . '/emblem_refs.json';

  $ok = true;
  $ok = $ok && api_atomic_write_json($assetsPath, ['generated_at' => gmdate('c'), 'assets' => $bundle['emblem_assets'] ?? []]);
  $ok = $ok && api_atomic_write_json($refsPath, ['generated_at' => gmdate('c'), 'refs' => $bundle['emblem_refs'] ?? []]);
  $ok = $ok && api_atomic_write_json($mapMigratedPath, $bundle['migrated_state'] ?? []);

  $backupPath = null;
  if ($ok && $replaceMapState) {
    $backupPath = $dataDir . '/map_state.backup.' . gmdate('Ymd_His') . '.json';
    $ok = $ok && @copy($mapPath, $backupPath);
    $ok = $ok && api_atomic_write_json($mapPath, $bundle['migrated_state'] ?? []);
  }

  return [
    'ok' => $ok,
    'paths' => [
      'map_migrated' => 'data/map_state.migrated.json',
      'assets' => 'data/emblem_assets.json',
      'refs' => 'data/emblem_refs.json',
      'map_backup' => $backupPath ? str_replace($root . '/', '', $backupPath) : null,
      'map_replaced' => $replaceMapState,
    ],
  ];
}




function api_request_if_match(array $payload = []): string {
  $h = trim((string)($_SERVER['HTTP_IF_MATCH'] ?? ''));
  if ($h !== '') return trim($h, '" ');
  $b = trim((string)($payload['if_match'] ?? ''));
  return trim($b, '" ');
}

function api_if_match_policy(): array {
  return [
    'required_for_write' => true,
    'accept_payload_fallback' => true,
  ];
}

function api_check_if_match(array $state, array $payload = [], ?bool $requiredOverride = null): array {
  $policy = api_if_match_policy();
  $provided = api_request_if_match($payload);
  $expected = api_state_version_hash($state);
  $required = ($requiredOverride === null) ? (bool)($policy['required_for_write'] ?? false) : $requiredOverride;
  if ($provided === '') {
    if (!$required) {
      return ['ok' => true, 'required' => false, 'expected' => $expected, 'provided' => ''];
    }
    return ['ok' => false, 'error' => 'if_match_required', 'required' => true, 'expected' => $expected, 'provided' => ''];
  }
  if (!hash_equals($expected, $provided)) {
    return ['ok' => false, 'error' => 'version_conflict', 'required' => true, 'expected' => $expected, 'provided' => $provided];
  }
  return ['ok' => true, 'required' => true, 'expected' => $expected, 'provided' => $provided];
}

function api_state_version_hash(array $state): string {
  return hash('sha256', (string)api_state_mtime() . ':' . (string)filesize(api_state_path()));
}

function api_layer_color_for_province(array $state, array $pd, string $mode): ?array {
  if ($mode === 'provinces') {
    $rgba = $pd['fill_rgba'] ?? null;
    if (is_array($rgba) && count($rgba) === 4) {
      return [max(0,min(255,(int)$rgba[0])), max(0,min(255,(int)$rgba[1])), max(0,min(255,(int)$rgba[2])), max(0,min(255,(int)$rgba[3]))];
    }
    return null;
  }

  $modeMap = [
    'kingdoms' => ['field' => 'kingdom_id', 'bucket' => 'kingdoms', 'fallback' => '#ff3b30'],
    'great_houses' => ['field' => 'great_house_id', 'bucket' => 'great_houses', 'fallback' => '#ff3b30'],
    'minor_houses' => ['field' => 'minor_house_id', 'bucket' => 'minor_houses', 'fallback' => '#ffd166'],
    'free_cities' => ['field' => 'free_city_id', 'bucket' => 'free_cities', 'fallback' => '#ff7a1a'],
  ];
  if (!isset($modeMap[$mode])) return null;
  $cfg = $modeMap[$mode];
  $id = trim((string)($pd[$cfg['field']] ?? ''));
  if ($id === '') return null;
  $realm = $state[$cfg['bucket']][$id] ?? null;
  if (!is_array($realm)) return null;
  $hex = (string)($realm['color'] ?? $cfg['fallback']);
  if (!preg_match('/^#?[0-9a-fA-F]{6}$/', $hex)) $hex = $cfg['fallback'];
  if ($hex[0] !== '#') $hex = '#' . $hex;
  $n = hexdec(substr($hex,1));
  return [($n>>16)&255, ($n>>8)&255, $n&255, 170];
}

function api_build_layer_payload(array $state, string $mode): array {
  $items = [];
  foreach (($state['provinces'] ?? []) as $pid => $pd) {
    if (!is_array($pd)) continue;
    $pidNum = (int)($pd['pid'] ?? $pid);
    if ($pidNum <= 0) continue;
    $rgba = api_layer_color_for_province($state, $pd, $mode);
    if (!is_array($rgba)) continue;
    $items[] = ['pid' => $pidNum, 'rgba' => $rgba];
  }

  return [
    'mode' => $mode,
    'version' => api_state_version_hash($state),
    'items' => $items,
    'total' => count($items),
  ];
}

function api_apply_changeset(array $state, array $changes): array {
  $applied = 0;
  $errors = [];

  foreach ($changes as $idx => $entry) {
    if (!is_array($entry)) {
      $errors[] = ['index' => $idx, 'error' => 'invalid_entry'];
      continue;
    }

    $kind = (string)($entry['kind'] ?? '');
    $delta = $entry['changes'] ?? null;
    if (!is_array($delta)) {
      $errors[] = ['index' => $idx, 'error' => 'invalid_changes'];
      continue;
    }

    if ($kind === 'province') {
      $pid = (int)($entry['pid'] ?? 0);
      if ($pid <= 0) {
        $errors[] = ['index' => $idx, 'error' => 'invalid_pid'];
        continue;
      }
      $res = api_patch_province($state, $pid, $delta);
      if (!$res['ok']) {
        $errors[] = ['index' => $idx, 'error' => (string)($res['error'] ?? 'patch_failed')];
        continue;
      }
      $state = $res['state'];
      $applied++;
      continue;
    }

    if ($kind === 'realm') {
      $type = trim((string)($entry['type'] ?? ''));
      $id = trim((string)($entry['id'] ?? ''));
      if ($type === '' || $id === '') {
        $errors[] = ['index' => $idx, 'error' => 'invalid_realm_identity'];
        continue;
      }
      $res = api_patch_realm($state, $type, $id, $delta);
      if (!$res['ok']) {
        $errors[] = ['index' => $idx, 'error' => (string)($res['error'] ?? 'patch_failed')];
        continue;
      }
      $state = $res['state'];
      $applied++;
      continue;
    }

    $errors[] = ['index' => $idx, 'error' => 'unknown_kind'];
  }

  return ['state' => $state, 'applied' => $applied, 'errors' => $errors];
}


function api_jobs_path(): string {
  return api_repo_root() . '/data/jobs.json';
}

function api_load_jobs(): array {
  $path = api_jobs_path();
  if (!is_file($path)) return ['jobs' => []];
  $raw = @file_get_contents($path);
  if (!is_string($raw) || trim($raw) === '') return ['jobs' => []];
  $decoded = json_decode($raw, true);
  if (!is_array($decoded) || !is_array($decoded['jobs'] ?? null)) return ['jobs' => []];
  return $decoded;
}

function api_save_jobs(array $jobsPayload): bool {
  return api_atomic_write_json(api_jobs_path(), $jobsPayload);
}

function api_create_job(string $type, array $payload): array {
  $jobsPayload = api_load_jobs();
  $jobs = $jobsPayload['jobs'] ?? [];
  $id = 'job_' . substr(hash('sha256', $type . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX)), 0, 16);
  $job = [
    'id' => $id,
    'type' => $type,
    'status' => 'queued',
    'payload' => $payload,
    'attempts' => 0,
    'max_attempts' => (int)($payload['max_attempts'] ?? 1),
    'progress' => ['current' => 0, 'total' => 0, 'percent' => 0],
    'created_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
  ];
  $jobs[] = $job;
  $jobsPayload['jobs'] = $jobs;
  if (!api_save_jobs($jobsPayload)) return ['ok' => false, 'error' => 'write_failed'];
  return ['ok' => true, 'job' => $job];
}

function api_find_job(string $id): ?array {
  $jobsPayload = api_load_jobs();
  foreach (($jobsPayload['jobs'] ?? []) as $job) {
    if (!is_array($job)) continue;
    if ((string)($job['id'] ?? '') === $id) return $job;
  }
  return null;
}


function api_write_render_cache(array $state, string $mode): bool {
  $dir = api_repo_root() . '/data/render_cache';
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
  $payload = api_build_layer_payload($state, $mode);
  $path = $dir . '/' . $mode . '_' . $payload['version'] . '.json';
  return api_atomic_write_json($path, $payload);
}

function api_update_job(array $job): bool {
  $jobsPayload = api_load_jobs();
  $jobs = $jobsPayload['jobs'] ?? [];
  $updated = false;
  foreach ($jobs as $idx => $row) {
    if (!is_array($row)) continue;
    if ((string)($row['id'] ?? '') !== (string)($job['id'] ?? '')) continue;
    $jobs[$idx] = $job;
    $updated = true;
    break;
  }
  if (!$updated) return false;
  $jobsPayload['jobs'] = $jobs;
  return api_save_jobs($jobsPayload);
}

function api_run_next_job(array $state): array {
  $jobsPayload = api_load_jobs();
  $jobs = $jobsPayload['jobs'] ?? [];
  $targetIdx = -1;
  $job = null;
  foreach ($jobs as $idx => $row) {
    if (!is_array($row)) continue;
    if ((string)($row['status'] ?? '') !== 'queued') continue;
    $targetIdx = $idx;
    $job = $row;
    break;
  }
  if (!is_array($job)) return ['ok' => true, 'processed' => false];

  $job['status'] = 'running';
  $job['attempts'] = (int)($job['attempts'] ?? 0) + 1;
  $job['progress'] = ['current' => 0, 'total' => 0, 'percent' => 0];
  $job['updated_at'] = gmdate('c');
  $jobs[$targetIdx] = $job;
  $jobsPayload['jobs'] = $jobs;
  if (!api_save_jobs($jobsPayload)) return ['ok' => false, 'error' => 'write_failed'];

  $type = (string)($job['type'] ?? '');
  if ($type === 'rebuild_layers') {
    $mode = (string)($job['payload']['mode'] ?? 'all');
    $modes = $mode === 'all' ? ['provinces','kingdoms','great_houses','minor_houses','free_cities'] : [$mode];
    $allOk = true;
    $total = count($modes);
    $done = 0;
    foreach ($modes as $m) {
      $okMode = api_write_render_cache($state, $m);
      $allOk = $allOk && $okMode;
      $done++;
      $job['progress'] = ['current' => $done, 'total' => $total, 'percent' => (int)floor(($done / max(1, $total)) * 100)];
    }
    if ($allOk) {
      $job['status'] = 'succeeded';
    } else {
      $maxAttempts = max(1, (int)($job['max_attempts'] ?? 1));
      $job['status'] = ((int)$job['attempts'] < $maxAttempts) ? 'queued' : 'failed';
    }
    $job['result'] = ['modes' => $modes, 'ok' => $allOk];
  } else {
    $maxAttempts = max(1, (int)($job['max_attempts'] ?? 1));
    $job['status'] = ((int)$job['attempts'] < $maxAttempts) ? 'queued' : 'failed';
    $job['result'] = ['error' => 'unsupported_job_type'];
  }

  $job['updated_at'] = gmdate('c');
  if (!api_update_job($job)) return ['ok' => false, 'error' => 'write_failed'];
  return ['ok' => true, 'processed' => true, 'job' => $job];
}

function api_atomic_write_json(string $path, array $payload): bool {
  $tmp = $path . '.tmp';
  $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($raw === false) return false;
  if (@file_put_contents($tmp, $raw) === false) return false;
  return @rename($tmp, $path);
}
