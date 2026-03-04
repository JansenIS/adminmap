<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_state_mtime());
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : [];
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());
$valid = api_validate_split_free_cities_payload($payload);
if (!$valid['ok']) api_json_response(['error' => $valid['error'], 'field' => $valid['field'] ?? null, 'required' => $valid['required'] ?? null], 400, api_state_mtime());

$dryRun = !empty($payload['dry_run']);
$currentState = api_load_state();
if (!$dryRun) {
  $ifMatch = api_check_if_match($currentState, $payload, true);
  if (!$ifMatch['ok']) {
    $status = (($ifMatch['error'] ?? '') === 'if_match_required') ? 428 : 412;
    api_json_response(['error' => ($ifMatch['error'] ?? 'version_conflict'), 'expected_version' => $ifMatch['expected'], 'provided_if_match' => $ifMatch['provided']], $status, api_state_mtime());
  }
}

$res = api_split_free_cities_state($currentState, (array)$payload['ids']);
if (!$dryRun) {
  $next = $res['state'] ?? null;
  if (!is_array($next) || !api_save_state($next)) {
    api_json_response(['error' => 'write_failed'], 500, api_state_mtime());
  }
}

unset($res['state']);
$res['dry_run'] = $dryRun;
api_json_response($res, 200, api_state_mtime());
