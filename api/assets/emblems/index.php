<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET','POST']], 405, api_state_mtime());
}

$payload = [];
if ($method === 'POST') {
  $raw = file_get_contents('php://input');
  $payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : [];
  if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());
  $valid = api_validate_emblems_persist_payload($payload);
  if (!$valid['ok']) api_json_response(['error' => $valid['error'], 'field' => $valid['field'] ?? null], 400, api_state_mtime());
}

$mtime = api_state_mtime();
$shouldPersist = false;
if ($method === 'POST') {
  $shouldPersist = !empty($payload['migrate']);
} else {
  $shouldPersist = (($_GET['migrate'] ?? '0') === '1');
}

$state = null;
if ($shouldPersist) {
  $state = api_load_state();
}

$migration = $shouldPersist
  ? api_emblem_migration_from_state($state)
  : api_load_emblem_bundle_from_file_or_state(null);

$assetPath = api_repo_root() . '/data/emblem_assets.json';
$refsPath = api_repo_root() . '/data/emblem_refs.json';
$persisted = false;
if ($shouldPersist) {
  $ifMatch = api_check_if_match($state, $payload, true);
  if (!$ifMatch['ok']) {
    $status = (($ifMatch['error'] ?? '') === 'if_match_required') ? 428 : 412;
    api_json_response(['error' => ($ifMatch['error'] ?? 'version_conflict'), 'expected_version' => $ifMatch['expected'], 'provided_if_match' => $ifMatch['provided']], $status, $mtime);
  }
  $persisted = api_atomic_write_json($assetPath, ['generated_at' => gmdate('c'), 'assets' => $migration['assets']])
    && api_atomic_write_json($refsPath, ['generated_at' => gmdate('c'), 'refs' => $migration['refs']]);
}

$offset = api_limit_int('offset', 0, 0, 1_000_000);
$limit = api_limit_int('limit', 100, 1, 1000);
$profile = (string)($_GET['profile'] ?? 'full');
$withPayload = (($_GET['with_payload'] ?? '0') === '1');
$assetsSlice = array_slice($migration['assets'], $offset, $limit);

if ($profile === 'compact') {
  $withPayload = false;
}
if (!$withPayload) {
  foreach ($assetsSlice as &$asset) unset($asset['svg']);
  unset($asset);
}

api_json_response([
  'draft' => true,
  'legacy_supported' => ['plain_svg', 'data_image_svg_base64'],
  'profile' => $profile,
  'with_payload' => $withPayload,
  'assets_total' => count($migration['assets']),
  'refs_total' => count($migration['refs']),
  'offset' => $offset,
  'limit' => $limit,
  'items' => $assetsSlice,
  'persisted' => $persisted,
  'persist_targets' => ['data/emblem_assets.json', 'data/emblem_refs.json'],
], 200, $mtime);
