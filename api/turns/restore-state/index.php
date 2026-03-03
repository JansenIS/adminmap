<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/turn_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  turn_api_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405);
}

$payload = turn_api_request_payload();
$year = (int)($payload['turn_year'] ?? 0);
if ($year <= 0) {
  turn_api_response(['error' => 'invalid_payload', 'required' => ['turn_year:int>0', 'if_match:string(header or body)']], 400);
}

$turn = turn_api_load_turn($year);
if (!is_array($turn)) {
  turn_api_response(['error' => 'turn_not_found', 'year' => $year], 404);
}
if ((string)($turn['status'] ?? '') !== 'published') {
  turn_api_response(['error' => 'turn_not_published', 'year' => $year, 'status' => $turn['status'] ?? null], 409);
}

$currentState = api_load_state();
$ifMatch = api_check_if_match($currentState, $payload, true);
if (!$ifMatch['ok']) {
  $status = (($ifMatch['error'] ?? '') === 'if_match_required') ? 428 : 412;
  turn_api_response(['error' => ($ifMatch['error'] ?? 'version_conflict'), 'expected_version' => $ifMatch['expected'], 'provided_if_match' => $ifMatch['provided']], $status);
}

$snap = turn_api_load_snapshot($year, 'end');
$world = is_array($snap) ? ($snap['payload']['world_state'] ?? null) : null;
if (!is_array($world)) {
  turn_api_response(['error' => 'snapshot_not_found', 'phase' => 'end', 'year' => $year], 500);
}

if (!api_atomic_write_json(api_state_path(), $world)) {
  turn_api_response(['error' => 'write_failed'], 500);
}

turn_api_response([
  'ok' => true,
  'restored_turn_year' => $year,
  'state_version' => api_state_version_hash($world),
], 200);
