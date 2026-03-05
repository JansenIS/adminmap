<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/turn_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  turn_api_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405);
}

$payload = turn_api_request_payload();
$sourceYear = (int)($payload['source_turn_year'] ?? 0);
$targetYear = (int)($payload['target_turn_year'] ?? 0);
$rulesetVersion = trim((string)($payload['ruleset_version'] ?? 'v1.0'));
$preferMapState = filter_var($payload['prefer_map_state'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($sourceYear < 0 || $targetYear <= 0 || $targetYear <= $sourceYear) {
  turn_api_response(['error' => 'invalid_payload', 'required' => ['source_turn_year:int>=0', 'target_turn_year:int>source', 'ruleset_version:string']], 400);
}


if ($sourceYear > 0) {
  $source = turn_api_load_turn($sourceYear);
  if (!is_array($source)) turn_api_response(['error' => 'source_turn_not_found', 'source_turn_year' => $sourceYear], 404);
  if (($source['status'] ?? '') !== 'published') turn_api_response(['error' => 'source_turn_not_published', 'source_turn_year' => $sourceYear, 'status' => $source['status'] ?? null], 409);
}

$index = turn_api_load_index();
if (in_array($targetYear, array_map('intval', $index['turns']), true)) {
  turn_api_response(['error' => 'turn_already_exists', 'year' => $targetYear], 409);
}

$turn = turn_api_build_base_turn($sourceYear, $targetYear, $rulesetVersion, (bool)$preferMapState);
if (!turn_api_save_turn($turn)) {
  turn_api_response(['error' => 'write_failed'], 500);
}

$index['turns'][] = $targetYear;
$index['turns'] = array_values(array_unique(array_map('intval', $index['turns'])));
sort($index['turns'], SORT_NUMERIC);
if (!turn_api_save_index($index)) {
  turn_api_response(['error' => 'write_failed'], 500);
}

$mapState = api_load_state();
api_sync_army_registry($mapState, $targetYear, true);
if (!api_atomic_write_json(api_state_path(), $mapState)) {
  turn_api_response(['error' => 'state_write_failed'], 500);
}

$saved = turn_api_load_turn($targetYear) ?? $turn;
turn_api_response([
  'turn' => [
    'id' => $saved['id'] ?? ('turn-' . $targetYear),
    'year' => $targetYear,
    'status' => $saved['status'] ?? 'draft',
    'version' => $saved['version'] ?? turn_api_turn_version($saved),
  ],
], 200);
