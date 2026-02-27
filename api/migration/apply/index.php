<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_state_mtime());
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : [];
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());
$valid = api_validate_migration_apply_payload($payload);
if (!$valid['ok']) api_json_response(['error' => $valid['error'], 'field' => $valid['field'] ?? null], 400, api_state_mtime());

$currentState = api_load_state();
$state = (isset($payload['state']) && is_array($payload['state'])) ? $payload['state'] : $currentState;
$replace = !empty($payload['replace_map_state']);
$includeLegacy = !empty($payload['include_legacy_svg']);
if ($replace) {
  $ifMatch = api_check_if_match($currentState, $payload, true);
  if (!$ifMatch['ok']) {
    $status = (($ifMatch['error'] ?? '') === 'if_match_required') ? 428 : 412;
    api_json_response(['error' => ($ifMatch['error'] ?? 'version_conflict'), 'expected_version' => $ifMatch['expected'], 'provided_if_match' => $ifMatch['provided']], $status, api_state_mtime());
  }
}

$bundle = api_build_migrated_bundle($state, $includeLegacy);
$write = api_write_migrated_bundle($bundle, $replace);
if (!$write['ok']) api_json_response(['error' => 'write_failed', 'paths' => $write['paths']], 500, api_state_mtime());

api_json_response([
  'ok' => true,
  'stats' => $bundle['stats'],
  'paths' => $write['paths'],
], 200, api_state_mtime());
