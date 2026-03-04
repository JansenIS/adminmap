#!/usr/bin/env php
<?php

declare(strict_types=1);
require_once __DIR__ . '/../api/lib/state_api.php';

$idsArg = null;
$fromFile = null;
$dryRun = in_array('--dry-run', $argv, true);
$replace = in_array('--replace-map-state', $argv, true);

foreach ($argv as $arg) {
  if (str_starts_with($arg, '--ids=')) $idsArg = substr($arg, 6);
  if (str_starts_with($arg, '--from-file=')) $fromFile = substr($arg, 12);
}

if ($idsArg === null || trim($idsArg) === '') {
  fwrite(STDERR, "Usage: php tools/split_free_cities.php --ids=\"ID1,ID2\" [--dry-run] [--replace-map-state] [--from-file=/path/map_state.json]\n");
  exit(2);
}

$ids = array_values(array_unique(array_filter(array_map(
  static fn(string $v): string => trim($v),
  explode(',', (string)$idsArg)
), static fn(string $v): bool => $v !== '')));
if (!$ids) {
  fwrite(STDERR, "No valid ids in --ids\n");
  exit(2);
}

if ($fromFile !== null && $fromFile !== '') {
  $raw = @file_get_contents($fromFile);
  $state = is_string($raw) ? json_decode($raw, true) : null;
  if (!is_array($state)) {
    fwrite(STDERR, "Invalid --from-file JSON\n");
    exit(1);
  }
} else {
  $state = api_load_state();
}

if (!isset($state['free_cities']) || !is_array($state['free_cities'])) $state['free_cities'] = [];
if (!isset($state['special_territories']) || !is_array($state['special_territories'])) $state['special_territories'] = [];

$moved = [];
$missing = [];
foreach ($ids as $id) {
  if (!array_key_exists($id, $state['free_cities'])) {
    $missing[] = $id;
    continue;
  }
  $state['special_territories'][$id] = $state['free_cities'][$id];
  unset($state['free_cities'][$id]);
  $moved[] = $id;
}

$relinked = 0;
foreach (($state['provinces'] ?? []) as &$pd) {
  if (!is_array($pd)) continue;
  $fc = trim((string)($pd['free_city_id'] ?? ''));
  if ($fc === '' || !in_array($fc, $moved, true)) continue;
  $pd['special_territory_id'] = $fc;
  $pd['free_city_id'] = '';
  $relinked++;
}
unset($pd);

$out = [
  'ok' => true,
  'moved_total' => count($moved),
  'moved_ids' => $moved,
  'missing_total' => count($missing),
  'missing_ids' => $missing,
  'relinked_provinces' => $relinked,
  'dry_run' => $dryRun,
];

if (!$dryRun) {
  $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if (!is_string($encoded)) {
    fwrite(STDERR, "Failed to encode updated state\n");
    exit(1);
  }
  $encoded .= "\n";

  if ($replace && $fromFile === null) {
    api_save_state($state);
    $out['map_replaced'] = true;
  } else {
    $target = $fromFile ? ($fromFile . '.split.out.json') : (__DIR__ . '/../data/map_state.split.out.json');
    if (@file_put_contents($target, $encoded) === false) {
      fwrite(STDERR, "Failed to write output file: {$target}\n");
      exit(1);
    }
    $out['output_file'] = $target;
    $out['map_replaced'] = false;
  }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
