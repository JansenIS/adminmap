<?php

declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/lib/player_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_state_mtime());
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());

$entityType = trim((string)($payload['entity_type'] ?? ''));
$entityId = trim((string)($payload['entity_id'] ?? ''));
if ($entityType === '' || $entityId === '') api_json_response(['error' => 'entity_ref_required'], 400, api_state_mtime());

$state = api_load_state();
if (!player_validate_entity_ref($state, $entityType, $entityId)) {
  api_json_response(['error' => 'entity_not_found', 'entity_type' => $entityType, 'entity_id' => $entityId], 404, api_state_mtime());
}

$tokens = player_prune_tokens(player_load_tokens());
$token = player_generate_token();
$now = time();
$tokens[$token] = [
  'entity_type' => $entityType,
  'entity_id' => $entityId,
  'created_at' => $now,
  'expires_at' => $now + player_token_ttl_seconds(),
];
if (!player_save_tokens($tokens)) api_json_response(['error' => 'write_failed'], 500, api_state_mtime());

$host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$path = '/player.html?token=' . rawurlencode($token);
$link = ($host !== '') ? ($scheme . '://' . $host . $path) : $path;

api_json_response([
  'ok' => true,
  'token' => $token,
  'expires_in_seconds' => player_token_ttl_seconds(),
  'expires_at' => $now + player_token_ttl_seconds(),
  'url' => $link,
], 200, api_state_mtime());
