<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/vk_bot_api.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
  $rows = vk_bot_load_image_usage();
  $items = [];
  foreach ($rows as $uid => $row) {
    if (!is_array($row)) continue;
    $items[] = [
      'vk_user_id' => (int)$uid,
      'count' => (int)($row['count'] ?? 0),
      'updated_at' => (int)($row['updated_at'] ?? 0),
    ];
  }
  usort($items, static fn(array $a, array $b): int => $b['updated_at'] <=> $a['updated_at']);

  $generationLogRows = vk_bot_load_image_generations_log();
  $generationLog = [];
  foreach ($generationLogRows as $row) {
    if (!is_array($row)) continue;
    $generationLog[] = [
      'ts' => (int)($row['ts'] ?? 0),
      'vk_user_id' => (int)($row['vk_user_id'] ?? 0),
      'prompt' => trim((string)($row['prompt'] ?? '')),
      'ok' => (bool)($row['ok'] ?? false),
      'error' => trim((string)($row['error'] ?? '')),
      'http_code' => (int)($row['http_code'] ?? 0),
      'router_response' => trim((string)($row['router_response'] ?? '')),
    ];
  }
  usort($generationLog, static fn(array $a, array $b): int => $b['ts'] <=> $a['ts']);
  $generationLog = array_slice($generationLog, 0, 10);

  api_json_response([
    'ok' => true,
    'limit' => vk_bot_image_user_limit(),
    'items' => $items,
    'generation_log' => $generationLog,
  ], 200, vk_bot_data_mtime());
}

if ($method !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET', 'POST']], 405, vk_bot_data_mtime());
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, vk_bot_data_mtime());
$action = trim((string)($payload['action'] ?? ''));
$rows = vk_bot_load_image_usage();

if ($action === 'reset_all') {
  $rows = [];
  if (!vk_bot_save_image_usage($rows)) api_json_response(['error' => 'write_failed'], 500, vk_bot_data_mtime());
  api_json_response(['ok' => true], 200, vk_bot_data_mtime());
}

if ($action === 'reset_user') {
  $uid = (int)($payload['vk_user_id'] ?? 0);
  if ($uid <= 0) api_json_response(['error' => 'invalid_vk_user_id'], 400, vk_bot_data_mtime());
  unset($rows[(string)$uid]);
  if (!vk_bot_save_image_usage($rows)) api_json_response(['error' => 'write_failed'], 500, vk_bot_data_mtime());
  api_json_response(['ok' => true], 200, vk_bot_data_mtime());
}

api_json_response(['error' => 'unknown_action'], 400, vk_bot_data_mtime());
