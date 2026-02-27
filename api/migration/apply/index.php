<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : [];
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());

$state = (isset($payload['state']) && is_array($payload['state'])) ? $payload['state'] : api_load_state();
$replace = !empty($payload['replace_map_state']);
$includeLegacy = !empty($payload['include_legacy_svg']);

$bundle = api_build_migrated_bundle($state, $includeLegacy);
$write = api_write_migrated_bundle($bundle, $replace);
if (!$write['ok']) api_json_response(['error' => 'write_failed', 'paths' => $write['paths']], 500, api_state_mtime());

api_json_response([
  'ok' => true,
  'stats' => $bundle['stats'],
  'paths' => $write['paths'],
], 200, api_state_mtime());
