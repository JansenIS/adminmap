<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') api_json_response(['error' => 'invalid_id'], 400, api_state_mtime());

$job = api_find_job($id);
if (!is_array($job)) api_json_response(['error' => 'not_found'], 404, api_state_mtime());

api_json_response([
  'ok' => true,
  'job' => $job,
], 200, api_state_mtime());
