<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/lib/vk_bot_api.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') {
  $raw = (string)file_get_contents('php://input');
  $payload = json_decode($raw, true);
  if (is_array($payload)) $code = trim((string)($payload['code'] ?? ''));
}
if (!preg_match('/^\d{6}$/', $code)) api_json_response(['error' => 'code_required'], 400, vk_bot_data_mtime());

if ($method === 'GET') {
  $store = vk_bot_load_admin_mode_store();
  $row = $store['pending_codes'][$code] ?? null;
  if (!is_array($row)) api_json_response(['ok' => false, 'status' => 'invalid_code'], 404, vk_bot_data_mtime());
  $exp = (int)($row['expires_at'] ?? 0);
  if ($exp > 0 && $exp < time()) api_json_response(['ok' => false, 'status' => 'expired'], 410, vk_bot_data_mtime());
  api_json_response(['ok' => true, 'status' => 'pending', 'vk_user_id' => (int)($row['vk_user_id'] ?? 0)], 200, vk_bot_data_mtime());
}

if ($method !== 'POST') api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET', 'POST']], 405, vk_bot_data_mtime());
$vkUserId = vk_bot_mark_admin_confirmed_by_code($code);
if ($vkUserId === null || $vkUserId <= 0) api_json_response(['ok' => false, 'error' => 'invalid_or_expired_code'], 400, vk_bot_data_mtime());
api_json_response(['ok' => true, 'confirmed' => true, 'vk_user_id' => $vkUserId], 200, vk_bot_data_mtime());
