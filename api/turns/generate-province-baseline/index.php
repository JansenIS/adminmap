<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/turn_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  turn_api_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405);
}

$state = api_load_state();
$result = turn_api_generate_start_population_and_treasury($state);
if (!api_atomic_write_json(api_state_path(), $state)) {
  turn_api_response(['error' => 'write_failed'], 500);
}

turn_api_response([
  'ok' => true,
  'updated' => $result,
  'state_version' => api_state_version_hash($state),
], 200);
