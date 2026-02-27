<?php

declare(strict_types=1);

function api_json_response(array $payload, int $status = 200, ?int $sourceMtime = null): void {
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

  $updated = 0;
  foreach ($changes as $field => $value) {
    if (!in_array((string)$field, $allowed, true)) continue;

    if ($field === 'vassals') {
      if (!is_array($value)) continue;
      $value = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $value), static fn($v) => $v !== ''));
    }

    if ($field === 'fill_rgba') {
      if ($value === null) {
        $state['provinces'][$key]['fill_rgba'] = null;
        $updated++;
        continue;
      }
      if (!is_array($value) || count($value) !== 4) continue;
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
      if (!is_array($value) || count($value) !== 2) continue;
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
  $updated = 0;
  foreach ($changes as $field => $value) {
    if (!in_array((string)$field, $allowed, true)) continue;

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
      if (!is_array($value) || count($value) !== 2) continue;
      $value = [(float)$value[0], (float)$value[1]];
    }

    if ($field === 'province_pids') {
      if (!is_array($value)) continue;
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

function api_atomic_write_json(string $path, array $payload): bool {
  $tmp = $path . '.tmp';
  $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($raw === false) return false;
  if (@file_put_contents($tmp, $raw) === false) return false;
  return @rename($tmp, $path);
}
