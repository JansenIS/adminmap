<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

diplomacy_require_feature();
$state = api_load_state();
$actor = diplomacy_actor($state);
$threads = diplomacy_load_threads_store();
$messages = diplomacy_load_messages_store();
$proposals = diplomacy_load_proposals_store();
$treaties = diplomacy_load_treaties_store();

$visibleThreadIds = [];
$outThreads = [];
foreach ($threads['threads'] as $t) {
  if (!is_array($t) || !diplomacy_thread_visible($t, $actor)) continue;
  $visibleThreadIds[(string)$t['id']] = true;
  $outThreads[] = $t;
}
$outMessages = array_values(array_filter($messages['messages'], static fn($m) => is_array($m) && isset($visibleThreadIds[(string)($m['thread_id'] ?? '')])));
$outProposals = array_values(array_filter($proposals['proposals'], static fn($p) => is_array($p) && isset($visibleThreadIds[(string)($p['thread_id'] ?? '')])));

$visibleProposalIds = [];
foreach ($outProposals as $p) $visibleProposalIds[(string)($p['id'] ?? '')] = true;
$outTreaties = [];
foreach ($treaties['treaties'] as $t) {
  if (!is_array($t)) continue;
  if (($actor['role'] ?? '') === 'admin') { $outTreaties[] = $t; continue; }
  if (isset($visibleProposalIds[(string)($t['source_proposal_id'] ?? '')])) { $outTreaties[] = $t; continue; }
}

diplomacy_response([
  'ok' => true,
  'exported_at' => diplomacy_now(),
  'npc_reaction_status' => 'pending',
  'threads' => $outThreads,
  'messages' => $outMessages,
  'proposals' => $outProposals,
  'treaties' => $outTreaties,
]);
