<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/player_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET']], 405, api_state_mtime());
}

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') api_json_response(['error' => 'token_required'], 400, api_state_mtime());

$state = api_load_state();
$session = player_resolve_session($state, $token);
if ($session === null) api_json_response(['error' => 'invalid_or_expired_token'], 403, api_state_mtime());

$emblemBundle = api_load_emblem_bundle_from_file_or_state($state);
$assetSvgById = [];
foreach (($emblemBundle['assets'] ?? []) as $asset) {
  if (!is_array($asset)) continue;
  $aid = trim((string)($asset['id'] ?? ''));
  $svg = (string)($asset['svg'] ?? '');
  if ($aid === '' || trim($svg) === '') continue;
  $assetSvgById[$aid] = $svg;
}

$assetIdByOwner = [];
foreach (($emblemBundle['refs'] ?? []) as $ref) {
  if (!is_array($ref)) continue;
  $ownerType = trim((string)($ref['owner_type'] ?? ''));
  $ownerId = trim((string)($ref['owner_id'] ?? ''));
  $aid = trim((string)($ref['asset_id'] ?? ''));
  if ($ownerType === '' || $ownerId === '' || $aid === '') continue;
  $assetIdByOwner[$ownerType . ':' . $ownerId] = $aid;
}

$resolveEmblem = static function (array $source, string $ownerType, string $ownerId) use ($assetIdByOwner, $assetSvgById): string {
  $legacy = trim((string)($source['emblem_svg'] ?? ''));
  if ($legacy !== '') return $legacy;
  $assetId = trim((string)($source['emblem_asset_id'] ?? ''));
  if ($assetId === '') $assetId = trim((string)($assetIdByOwner[$ownerType . ':' . $ownerId] ?? ''));
  if ($assetId === '') return '';
  return (string)($assetSvgById[$assetId] ?? '');
};

$ownedPids = array_flip($session['owned_pids']);
$provinces = [];
foreach (($state['provinces'] ?? []) as $pid => $pd) {
  if (!is_array($pd)) continue;
  $pidNum = (int)$pid;
  $provinces[] = [
    'pid' => $pidNum,
    'name' => (string)($pd['name'] ?? ''),
    'owner' => (string)($pd['owner'] ?? ''),
    'suzerain' => (string)($pd['suzerain'] ?? ''),
    'senior' => (string)($pd['senior'] ?? ''),
    'terrain' => (string)($pd['terrain'] ?? ''),
    'kingdom_id' => (string)($pd['kingdom_id'] ?? ''),
    'great_house_id' => (string)($pd['great_house_id'] ?? ''),
    'minor_house_id' => (string)($pd['minor_house_id'] ?? ''),
    'free_city_id' => (string)($pd['free_city_id'] ?? ''),
    'special_territory_id' => (string)($pd['special_territory_id'] ?? ''),
    'fill_rgba' => is_array($pd['fill_rgba'] ?? null) ? array_values($pd['fill_rgba']) : [90, 90, 90, 180],
    'emblem_svg' => $resolveEmblem($pd, 'province', (string)$pidNum),
    'wiki_description' => (string)($pd['wiki_description'] ?? ''),
    'province_card_image' => (string)($pd['province_card_image'] ?? ''),
    'treasury' => (float)($pd['treasury'] ?? 0),
    'population' => (int)($pd['population'] ?? 0),
    'is_owned' => isset($ownedPids[$pidNum]),
  ];
}

$realms = [];
foreach (['kingdoms', 'great_houses', 'minor_houses', 'free_cities', 'special_territories'] as $type) {
  $ownerTypeMap = [
    'kingdoms' => 'kingdom',
    'great_houses' => 'great_house',
    'minor_houses' => 'minor_house',
    'free_cities' => 'free_city',
    'special_territories' => 'special_territory',
  ];
  $ownerType = $ownerTypeMap[$type] ?? rtrim($type, 's');
  $bucket = [];
  foreach (($state[$type] ?? []) as $id => $r) {
    if (!is_array($r)) continue;
    $bucket[(string)$id] = [
      'name' => (string)($r['name'] ?? ''),
      'color' => (string)($r['color'] ?? ''),
      'emblem_svg' => $resolveEmblem($r, $ownerType, (string)$id),
      'minor_house_layer' => ($type === 'great_houses' && is_array($r['minor_house_layer'] ?? null)) ? $r['minor_house_layer'] : null,
      'ruling_house_id' => ($type === 'kingdoms') ? (string)($r['ruling_house_id'] ?? '') : '',
    ];
  }
  $realms[$type] = $bucket;
}

$entityType = (string)($session['entity']['type'] ?? '');
$entityId = (string)($session['entity']['id'] ?? '');
$entityOwnerTypeMap = [
  'kingdoms' => 'kingdom',
  'great_houses' => 'great_house',
  'minor_houses' => 'minor_house',
  'free_cities' => 'free_city',
  'special_territories' => 'special_territory',
];
$entityOwnerType = $entityOwnerTypeMap[$entityType] ?? rtrim($entityType, 's');
$entity = $state[$entityType][$entityId] ?? null;
if (is_array($entity) && $entityOwnerType !== '') {
  $session['entity']['emblem_svg'] = $resolveEmblem($entity, $entityOwnerType, $entityId);
}

api_json_response([
  'ok' => true,
  'session' => $session,
  'provinces' => $provinces,
  'realms' => $realms,
], 200, api_state_mtime());
