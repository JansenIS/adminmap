<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/state_api.php';
require_once dirname(__DIR__, 2) . '/lib/genealogy_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET']], 405, max(api_state_mtime(), genealogy_mtime()));
}

$state = api_load_state();
$names = [];

foreach (($state['people'] ?? []) as $person) {
  $name = is_array($person) ? trim((string)($person['name'] ?? '')) : trim((string)$person);
  if ($name !== '') $names[$name] = true;
}

foreach (($state['provinces'] ?? []) as $province) {
  if (!is_array($province)) continue;
  foreach (['owner', 'suzerain', 'senior'] as $field) {
    $name = trim((string)($province[$field] ?? ''));
    if ($name !== '') $names[$name] = true;
  }
}

$list = array_keys($names);
usort($list, static fn($a, $b) => strcasecmp($a, $b));

api_json_response([
  'people' => $list,
  'count' => count($list),
], 200, max(api_state_mtime(), genealogy_mtime()));
