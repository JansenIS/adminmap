<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

$state = api_load_state();
$mtime = api_state_mtime();
$migration = api_emblem_migration_from_state($state);

$shouldPersist = (($_GET['migrate'] ?? '0') === '1');
$assetPath = api_repo_root() . '/data/emblem_assets.json';
$refsPath = api_repo_root() . '/data/emblem_refs.json';
$persisted = false;
if ($shouldPersist) {
  $persisted = api_atomic_write_json($assetPath, ['generated_at' => gmdate('c'), 'assets' => $migration['assets']])
    && api_atomic_write_json($refsPath, ['generated_at' => gmdate('c'), 'refs' => $migration['refs']]);
}

$offset = api_limit_int('offset', 0, 0, 1_000_000);
$limit = api_limit_int('limit', 100, 1, 1000);
$assetsSlice = array_slice($migration['assets'], $offset, $limit);

api_json_response([
  'draft' => true,
  'legacy_supported' => ['plain_svg', 'data_image_svg_base64'],
  'assets_total' => count($migration['assets']),
  'refs_total' => count($migration['refs']),
  'offset' => $offset,
  'limit' => $limit,
  'items' => $assetsSlice,
  'persisted' => $persisted,
  'persist_targets' => ['data/emblem_assets.json', 'data/emblem_refs.json'],
], 200, $mtime);
