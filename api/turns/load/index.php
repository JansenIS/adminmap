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
if ((string)($turn['status'] ?? '') !== 'published') {
  turn_api_response(['error' => 'turn_not_published', 'year' => $year, 'status' => $turn['status'] ?? null], 409);
}

$snap = turn_api_load_snapshot($year, 'end');
if (!is_array($snap) || !is_array($snap['payload'] ?? null)) {
  turn_api_response(['error' => 'snapshot_not_found', 'phase' => 'end', 'year' => $year], 500);
}

turn_api_response([
  'turn' => ['year' => $year, 'status' => 'published', 'version' => (string)($turn['version'] ?? turn_api_turn_version($turn))],
  'world_snapshot' => [
    'phase' => 'end',
    'checksum' => (string)($snap['checksum'] ?? ''),
    'payload' => $snap['payload'],
  ],
  'map_artifacts' => $turn['map_artifacts'] ?? [],
], 200);
