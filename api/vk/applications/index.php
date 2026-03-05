<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/vk_bot_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET']], 405, vk_bot_data_mtime());
}
$rows = vk_bot_load_applications();
usort($rows, static fn($a, $b) => ((int)($b['created_at'] ?? 0)) <=> ((int)($a['created_at'] ?? 0)));
api_json_response(['ok' => true, 'items' => $rows], 200, vk_bot_data_mtime());
