<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 50;

$jobsPayload = api_load_jobs();
$jobs = array_values(array_filter(($jobsPayload['jobs'] ?? []), static fn($j) => is_array($j)));
$total = count($jobs);
$items = array_slice($jobs, $offset, $limit);

api_json_response([
  'offset' => $offset,
  'limit' => $limit,
  'total' => $total,
  'items' => $items,
], 200, api_state_mtime());
