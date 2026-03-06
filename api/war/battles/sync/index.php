<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/lib/war_battle_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_state_mtime());
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : [];
if (!is_array($payload)) $payload = [];
$includeStaticConflicts = !empty($payload['include_static_conflicts']);

$state = api_load_state();
api_sync_army_registry($state, null, false);
$rows = war_battle_sync($state, $includeStaticConflicts);

api_json_response(['ok' => true, 'battles' => array_values($rows)], 200, max(api_state_mtime(), api_file_mtime(war_battles_path())));
