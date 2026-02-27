<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/state_api.php';

$state = api_load_state();
$mtime = api_state_mtime();
$type = (string)($_GET['type'] ?? '');
$allowed = ['kingdoms', 'great_houses', 'minor_houses', 'free_cities'];
if (!in_array($type, $allowed, true)) {
  api_json_response(['error' => 'invalid_type', 'allowed' => $allowed], 400, $mtime);
}

$items = [];
foreach (($state[$type] ?? []) as $id => $realm) {
  if (!is_array($realm)) continue;
  $realm['id'] = (string)$id;
  $items[] = $realm;
}

api_json_response([
  'type' => $type,
  'total' => count($items),
  'items' => $items,
], 200, $mtime);
