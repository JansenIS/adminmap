<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

diplomacy_require_feature();
$state = api_load_state();
$actor = diplomacy_actor($state);
if (($actor['role'] ?? 'public') === 'public') diplomacy_response(['error' => 'auth_required'], 403);

$store = orders_api_load_store();
$q = mb_strtolower(trim((string)($_GET['q'] ?? '')), 'UTF-8');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int)($_GET['per_page'] ?? 30)));

$rows = [];
foreach ($store['orders'] as $o) {
  if (!is_array($o)) continue;
  if (($actor['role'] ?? '') !== 'admin') {
    if ((string)($o['entity_type'] ?? '') !== (string)($actor['entity_type'] ?? '')) continue;
    if ((string)($o['entity_id'] ?? '') !== (string)($actor['entity_id'] ?? '')) continue;
  }
  $verdict = is_array($o['verdict'] ?? null) ? $o['verdict'] : null;
  $verdictId = trim((string)($verdict['id'] ?? ''));
  if ($verdictId === '') continue;

  $title = trim((string)($o['title'] ?? ''));
  $entity = trim((string)($o['entity_id'] ?? ''));
  $status = trim((string)($o['status'] ?? ''));
  if ($q !== '') {
    $hay = mb_strtolower($verdictId . ' ' . $title . ' ' . $entity . ' ' . $status, 'UTF-8');
    if (mb_strpos($hay, $q) === false) continue;
  }

  $rows[] = [
    'verdict_id' => $verdictId,
    'order_id' => (string)($o['id'] ?? ''),
    'order_title' => $title,
    'entity_type' => (string)($o['entity_type'] ?? ''),
    'entity_id' => $entity,
    'order_status' => $status,
    'published_at' => (string)($verdict['published_at'] ?? ''),
    'finalized_at' => (string)($verdict['finalized_at'] ?? ''),
  ];
}

usort($rows, static function(array $a, array $b): int {
  return strcmp((string)($b['published_at'] ?: $b['finalized_at']), (string)($a['published_at'] ?: $a['finalized_at']));
});
$total = count($rows);
$offset = ($page - 1) * $perPage;
$items = array_slice($rows, $offset, $perPage);

diplomacy_response(['ok' => true, 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
