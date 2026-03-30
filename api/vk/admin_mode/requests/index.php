<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/lib/vk_bot_api.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
  $store = vk_bot_load_admin_mode_store();
  $rows = is_array($store['requests'] ?? null) ? $store['requests'] : [];
  usort($rows, static fn($a, $b) => ((int)($b['created_at'] ?? 0)) <=> ((int)($a['created_at'] ?? 0)));
  api_json_response(['ok' => true, 'items' => $rows], 200, vk_bot_data_mtime());
}

if ($method !== 'POST') api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET', 'POST']], 405, vk_bot_data_mtime());
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, vk_bot_data_mtime());
$id = trim((string)($payload['id'] ?? ''));
$action = trim((string)($payload['action'] ?? ''));
$admin = trim((string)($payload['admin'] ?? 'site_admin'));
if ($id === '' || !in_array($action, ['approve', 'reject'], true)) api_json_response(['error' => 'invalid_payload'], 400, vk_bot_data_mtime());
$status = $action === 'approve' ? 'approved' : 'rejected';
if (!vk_bot_admin_mode_set_request_status($id, $status, $admin)) api_json_response(['error' => 'request_not_found'], 404, vk_bot_data_mtime());
api_json_response(['ok' => true, 'id' => $id, 'status' => $status], 200, vk_bot_data_mtime());
