<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/state_api.php';

$state = api_load_state();
$mtime = api_state_mtime();

$z = isset($_GET['z']) ? (int)$_GET['z'] : 0;
$x = isset($_GET['x']) ? (int)$_GET['x'] : 0;
$y = isset($_GET['y']) ? (int)$_GET['y'] : 0;
$mode = trim((string)($_GET['mode'] ?? 'provinces'));
$allowedModes = ['provinces', 'kingdoms', 'great_houses', 'free_cities'];
if (!in_array($mode, $allowedModes, true)) {
  api_json_response(['error' => 'invalid_mode', 'allowed' => $allowedModes], 400, $mtime);
}

if ($z !== 0 || $x !== 0 || $y !== 0) {
  api_json_response([
    'error' => 'tile_not_ready',
    'message' => 'Tiles are currently supported only for z=0,x=0,y=0 in transitional mode.',
  ], 501, $mtime);
}

$payload = api_build_layer_payload($state, $mode);
api_json_response([
  'z' => 0,
  'x' => 0,
  'y' => 0,
  'mode' => $mode,
  'version' => $payload['version'],
  'tile_kind' => 'json-province-rgba',
  'items' => $payload['items'],
  'total' => $payload['total'],
], 200, $mtime);
