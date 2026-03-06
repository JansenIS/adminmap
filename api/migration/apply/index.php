<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_state_mtime());
}

$raw = file_get_contents('php://input');
$encoding = strtolower(trim((string)($_SERVER['HTTP_CONTENT_ENCODING'] ?? '')));
if ($raw !== false && $raw !== '' && in_array($encoding, ['gzip', 'x-gzip'], true)) {
  $decoded = @gzdecode($raw);
  if ($decoded === false) {
    api_json_response(['error' => 'invalid_gzip_body'], 400, api_state_mtime());
  }
  $raw = $decoded;
}
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : [];
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());
$valid = api_validate_migration_apply_payload($payload);
if (!$valid['ok']) api_json_response(['error' => $valid['error'], 'field' => $valid['field'] ?? null], 400, api_state_mtime());

$currentState = api_load_state();
$state = (isset($payload['state']) && is_array($payload['state'])) ? api_normalize_state_snapshot_for_backend($payload['state']) : $currentState;
$replace = !empty($payload['replace_map_state']);
$includeLegacy = !empty($payload['include_legacy_svg']);
if ($replace) {
  $ifMatch = api_check_if_match($currentState, $payload, true);
  if (!$ifMatch['ok']) {
    $status = (($ifMatch['error'] ?? '') === 'if_match_required') ? 428 : 412;
    api_json_response(['error' => ($ifMatch['error'] ?? 'version_conflict'), 'expected_version' => $ifMatch['expected'], 'provided_if_match' => $ifMatch['provided']], $status, api_state_mtime());
  }
}

if ($replace) {
  $wrapped = api_with_state_write_lock(static function () use ($payload, $includeLegacy): array {
    $lockedCurrentState = api_load_state();
    $ifMatchLocked = api_check_if_match($lockedCurrentState, $payload, true);
    if (!$ifMatchLocked['ok']) {
      $status = (($ifMatchLocked['error'] ?? '') === 'if_match_required') ? 428 : 412;
      return ['ok' => false, 'error' => ($ifMatchLocked['error'] ?? 'version_conflict'), 'status' => $status, 'expected_version' => $ifMatchLocked['expected'], 'provided_if_match' => $ifMatchLocked['provided']];
    }

    $nextState = (isset($payload['state']) && is_array($payload['state']))
      ? api_normalize_state_snapshot_for_backend($payload['state'])
      : $lockedCurrentState;
    $bundleLocked = api_build_migrated_bundle($nextState, $includeLegacy);
    $writeLocked = api_write_migrated_bundle($bundleLocked, true);
    if (!$writeLocked['ok']) return ['ok' => false, 'error' => 'write_failed', 'status' => 500, 'paths' => $writeLocked['paths']];
    return ['ok' => true, 'bundle' => $bundleLocked, 'write' => $writeLocked];
  });

  if (!$wrapped['ok']) {
    api_json_response([
      'error' => $wrapped['error'] ?? 'write_failed',
      'expected_version' => $wrapped['expected_version'] ?? null,
      'provided_if_match' => $wrapped['provided_if_match'] ?? null,
      'paths' => $wrapped['paths'] ?? null,
    ], (int)($wrapped['status'] ?? 500), api_state_mtime());
  }

  $bundle = $wrapped['bundle'];
  $write = $wrapped['write'];
} else {
  $bundle = api_build_migrated_bundle($state, $includeLegacy);
  $write = api_write_migrated_bundle($bundle, false);
  if (!$write['ok']) api_json_response(['error' => 'write_failed', 'paths' => $write['paths']], 500, api_state_mtime());
}

api_json_response([
  'ok' => true,
  'stats' => $bundle['stats'],
  'paths' => $write['paths'],
], 200, api_state_mtime());
