<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : null;

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
