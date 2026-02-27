<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/state_api.php';

$state = api_load_state();
$mtime = api_state_mtime();
$offset = api_limit_int('offset', 0, 0, 1_000_000);
$limit = api_limit_int('limit', 100, 1, 500);

$provinces = $state['provinces'] ?? [];
$rows = [];
foreach ($provinces as $pid => $pd) {
  if (!is_array($pd)) continue;
  $pidNum = (int)($pd['pid'] ?? $pid);
  if ($pidNum <= 0) continue;
  $rows[$pidNum] = $pd;
}
ksort($rows, SORT_NUMERIC);
$total = count($rows);
$slice = array_slice($rows, $offset, $limit, true);

$refs = [];
$refsPath = api_repo_root() . '/data/emblem_refs.json';
if (is_file($refsPath)) {
  $decodedRefs = json_decode((string)file_get_contents($refsPath), true);
  if (is_array($decodedRefs)) {
    foreach (($decodedRefs['refs'] ?? []) as $ref) {
      if (!is_array($ref)) continue;
      $refs[$ref['owner_type'] . ':' . $ref['owner_id']] = (string)($ref['asset_id'] ?? '');
    }
  }
} else {
  $migration = api_emblem_migration_from_state($state);
  foreach (($migration['refs'] ?? []) as $ref) {
    if (!is_array($ref)) continue;
    $refs[$ref['owner_type'] . ':' . $ref['owner_id']] = (string)($ref['asset_id'] ?? '');
  }
}

$out = [];
foreach ($slice as $pid => $pd) {
  $key = 'province:' . $pid;
  if (!isset($pd['emblem_asset_id']) && isset($refs[$key]) && $refs[$key] !== '') {
    $pd['emblem_asset_id'] = $refs[$key];
  }
  $out[] = $pd;
}

api_json_response([
  'offset' => $offset,
  'limit' => $limit,
  'total' => $total,
  'items' => $out,
], 200, $mtime);
