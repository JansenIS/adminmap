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

$ownerTypeByRealmType = [
  'kingdoms' => 'kingdom',
  'great_houses' => 'great_house',
  'minor_houses' => 'minor_house',
  'free_cities' => 'free_city',
];
$refs = api_build_refs_by_owner_from_file_or_state($state);
$ownerType = $ownerTypeByRealmType[$type] ?? $type;
$ownerKey = $ownerType . ':' . $id;
if (!isset($item['emblem_asset_id']) && isset($refs[$ownerKey]) && $refs[$ownerKey] !== '') {
  $item['emblem_asset_id'] = $refs[$ownerKey];
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
