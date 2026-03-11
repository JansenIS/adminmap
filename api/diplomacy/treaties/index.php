<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

diplomacy_require_feature();
$state = api_load_state();
$actor = diplomacy_actor($state);
$rows = [];

$proposalStore = diplomacy_load_proposals_store();
$proposalById = [];
foreach ($proposalStore['proposals'] as $p) if (is_array($p)) $proposalById[(string)($p['id'] ?? '')] = $p;
$threadStore = diplomacy_load_threads_store();
$threadById = [];
foreach ($threadStore['threads'] as $t) if (is_array($t)) $threadById[(string)($t['id'] ?? '')] = $t;

$store = diplomacy_load_treaties_store();
foreach ($store['treaties'] as $t) {
  if (!is_array($t)) continue;
  if (($actor['role'] ?? '') !== 'admin') {
    $source = $proposalById[(string)($t['source_proposal_id'] ?? '')] ?? null;
    $thread = is_array($source) ? ($threadById[(string)($source['thread_id'] ?? '')] ?? null) : null;
    $partyHit = false;
    foreach ((array)($t['parties'] ?? []) as $party) {
      if (!is_array($party)) continue;
      if ((string)($party['entity_type'] ?? '') === (string)($actor['entity_type'] ?? '') && (string)($party['entity_id'] ?? '') === (string)($actor['entity_id'] ?? '')) { $partyHit = true; break; }
    }
    $public = is_array($thread) && (string)($thread['visibility'] ?? '') === 'public_summary';
    if (!$partyHit && !$public) continue;
  }
  if (trim((string)($_GET['status'] ?? '')) !== '' && (string)$t['status'] !== (string)$_GET['status']) continue;
  if (trim((string)($_GET['treaty_type'] ?? '')) !== '' && (string)$t['treaty_type'] !== (string)$_GET['treaty_type']) continue;
  $rows[] = $t;
}
usort($rows, static fn($a, $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
$pack = diplomacy_paginate($rows, (int)($_GET['page'] ?? 1), (int)($_GET['per_page'] ?? 50));
diplomacy_response(['ok' => true, 'treaties' => $pack['items'], 'page' => $pack['page'], 'per_page' => $pack['per_page'], 'total' => $pack['total']]);
