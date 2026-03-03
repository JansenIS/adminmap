<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/turn_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  turn_api_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405);
}

$payload = turn_api_request_payload();
$year = (int)($payload['turn_year'] ?? 0);
$reason = trim((string)($payload['reason'] ?? ''));
if ($year <= 0 || $reason === '') {
  turn_api_response(['error' => 'invalid_payload', 'required' => ['turn_year:int>0', 'reason:string']], 400);
}

$turn = turn_api_load_turn($year);
if (!is_array($turn)) {
  turn_api_response(['error' => 'turn_not_found', 'year' => $year], 404);
}

$ifMatch = turn_api_if_match($turn, $payload, true);
if (!$ifMatch['ok']) {
  $status = (($ifMatch['error'] ?? '') === 'if_match_required') ? 428 : 412;
  turn_api_response(['error' => $ifMatch['error'], 'expected_version' => $ifMatch['expected'], 'provided_if_match' => $ifMatch['provided']], $status);
}

$status = (string)($turn['status'] ?? '');
if ($status !== 'published') {
  turn_api_response(['error' => 'rollback_requires_published_turn', 'year' => $year, 'status' => $status], 409);
}
if (turn_api_has_published_successor($year)) {
  turn_api_response(['error' => 'rollback_blocked_by_published_successor', 'year' => $year], 409);
}

$turn['status'] = 'rolled_back';
$turn['rolled_back_at'] = gmdate('c');
$turn['rollback_reason'] = $reason;
$turn['events'][] = [
  'category' => 'system',
  'event_type' => 'turn_rolled_back',
  'payload' => ['turn_year' => $year, 'reason' => $reason],
  'occurred_at' => gmdate('c'),
];

if (!turn_api_save_turn($turn)) {
  turn_api_response(['error' => 'write_failed'], 500);
}

$saved = turn_api_load_turn($year) ?? $turn;
turn_api_response([
  'turn' => [
    'year' => $year,
    'status' => $saved['status'] ?? 'rolled_back',
    'version' => $saved['version'] ?? turn_api_turn_version($saved),
  ],
  'rollback' => ['reason' => $reason, 'at' => $saved['rolled_back_at'] ?? null],
], 200);
