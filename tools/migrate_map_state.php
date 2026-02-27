#!/usr/bin/env php
<?php

declare(strict_types=1);
require_once __DIR__ . '/../api/lib/state_api.php';

$replace = in_array('--replace-map-state', $argv, true);
$includeLegacy = in_array('--keep-legacy-svg', $argv, true);

$state = api_load_state();
$bundle = api_build_migrated_bundle($state, $includeLegacy);
$write = api_write_migrated_bundle($bundle, $replace);

if (!$write['ok']) {
  fwrite(STDERR, "Migration write failed\n");
  exit(1);
}

fwrite(STDOUT, "Migration completed\n");
fwrite(STDOUT, 'assets_total=' . ($bundle['stats']['assets_total'] ?? 0) . "\n");
fwrite(STDOUT, 'refs_total=' . ($bundle['stats']['refs_total'] ?? 0) . "\n");
fwrite(STDOUT, 'stripped_legacy_svg_total=' . ($bundle['stats']['stripped_legacy_svg_total'] ?? 0) . "\n");
fwrite(STDOUT, 'map_replaced=' . (($write['paths']['map_replaced'] ?? false) ? '1' : '0') . "\n");
