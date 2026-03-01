<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/state_api.php';
require_once dirname(__DIR__, 2) . '/lib/genealogy_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET']], 405, max(api_state_mtime(), genealogy_mtime()));
}

$state = api_load_state();
$genealogy = genealogy_load();
$names = [];

$addName = static function (string $raw) use (&$names): void {
  $name = trim($raw);
  if ($name !== '') $names[$name] = true;
};

foreach (($state['people'] ?? []) as $person) {
  $name = is_array($person) ? (string)($person['name'] ?? '') : (string)$person;
  $addName($name);
}

foreach (($state['provinces'] ?? []) as $province) {
  if (!is_array($province)) continue;
  $addName((string)($province['owner'] ?? ''));
}

foreach (['kingdoms', 'great_houses', 'minor_houses', 'free_cities'] as $bucket) {
  foreach (($state[$bucket] ?? []) as $realm) {
    if (!is_array($realm)) continue;
    $addName((string)($realm['ruler'] ?? ''));

    if ($bucket === 'great_houses') {
      $layer = $realm['minor_house_layer'] ?? null;
      if (is_array($layer) && is_array($layer['vassals'] ?? null)) {
        foreach ($layer['vassals'] as $vassal) {
          if (!is_array($vassal)) continue;
          $addName((string)($vassal['ruler'] ?? ''));
        }
      }
    }
  }
}

foreach (($genealogy['characters'] ?? []) as $character) {
  if (!is_array($character)) continue;
  $addName((string)($character['name'] ?? ''));
}

$list = array_keys($names);
usort($list, static fn($a, $b) => strcasecmp($a, $b));

api_json_response([
  'people' => $list,
  'count' => count($list),
], 200, max(api_state_mtime(), genealogy_mtime()));
