<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/player_admin_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET']], 405, api_state_mtime());
}

$token = player_admin_token_from_request();
if ($token === '') api_json_response(['error' => 'token_required'], 400, api_state_mtime());

$state = api_load_state();
$session = player_admin_resolve_session($state, $token);
if (!$session) api_json_response(['error' => 'invalid_or_expired_token'], 403, api_state_mtime());

api_json_response(['ok' => true, 'session' => $session], 200, api_state_mtime());
