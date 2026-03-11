<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

telegraph_require_feature();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  orders_api_require_admin();
  $payload = telegraph_request_payload();
  $rows = is_array($payload['channels'] ?? null) ? $payload['channels'] : [];
  $norm = [];
  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $chatId = (int)($row['chat_id'] ?? 0);
    if ($chatId === 0) continue;
    $norm[] = [
      'chat_id' => $chatId,
      'title' => mb_substr(trim((string)($row['title'] ?? '')), 0, 120),
      'enabled' => (bool)($row['enabled'] ?? true),
      'accept_tg_input' => (bool)($row['accept_tg_input'] ?? false),
      'relay_public' => (bool)($row['relay_public'] ?? false),
      'relay_system' => (bool)($row['relay_system'] ?? false),
    ];
  }
  telegraph_save_channels_store(['schema_version' => 1, 'channels' => $norm]);
}
$store = telegraph_load_channels_store();
telegraph_response(['ok' => true, 'channels' => $store['channels']]);
