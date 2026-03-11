<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/state_api.php';
require_once dirname(__DIR__) . '/lib/orders_api.php';
require_once dirname(__DIR__) . '/lib/player_admin_api.php';
require_once dirname(__DIR__) . '/lib/vk_bot_api.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/helpers.php';

if (!defined('TELEGRAPH_V1_ENABLED')) {
  define('TELEGRAPH_V1_ENABLED', true);
}

function telegraph_require_feature(): void {
  if (!TELEGRAPH_V1_ENABLED) {
    telegraph_response(['error' => 'telegraph_disabled'], 404);
  }
}

function telegraph_request_payload(): array {
  $raw = file_get_contents('php://input');
  if (!is_string($raw) || trim($raw) === '') return [];
  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) telegraph_response(['error' => 'invalid_json'], 400);
  return $decoded;
}

function telegraph_response(array $payload, int $status = 200): void {
  api_json_response($payload, $status, telegraph_store_mtime());
}
