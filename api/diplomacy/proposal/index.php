<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

diplomacy_require_feature();
$id = trim((string)($_GET['proposal_id'] ?? ''));
if ($id === '') diplomacy_response(['error' => 'proposal_id_required'], 400);
$state = api_load_state();
$actor = diplomacy_actor($state);
$threadStore = diplomacy_load_threads_store();
$threadMap = [];
foreach ($threadStore['threads'] as $t) if (is_array($t)) $threadMap[(string)$t['id']] = $t;
$store = diplomacy_load_proposals_store();
foreach ($store['proposals'] as $p) {
  if (!is_array($p) || (string)($p['id'] ?? '') !== $id) continue;
  $thread = $threadMap[(string)($p['thread_id'] ?? '')] ?? null;
  if (!is_array($thread) || !diplomacy_thread_visible($thread, $actor)) diplomacy_response(['error' => 'forbidden'], 403);
  diplomacy_response(['ok' => true, 'proposal' => $p]);
}
diplomacy_response(['error' => 'not_found'], 404);
