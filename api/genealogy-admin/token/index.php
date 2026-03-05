<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/vk_bot_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET']], 405, vk_bot_data_mtime());
}

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') api_json_response(['ok' => false, 'error' => 'token_required'], 400, vk_bot_data_mtime());
$row = vk_bot_resolve_genealogy_admin_token($token);
if (!is_array($row)) api_json_response(['ok' => false, 'error' => 'invalid_or_expired_token'], 403, vk_bot_data_mtime());
api_json_response(['ok' => true, 'access' => $row], 200, vk_bot_data_mtime());
