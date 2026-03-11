<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

diplomacy_require_feature();
$threadStore = diplomacy_load_threads_store();
$proposalStore = diplomacy_load_proposals_store();
$treatyStore = diplomacy_load_treaties_store();
$telegraph = telegraph_load_messages_store();

$threads = [];
foreach ($threadStore['threads'] as $t) {
  if (!is_array($t)) continue;
  if ((string)($t['visibility'] ?? '') !== 'public_summary') continue;
  $threads[] = [
    'id' => (string)($t['id'] ?? ''),
    'title' => (string)($t['title'] ?? ''),
    'kind' => (string)($t['kind'] ?? ''),
    'status' => (string)($t['status'] ?? ''),
    'latest_message_at' => (string)($t['latest_message_at'] ?? ''),
    'linked_war_id' => (string)($t['linked_war_id'] ?? ''),
    'tags' => (array)($t['tags'] ?? []),
  ];
}
usort($threads, static fn($a, $b) => strcmp((string)($b['latest_message_at'] ?? ''), (string)($a['latest_message_at'] ?? '')));

$publicThreadIds = array_fill_keys(array_map(static fn($x) => (string)($x['id'] ?? ''), $threads), true);
$proposalById = [];
$publicProposals = [];
foreach ($proposalStore['proposals'] as $p) {
  if (!is_array($p)) continue;
  $pid = (string)($p['id'] ?? '');
  if ($pid !== '') $proposalById[$pid] = $p;
  if (!isset($publicThreadIds[(string)($p['thread_id'] ?? '')])) continue;
  $publicProposals[] = [
    'id' => $pid,
    'thread_id' => (string)($p['thread_id'] ?? ''),
    'proposal_type' => (string)($p['proposal_type'] ?? ''),
    'title' => (string)($p['title'] ?? ''),
    'summary' => (string)($p['summary'] ?? ''),
    'status' => (string)($p['status'] ?? ''),
    'effective_from' => (string)($p['effective_from'] ?? ''),
    'effective_until' => (string)($p['effective_until'] ?? ''),
  ];
}

$publicTreaties = [];
foreach ($treatyStore['treaties'] as $t) {
  if (!is_array($t)) continue;
  $source = $proposalById[(string)($t['source_proposal_id'] ?? '')] ?? null;
  if (!is_array($source)) continue;
  if (!isset($publicThreadIds[(string)($source['thread_id'] ?? '')])) continue;
  $publicTreaties[] = [
    'id' => (string)($t['id'] ?? ''),
    'title' => (string)($t['title'] ?? ''),
    'treaty_type' => (string)($t['treaty_type'] ?? ''),
    'status' => (string)($t['status'] ?? ''),
    'source_proposal_id' => (string)($t['source_proposal_id'] ?? ''),
    'linked_war_id' => (string)($t['linked_war_id'] ?? ''),
    'updated_at' => (string)($t['updated_at'] ?? ''),
  ];
}
usort($publicTreaties, static fn($a, $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));

$communiques = [];
foreach ($telegraph['messages'] as $m) {
  if (!is_array($m)) continue;
  if ((string)($m['scope'] ?? '') !== 'public') continue;
  if ((string)($m['moderation']['status'] ?? '') !== 'approved') continue;
  $tags = (array)($m['content']['tags'] ?? []);
  $threadId = trim((string)($m['game_hooks']['linked_diplomacy_thread_id'] ?? ''));
  $isDiplo = in_array('diplomacy', $tags, true) || $threadId !== '';
  if (!$isDiplo) continue;
  if ($threadId !== '' && !isset($publicThreadIds[$threadId])) continue;
  $communiques[] = [
    'id' => (string)($m['id'] ?? ''),
    'created_at' => (string)($m['created_at'] ?? ''),
    'title' => (string)($m['content']['title'] ?? ''),
    'short_preview' => (string)($m['content']['short_preview'] ?? ''),
    'linked_thread_id' => $threadId,
    'linked_war_id' => (string)($m['game_hooks']['linked_war_id'] ?? ''),
  ];
}
usort($communiques, static fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));

$limit = max(1, min(50, (int)($_GET['limit'] ?? 12)));

diplomacy_response([
  'ok' => true,
  'threads' => array_slice($threads, 0, $limit),
  'proposals' => array_slice($publicProposals, 0, $limit),
  'treaties' => array_slice($publicTreaties, 0, $limit),
  'communiques' => array_slice($communiques, 0, $limit),
]);
