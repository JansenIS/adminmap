<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

telegraph_require_feature();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
  orders_api_require_admin();
  $log = telegraph_load_relay_log_store();
  telegraph_response(['ok' => true, 'rows' => array_slice($log['rows'], -200)]);
}

orders_api_require_admin();
$payload = telegraph_request_payload();
$action = trim((string)($payload['action'] ?? 'relay_message'));
$limit = max(1, min(100, (int)($payload['limit'] ?? 20)));
$messageId = trim((string)($payload['id'] ?? ''));
if ($action === 'relay_message' && $messageId === '') telegraph_response(['error' => 'id_required'], 400);

$settings = telegraph_load_settings_store();
if (!(bool)($settings['relay_enabled'] ?? true)) telegraph_response(['ok'=>true,'processed'=>[],'channels'=>[],'relay_enabled'=>false]);
$enabledChannels = telegraph_collect_enabled_relay_channels();
$store = telegraph_load_messages_store();
$res = telegraph_process_relay_queue(
  $store,
  $enabledChannels,
  $limit,
  $action === 'relay_message' ? $messageId : null
);
telegraph_save_messages_store($res['store']);
telegraph_response(['ok' => true, 'processed' => $res['processed'], 'channels' => $enabledChannels]);
