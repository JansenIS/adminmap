<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/orders_api.php';
orders_api_require_admin();
$store = orders_api_load_store();
$status = trim((string)($_GET['status'] ?? ''));
$turn = (int)($_GET['turn'] ?? 0);
$category = trim((string)($_GET['category'] ?? ''));
$entity = trim((string)($_GET['entity'] ?? ''));
$hasAttachments = trim((string)($_GET['has_attachments'] ?? ''));
$rows = [];
foreach ($store['orders'] as $o) {
  if (!is_array($o)) continue;
  if ($status !== '' && (string)($o['status'] ?? '') !== $status) continue;
  if ($turn > 0 && (int)($o['turn_year'] ?? 0) !== $turn) continue;
  if ($entity !== '' && mb_stripos((string)($o['entity_id'] ?? ''), $entity) === false) continue;
  if ($category !== '') {
    $ok = false;
    foreach (($o['action_items'] ?? []) as $ai) { if ((string)($ai['category'] ?? '') === $category) { $ok = true; break; } }
    if (!$ok) continue;
  }
  if ($hasAttachments !== '') {
    $has = !empty($o['public_images']) || !empty($o['private_attachments']);
    if (($hasAttachments === '1' || $hasAttachments === 'true') && !$has) continue;
    if (($hasAttachments === '0' || $hasAttachments === 'false') && $has) continue;
  }
  if (in_array((string)($o['status'] ?? ''), ['submitted','pending_review','needs_clarification','verdict_ready'], true)) $rows[] = $o;
}

orders_api_response(['ok'=>true,'items'=>$rows]);
