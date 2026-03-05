<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/vk_bot_api.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
  api_json_response(['ok' => true, 'config' => vk_bot_load_config()], 200, api_state_mtime());
}
if ($method !== 'POST') api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET', 'POST']], 405, api_state_mtime());

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());
$cfg = array_merge(vk_bot_load_config(), $payload);
if (!vk_bot_save_config($cfg)) api_json_response(['error' => 'write_failed'], 500, api_state_mtime());
api_json_response(['ok' => true, 'config' => vk_bot_load_config()], 200, api_state_mtime());
