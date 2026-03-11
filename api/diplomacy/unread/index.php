<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

diplomacy_require_feature();
$state = api_load_state();
$actor = diplomacy_require_actor($state);
$threads = diplomacy_load_threads_store();
$totalThreads = 0; $totalMessages = 0; $proposalPending = 0;
$key = (string)$actor['entity_type'] . ':' . (string)$actor['entity_id'];
$visibleThreadIds = [];
foreach ($threads['threads'] as $t) {
  if (!is_array($t) || !diplomacy_thread_visible($t, $actor)) continue;
  $visibleThreadIds[(string)($t['id'] ?? '')] = true;
  $c = (int)($t['unread_counters'][$key] ?? 0);
  if ($c > 0) { $totalThreads++; $totalMessages += $c; }
}
$props = diplomacy_load_proposals_store();
foreach ($props['proposals'] as $p) {
  if (!is_array($p)) continue;
  if (!isset($visibleThreadIds[(string)($p['thread_id'] ?? '')])) continue;
  if (in_array((string)($p['status'] ?? ''), ['pending', 'countered', 'ratification_pending'], true)) $proposalPending++;
}
diplomacy_response(['ok' => true, 'threads_with_unread' => $totalThreads, 'unread_messages' => $totalMessages, 'proposal_pending' => $proposalPending]);
