<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/state_api.php';
require_once dirname(__DIR__) . '/lib/orders_api.php';
require_once dirname(__DIR__) . '/lib/player_admin_api.php';
require_once dirname(__DIR__) . '/lib/vk_bot_api.php';
require_once dirname(__DIR__) . '/telegraph/bootstrap.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/helpers.php';

if (!defined('DIPLOMACY_V1_ENABLED')) define('DIPLOMACY_V1_ENABLED', true);

function diplomacy_require_feature(): void {
  if (!DIPLOMACY_V1_ENABLED) diplomacy_response(['error' => 'diplomacy_disabled'], 404);
}

function diplomacy_request_payload(): array {
  $raw = file_get_contents('php://input');
  if (!is_string($raw) || trim($raw) === '') return [];
  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) diplomacy_response(['error' => 'invalid_json'], 400);
  return $decoded;
}

function diplomacy_response(array $payload, int $status = 200): void {
  api_json_response($payload, $status, diplomacy_store_mtime());
}
