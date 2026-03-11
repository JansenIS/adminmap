<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

telegraph_require_feature();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  orders_api_require_admin();
  $payload = telegraph_request_payload();
  $store = telegraph_load_settings_store();
  $store['auto_approve_web_public'] = (bool)($payload['auto_approve_web_public'] ?? ($store['auto_approve_web_public'] ?? false));
  $store['auto_approve_vk_public'] = (bool)($payload['auto_approve_vk_public'] ?? ($store['auto_approve_vk_public'] ?? false));
  $store['relay_enabled'] = (bool)($payload['relay_enabled'] ?? ($store['relay_enabled'] ?? true));
  $store['updated_at'] = gmdate('c');
  api_atomic_write_json(telegraph_settings_path(), $store);
}

telegraph_response(['ok' => true, 'settings' => telegraph_load_settings_store()]);
