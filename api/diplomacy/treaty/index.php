<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

$id = trim((string)($_GET['treaty_id'] ?? ''));
if ($id === '') diplomacy_response(['error' => 'treaty_id_required'], 400);
$state = api_load_state();
$actor = diplomacy_actor($state);

$proposalStore = diplomacy_load_proposals_store();
$proposalById = [];
foreach ($proposalStore['proposals'] as $p) if (is_array($p)) $proposalById[(string)($p['id'] ?? '')] = $p;
$threadStore = diplomacy_load_threads_store();
$threadById = [];
foreach ($threadStore['threads'] as $t) if (is_array($t)) $threadById[(string)($t['id'] ?? '')] = $t;

$store = diplomacy_load_treaties_store();
foreach ($store['treaties'] as $t) {
  if (!is_array($t) || (string)($t['id'] ?? '') !== $id) continue;
  if (($actor['role'] ?? '') !== 'admin') {
    $source = $proposalById[(string)($t['source_proposal_id'] ?? '')] ?? null;
    $thread = is_array($source) ? ($threadById[(string)($source['thread_id'] ?? '')] ?? null) : null;
    $partyHit = false;
    foreach ((array)($t['parties'] ?? []) as $party) {
      if (!is_array($party)) continue;
      if ((string)($party['entity_type'] ?? '') === (string)($actor['entity_type'] ?? '') && (string)($party['entity_id'] ?? '') === (string)($actor['entity_id'] ?? '')) { $partyHit = true; break; }
    }
    $public = is_array($thread) && (string)($thread['visibility'] ?? '') === 'public_summary';
    if (!$partyHit && !$public) diplomacy_response(['error' => 'forbidden'], 403);
  }
  diplomacy_response(['ok' => true, 'treaty' => $t]);
}
diplomacy_response(['error' => 'not_found'], 404);
