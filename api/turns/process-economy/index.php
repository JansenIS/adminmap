<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/turn_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  turn_api_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405);
}

$payload = turn_api_request_payload();
$year = (int)($payload['turn_year'] ?? 0);
if ($year <= 0) {
  turn_api_response(['error' => 'invalid_payload', 'required' => ['turn_year:int>0']], 400);
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

$turn['status'] = 'processing';
$computed = turn_api_compute_economy_for_turn($turn);
$turn['economy'] = $computed['economy'];
$turn['entity_state'] = [
  'status' => 'processed',
  'records' => count($computed['entity_state']),
  'checksum' => hash('sha256', json_encode($computed['entity_state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
];
$turn['economy_state'] = [
  'status' => 'processed',
  'records' => count($computed['economy_state']),
  'checksum' => hash('sha256', json_encode($computed['economy_state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
];

$turn['entity_treasury'] = [
  'status' => 'processed',
  'records' => (int)($computed['treasury_summary']['entity_records'] ?? 0),
  'checksum' => (string)($computed['treasury_summary']['entity_treasury_checksum'] ?? ''),
];
$turn['province_treasury'] = [
  'status' => 'processed',
  'records' => (int)($computed['treasury_summary']['province_records'] ?? 0),
  'checksum' => (string)($computed['treasury_summary']['province_treasury_checksum'] ?? ''),
];
$turn['treasury_ledger'] = [
  'status' => 'processed',
  'records' => (int)($computed['treasury_summary']['ledger_records'] ?? 0),
  'checksum' => (string)($computed['treasury_summary']['ledger_checksum'] ?? ''),
];
$turn['events'][] = [
  'category' => 'economy',
  'event_type' => 'economy_processed',
  'payload' => ['turn_year' => $year, 'records' => $turn['economy']['records'] ?? 0],
  'occurred_at' => gmdate('c'),
];

if (!turn_api_save_turn($turn)) {
  turn_api_response(['error' => 'write_failed'], 500);
}

$saved = turn_api_load_turn($year) ?? $turn;
turn_api_response([
  'result' => [
    'turn_year' => $year,
    'status' => $saved['status'] ?? 'processing',
    'economy_checkpoint' => $saved['economy']['checkpoint'] ?? null,
  ],
  'turn' => [
    'year' => $year,
    'status' => $saved['status'] ?? 'processing',
    'version' => $saved['version'] ?? turn_api_turn_version($saved),
  ],
  'entity_state' => $saved['entity_state'] ?? null,
  'economy_state' => $saved['economy_state'] ?? null,
  'entity_treasury' => $saved['entity_treasury'] ?? null,
  'province_treasury' => $saved['province_treasury'] ?? null,
  'treasury_ledger' => $saved['treasury_ledger'] ?? null,
], 200);
