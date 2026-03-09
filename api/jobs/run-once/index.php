<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_state_mtime());
}

$state = api_load_state();
$res = api_run_next_job($state);
if (!$res['ok']) api_json_response(['error' => (string)($res['error'] ?? 'run_failed')], 500, api_state_mtime());

api_json_response([
  'ok' => true,
  'processed' => (bool)($res['processed'] ?? false),
  'job' => $res['job'] ?? null,
], 200, api_state_mtime());
