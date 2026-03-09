<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/orders_api.php';
orders_api_ensure_store();
$raw = @file_get_contents(orders_api_publications_path());
$dec = is_string($raw) ? json_decode($raw, true) : null;
$rows = is_array($dec['rows'] ?? null) ? $dec['rows'] : [];
$entity = trim((string)($_GET['entity_id'] ?? ''));
$turn = (int)($_GET['turn'] ?? 0);
$cat = trim((string)($_GET['category'] ?? ''));
$out = [];
foreach ($rows as $r) {
  if (!is_array($r)) continue;
  if ($entity !== '' && (string)($r['entity_id'] ?? '') !== $entity) continue;
  if ($turn > 0 && (int)($r['turn_year'] ?? 0) !== $turn) continue;
  if ($cat !== '') {
    $cats = is_array($r['categories'] ?? null) ? $r['categories'] : [];
    if (!in_array($cat, $cats, true)) continue;
  }
  $out[] = $r;
}
orders_api_response(['ok'=>true,'items'=>array_reverse($out)]);
