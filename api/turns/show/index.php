<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/turn_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  turn_api_response(['error' => 'method_not_allowed', 'allowed' => ['GET']], 405);
}

$year = (int)($_GET['year'] ?? 0);
if ($year <= 0) {
  turn_api_response(['error' => 'invalid_query', 'required' => ['year:int>0']], 400);
}

$turn = turn_api_load_turn($year);
if (!is_array($turn)) {
  turn_api_response(['error' => 'turn_not_found', 'year' => $year], 404);
}

$publishedOnly = (string)($_GET['published_only'] ?? '1') !== '0';
if ($publishedOnly && (string)($turn['status'] ?? '') !== 'published') {
  turn_api_response(['error' => 'turn_not_published', 'year' => $year, 'status' => $turn['status'] ?? null], 409);
}

$include = trim((string)($_GET['include'] ?? ''));
$includeParts = $include === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $include))));
$includeSet = array_fill_keys($includeParts, true);
$full = (string)($_GET['full'] ?? '0') === '1';

$response = [
  'turn' => [
    'id' => $turn['id'] ?? ('turn-' . $year),
    'year' => $year,
    'status' => (string)($turn['status'] ?? ''),
    'ruleset_version' => (string)($turn['ruleset_version'] ?? ''),
    'version' => (string)($turn['version'] ?? turn_api_turn_version($turn)),
  ],
  'entity_state' => $turn['entity_state'] ?? null,
  'economy_state' => $turn['economy_state'] ?? null,
  'entity_treasury' => $turn['entity_treasury'] ?? null,
  'province_treasury' => $turn['province_treasury'] ?? null,
  'treasury_ledger' => $turn['treasury_ledger'] ?? null,
];

if (isset($includeSet['state'])) {
  $response['state'] = [
    'snapshot_start' => $turn['snapshot_start'] ?? null,
    'snapshot_end' => $turn['snapshot_end'] ?? null,
  ];
}
if (isset($includeSet['map_artifacts'])) {
  $response['map_artifacts'] = $turn['map_artifacts'] ?? [];
}
if (isset($includeSet['economy'])) {
  $response['economy'] = $turn['economy'] ?? null;
}
if (isset($includeSet['events'])) {
  $response['events'] = $turn['events'] ?? [];
}
if (isset($includeSet['treasury'])) {
  $response['treasury'] = [
    'entity_treasury' => $turn['entity_treasury'] ?? null,
    'province_treasury' => $turn['province_treasury'] ?? null,
    'treasury_ledger' => $turn['treasury_ledger'] ?? null,
  ];
}
if (isset($includeSet['snapshot_payload'])) {
  $phase = ((string)($_GET['phase'] ?? 'end') === 'start') ? 'start' : 'end';
  $snapshot = turn_api_load_snapshot($year, $phase);
  if (!is_array($snapshot)) {
    $response['snapshot_payload'] = null;
  } else {
    $payload = $snapshot['payload'] ?? null;
    if (!$full && is_array($payload)) {
      if (is_array($payload['entity_state'] ?? null)) {
        $payload['entity_state'] = turn_api_compact_records($payload['entity_state']);
      }
      if (is_array($payload['economy_state'] ?? null)) {
        $payload['economy_state'] = turn_api_compact_records($payload['economy_state']);
      }
      if (is_array($payload['entity_treasury'] ?? null)) {
        $payload['entity_treasury'] = turn_api_compact_records($payload['entity_treasury']);
      }
      if (is_array($payload['province_treasury'] ?? null)) {
        $payload['province_treasury'] = turn_api_compact_records($payload['province_treasury']);
      }
      if (is_array($payload['treasury_ledger'] ?? null)) {
        $payload['treasury_ledger'] = turn_api_compact_records($payload['treasury_ledger']);
      }
    }
    $response['snapshot_payload'] = $payload;
  }
}

turn_api_response($response, 200);
