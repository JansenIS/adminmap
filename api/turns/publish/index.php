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

$currentStatus = (string)($turn['status'] ?? '');
if ($currentStatus === 'rolled_back') {
  turn_api_response(['error' => 'turn_is_rolled_back', 'year' => $year], 409);
}

$econ = $turn['economy'] ?? [];
if (($econ['status'] ?? '') !== 'processed') {
  turn_api_response(['error' => 'economy_not_processed', 'required_checkpoint' => 'checkpoint:economy_applied'], 409);
}
$treasuryLedger = $turn['treasury_ledger'] ?? [];
if (($treasuryLedger['status'] ?? '') !== 'processed') {
  turn_api_response(['error' => 'treasury_not_processed', 'required_checkpoint' => 'checkpoint:economy_applied'], 409);
}

$startSnap = turn_api_load_snapshot($year, 'start');
if (!is_array($startSnap) || !is_array($startSnap['payload']['world_state'] ?? null)) {
  turn_api_response(['error' => 'snapshot_not_found', 'phase' => 'start'], 500);
}

$worldState = $startSnap['payload']['world_state'];
$ruleset = turn_api_ruleset_for_turn($turn);
$entityState = turn_api_compute_entity_state($worldState, $year, $ruleset);
$economyState = turn_api_compute_economy_state($worldState, $year, $ruleset);
$treasury = turn_api_compute_treasury($worldState, $entityState, $economyState, $year, $ruleset, turn_api_previous_treasury_maps($turn));
$turn['map_artifacts'] = turn_api_build_map_artifacts();
$overlayPayload = turn_api_compute_overlay_payload($entityState, $economyState, (array)($turn['economy'] ?? []));
$overlayArtifact = turn_api_write_overlay_artifact($year, $overlayPayload);
if (is_array($overlayArtifact)) {
  $turn['map_artifacts'][] = $overlayArtifact;
}

$endRef = turn_api_save_snapshot($year, 'end', [
  'world_state' => $worldState,
  'entity_state' => $entityState,
  'economy_state' => $economyState,
  'entity_treasury' => $treasury['entity_treasury_rows'],
  'province_treasury' => $treasury['province_treasury_rows'],
  'treasury_ledger' => $treasury['ledger_rows'],
  'map_artifacts' => $turn['map_artifacts'],
  'economy_summary' => $turn['economy'] ?? null,
]);
if (!is_array($endRef)) {
  turn_api_response(['error' => 'snapshot_write_failed', 'phase' => 'end'], 500);
}

$turn['snapshot_end'] = $endRef;
$turn['entity_state'] = [
  'status' => 'published',
  'records' => count($entityState),
  'checksum' => hash('sha256', json_encode($entityState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
];
$turn['economy_state'] = [
  'status' => 'published',
  'records' => count($economyState),
  'checksum' => hash('sha256', json_encode($economyState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
];
$turn['entity_treasury'] = [
  'status' => 'published',
  'records' => (int)($treasury['summary']['entity_records'] ?? 0),
  'checksum' => (string)($treasury['summary']['entity_treasury_checksum'] ?? ''),
];
$turn['province_treasury'] = [
  'status' => 'published',
  'records' => (int)($treasury['summary']['province_records'] ?? 0),
  'checksum' => (string)($treasury['summary']['province_treasury_checksum'] ?? ''),
];
$turn['treasury_ledger'] = [
  'status' => 'published',
  'records' => (int)($treasury['summary']['ledger_records'] ?? 0),
  'checksum' => (string)($treasury['summary']['ledger_checksum'] ?? ''),
];
$turn['status'] = 'published';
$turn['published_at'] = gmdate('c');
$turn['events'][] = [
  'category' => 'system',
  'event_type' => 'turn_published',
  'payload' => ['turn_year' => $year],
  'occurred_at' => gmdate('c'),
];

if (!turn_api_save_turn($turn)) {
  turn_api_response(['error' => 'write_failed'], 500);
}

$saved = turn_api_load_turn($year) ?? $turn;
turn_api_response([
  'turn' => [
    'year' => $year,
    'status' => $saved['status'] ?? 'published',
    'version' => $saved['version'] ?? turn_api_turn_version($saved),
  ],
  'snapshot' => $saved['snapshot_end'] ?? null,
  'entity_state' => $saved['entity_state'] ?? null,
  'economy_state' => $saved['economy_state'] ?? null,
  'entity_treasury' => $saved['entity_treasury'] ?? null,
  'province_treasury' => $saved['province_treasury'] ?? null,
  'treasury_ledger' => $saved['treasury_ledger'] ?? null,
], 200);
