<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';
require_once dirname(__DIR__, 2) . '/lib/orders_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_state_mtime());
}

$kind = trim((string)($_GET['kind'] ?? ''));
if ($kind === 'orders_outbox') {
  $res = orders_api_process_outbox();
  api_json_response(['ok' => true, 'orders_outbox' => $res], 200, max(api_state_mtime(), api_file_mtime(orders_api_outbox_path())));
}

$state = api_load_state();
$res = api_run_next_job($state);
if (!$res['ok']) api_json_response(['error' => (string)($res['error'] ?? 'run_failed')], 500, api_state_mtime());

$orders = orders_api_process_outbox();
api_json_response([
  'ok' => true,
  'processed' => (bool)($res['processed'] ?? false),
  'job' => $res['job'] ?? null,
  'orders_outbox' => $orders,
], 200, max(api_state_mtime(), api_file_mtime(orders_api_outbox_path())));
