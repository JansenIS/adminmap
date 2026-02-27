<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_state_mtime());
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : [];
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());
$valid = api_validate_jobs_rebuild_payload($payload);
if (!$valid['ok']) api_json_response(['error' => $valid['error'], 'field' => $valid['field'] ?? null], 400, api_state_mtime());

$mode = trim((string)($payload['mode'] ?? 'all'));
$allowedModes = ['all', 'provinces', 'kingdoms', 'great_houses', 'minor_houses', 'free_cities'];
if (!in_array($mode, $allowedModes, true)) {
  api_json_response(['error' => 'invalid_mode', 'allowed' => $allowedModes], 400, api_state_mtime());
}

$created = api_create_job('rebuild_layers', [
  'mode' => $mode,
  'requested_by' => 'api/jobs/rebuild-layers',
]);
if (!$created['ok']) api_json_response(['error' => (string)($created['error'] ?? 'create_failed')], 500, api_state_mtime());

api_json_response([
  'ok' => true,
  'job' => $created['job'],
], 202, api_state_mtime());
