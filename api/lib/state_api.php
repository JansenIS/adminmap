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

function api_atomic_write_json(string $path, array $payload): bool {
  $tmp = $path . '.tmp';
  $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($raw === false) return false;
  if (@file_put_contents($tmp, $raw) === false) return false;
  return @rename($tmp, $path);
}
