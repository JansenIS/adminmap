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
    'emblem_svg' => (string)($pd['emblem_svg'] ?? ''),
    'wiki_description' => (string)($pd['wiki_description'] ?? ''),
    'province_card_image' => (string)($pd['province_card_image'] ?? ''),
    'treasury' => (float)($pd['treasury'] ?? 0),
    'population' => (int)($pd['population'] ?? 0),
    'is_owned' => isset($ownedPids[$pidNum]),
  ];
}

$realms = [];
foreach (['kingdoms', 'great_houses', 'minor_houses', 'free_cities', 'special_territories'] as $type) {
  $bucket = [];
  foreach (($state[$type] ?? []) as $id => $r) {
    if (!is_array($r)) continue;
    $bucket[(string)$id] = [
      'name' => (string)($r['name'] ?? ''),
      'color' => (string)($r['color'] ?? ''),
      'emblem_svg' => (string)($r['emblem_svg'] ?? ''),
    ];
  }
  $realms[$type] = $bucket;
}

api_json_response([
  'ok' => true,
  'session' => $session,
  'provinces' => $provinces,
  'realms' => $realms,
], 200, api_state_mtime());
