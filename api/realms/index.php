<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/state_api.php';

$state = api_load_state();
$mtime = api_state_mtime();
$type = (string)($_GET['type'] ?? '');
$allowed = ['kingdoms', 'great_houses', 'minor_houses', 'free_cities'];
$profile = (string)($_GET['profile'] ?? 'full');
if (!in_array($type, $allowed, true)) {
  api_json_response(['error' => 'invalid_type', 'allowed' => $allowed], 400, $mtime);
}

$ownerTypeByRealmType = [
  'kingdoms' => 'kingdom',
  'great_houses' => 'great_house',
  'minor_houses' => 'minor_house',
  'free_cities' => 'free_city',
];
$refs = api_build_refs_by_owner_from_file_or_state($state);
$ownerType = $ownerTypeByRealmType[$type] ?? $type;

$items = [];
foreach (($state[$type] ?? []) as $id => $realm) {
  if (!is_array($realm)) continue;
  $ownerKey = $ownerType . ':' . (string)$id;
  if (!isset($realm['emblem_asset_id']) && isset($refs[$ownerKey]) && $refs[$ownerKey] !== '') {
    $realm['emblem_asset_id'] = $refs[$ownerKey];
  }
  if ($profile === 'compact') {
    unset($realm['emblem_svg']);
  }
  $realm['id'] = (string)$id;
  $items[] = $realm;
}

api_json_response([
  'type' => $type,
  'total' => count($items),
  'profile' => $profile,
  'items' => $items,
], 200, $mtime);
