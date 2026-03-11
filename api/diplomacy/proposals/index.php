<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

diplomacy_require_feature();
$state = api_load_state();
$actor = diplomacy_actor($state);
$threadStore = diplomacy_load_threads_store();
$visibleThreads = [];
foreach ($threadStore['threads'] as $t) if (is_array($t) && diplomacy_thread_visible($t, $actor)) $visibleThreads[(string)$t['id']] = true;
$rows = [];
$store = diplomacy_load_proposals_store();
foreach ($store['proposals'] as $p) {
  if (!is_array($p)) continue;
  if (!isset($visibleThreads[(string)($p['thread_id'] ?? '')])) continue;
  if (trim((string)($_GET['thread_id'] ?? '')) !== '' && (string)$p['thread_id'] !== (string)$_GET['thread_id']) continue;
  if (trim((string)($_GET['status'] ?? '')) !== '' && (string)$p['status'] !== (string)$_GET['status']) continue;
  if ((int)($_GET['requiring_verdict'] ?? 0) === 1 && trim((string)($p['linked_verdict_id'] ?? '')) !== '') continue;
  $rows[] = $p;
}
usort($rows, static fn($a, $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
$pack = diplomacy_paginate($rows, (int)($_GET['page'] ?? 1), (int)($_GET['per_page'] ?? 50));
diplomacy_response(['ok' => true, 'proposals' => $pack['items'], 'page' => $pack['page'], 'per_page' => $pack['per_page'], 'total' => $pack['total']]);
