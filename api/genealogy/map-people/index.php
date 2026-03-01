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

foreach (api_collect_people_names_from_state($state) as $name) {
  $trimmed = trim((string)$name);
  if ($trimmed !== '') $names[$trimmed] = true;
}

foreach (($genealogy['characters'] ?? []) as $character) {
  if (!is_array($character)) continue;
  $name = trim((string)($character['name'] ?? ''));
  if ($name !== '') $names[$name] = true;
}

$list = array_keys($names);
usort($list, static fn($a, $b) => strcasecmp((string)$a, (string)$b));

api_json_response([
  'people' => $list,
  'count' => count($list),
], 200, max(api_state_mtime(), genealogy_mtime()));
