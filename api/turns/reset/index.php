<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/turn_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  turn_api_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405);
}

$index = turn_api_load_index();
$removedTurns = 0;
$removedSnapshots = 0;
$removedOverlays = 0;

foreach (($index['turns'] ?? []) as $year) {
  $year = (int)$year;
  if ($year <= 0) continue;
  $turnPath = turn_api_turn_path($year);
  if (is_file($turnPath) && @unlink($turnPath)) $removedTurns++;

  foreach (['start', 'end'] as $phase) {
    $snapPath = turn_api_snapshot_path($year, $phase);
    if (is_file($snapPath) && @unlink($snapPath)) $removedSnapshots++;
  }

  $overlayPath = turn_api_base_dir() . '/overlays/turn_' . $year . '_economy_overlay.json';
  if (is_file($overlayPath) && @unlink($overlayPath)) $removedOverlays++;
}

$index['turns'] = [0];
if (!turn_api_save_index($index)) {
  turn_api_response(['error' => 'write_failed'], 500);
}

$mapState = api_load_state();
api_sync_army_registry($mapState, 0, true);
if (!api_atomic_write_json(api_state_path(), $mapState)) {
  turn_api_response(['error' => 'state_write_failed'], 500);
}

turn_api_response([
  'ok' => true,
  'current_turn_year' => 0,
  'removed' => [
    'turn_files' => $removedTurns,
    'snapshots' => $removedSnapshots,
    'overlays' => $removedOverlays,
  ],
], 200);
