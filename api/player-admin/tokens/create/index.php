<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/lib/player_admin_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_state_mtime());
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : null;
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());

$type = (string)($payload['entity_type'] ?? '');
$id = (string)($payload['entity_id'] ?? '');
if (!in_array($type, player_admin_allowed_entity_types(), true) || $id === '') {
  api_json_response(['error' => 'invalid_entity_ref'], 400, api_state_mtime());
}

$state = api_load_state();
if (!player_admin_validate_entity_ref($state, $type, $id)) {
  api_json_response(['error' => 'entity_not_found'], 404, api_state_mtime());
}

$tokens = player_admin_prune_tokens(player_admin_load_tokens());
$token = player_admin_generate_token();
$now = time();
$tokens[$token] = [
  'entity_type' => $type,
  'entity_id' => $id,
  'created_at' => $now,
  'expires_at' => $now + player_admin_token_ttl_seconds(),
];
if (!player_admin_save_tokens($tokens)) api_json_response(['error' => 'write_failed'], 500, api_state_mtime());

api_json_response([
  'ok' => true,
  'token' => $token,
  'path' => '/player_admin.html?token=' . rawurlencode($token),
  'expires_in_seconds' => player_admin_token_ttl_seconds(),
  'expires_at' => $now + player_admin_token_ttl_seconds(),
], 200, api_state_mtime());
