<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/turn_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  turn_api_response(['error' => 'method_not_allowed', 'allowed' => ['GET']], 405);
}

$index = turn_api_load_index();
$onlyPublished = (string)($_GET['published_only'] ?? '0') === '1';
$items = [];
foreach (($index['turns'] ?? []) as $year) {
  $turn = turn_api_load_turn((int)$year);
  if (!is_array($turn)) continue;
  if ($onlyPublished && (string)($turn['status'] ?? '') !== 'published') continue;
  $items[] = [
    'year' => (int)$year,
    'status' => (string)($turn['status'] ?? ''),
    'version' => (string)($turn['version'] ?? turn_api_turn_version($turn)),
    'entity_state_records' => (int)($turn['entity_state']['records'] ?? 0),
    'economy_state_records' => (int)($turn['economy_state']['records'] ?? 0),
    'entity_treasury_records' => (int)($turn['entity_treasury']['records'] ?? 0),
    'province_treasury_records' => (int)($turn['province_treasury']['records'] ?? 0),
    'treasury_ledger_records' => (int)($turn['treasury_ledger']['records'] ?? 0),
    'treaty_records' => (int)($turn['treaties']['records'] ?? 0),
    'active_treaty_records' => (int)($turn['treaties']['active_records'] ?? 0),
    'published_at' => $turn['published_at'] ?? null,
  ];
}

usort($items, static fn($a, $b) => ($a['year'] <=> $b['year']));
turn_api_response(['items' => $items, 'total' => count($items)], 200);
