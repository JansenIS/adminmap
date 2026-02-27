<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

if (!in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET','POST'], true)) {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET','POST']], 405, api_state_mtime());
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : null;
if ($payload !== null && !is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());
if (is_array($payload)) {
  $valid = api_validate_migration_export_payload($payload);
  if (!$valid['ok']) api_json_response(['error' => $valid['error'], 'field' => $valid['field'] ?? null], 400, api_state_mtime());
}

$state = null;
$includeLegacy = false;

if (is_array($payload) && isset($payload['state']) && is_array($payload['state'])) {
  $state = $payload['state'];
  $includeLegacy = !empty($payload['include_legacy_svg']);
} else {
  $state = api_load_state();
  $includeLegacy = (($_GET['include_legacy_svg'] ?? '0') === '1');
}

$bundle = api_build_migrated_bundle($state, $includeLegacy);
$bundle['source'] = is_array($payload) ? 'request_state' : 'server_state';
$bundle['generated_at'] = gmdate('c');

$mtime = api_state_mtime();
api_json_response($bundle, 200, $mtime);
