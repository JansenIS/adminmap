<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

$state = api_load_state();
$mtime = api_state_mtime();
$profile = (string)($_GET['profile'] ?? 'full');
$type = (string)($_GET['type'] ?? '');
$id = trim((string)($_GET['id'] ?? ''));
$allowed = ['kingdoms', 'great_houses', 'minor_houses', 'free_cities'];
if (!in_array($type, $allowed, true)) {
  api_json_response(['error' => 'invalid_type', 'allowed' => $allowed], 400, $mtime);
}
if ($id === '') {
  api_json_response(['error' => 'invalid_id'], 400, $mtime);
}

$item = ($state[$type] ?? [])[$id] ?? null;
if (!is_array($item)) {
  api_json_response(['error' => 'not_found'], 404, $mtime);
}
if ($profile === 'compact') {
  unset($item['emblem_svg']);
}
$item['id'] = $id;

api_json_response([
  'type' => $type,
  'id' => $id,
  'profile' => $profile,
  'item' => $item,
], 200, $mtime);
