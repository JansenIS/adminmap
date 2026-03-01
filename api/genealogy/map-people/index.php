<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/state_api.php';
require_once dirname(__DIR__, 2) . '/lib/genealogy_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET']], 405, max(api_state_mtime(), genealogy_mtime()));
}

$state = api_load_state();
$names = [];

$addName = static function (string $raw) use (&$names): void {
  $name = trim($raw);
  if ($name !== '') $names[$name] = true;
};

foreach (($state['people'] ?? []) as $person) {
  $name = is_array($person) ? (string)($person['name'] ?? '') : (string)$person;
  $addName($name);
}

foreach ((array)($state['provinces'] ?? []) as $province) {
  if (!is_array($province)) continue;
  foreach (['owner', 'suzerain', 'senior'] as $field) {
    $addName((string)($province[$field] ?? ''));
  }
}

foreach (['great_houses', 'minor_houses', 'kingdoms', 'free_cities'] as $collection) {
  foreach ((array)($state[$collection] ?? []) as $entry) {
    if (!is_array($entry)) continue;
    $addName((string)($entry['name'] ?? ''));
  }
}

$list = array_keys($names);
usort($list, static fn($a, $b) => strcasecmp($a, $b));

api_json_response([
  'people' => $list,
  'count' => count($list),
], 200, max(api_state_mtime(), genealogy_mtime()));
