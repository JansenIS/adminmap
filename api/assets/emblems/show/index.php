<?php

declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/lib/state_api.php';

$mtime = api_state_mtime();
$assetId = trim((string)($_GET['id'] ?? ''));
if ($assetId === '') api_json_response(['error' => 'invalid_id'], 400, $mtime);

$migration = api_load_emblem_bundle_from_file_or_state(null);
$found = null;
foreach (($migration['assets'] ?? []) as $asset) {
  if (($asset['id'] ?? '') === $assetId) {
    $found = $asset;
    break;
  }
}
if (!is_array($found)) api_json_response(['error' => 'not_found'], 404, $mtime);

api_json_response(['item' => $found], 200, $mtime);
