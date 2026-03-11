<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

diplomacy_require_feature();
$state = api_load_state();
$actor = diplomacy_require_actor($state);
$payload = diplomacy_request_payload();
$proposalId = trim((string)($payload['proposal_id'] ?? ''));
$action = trim((string)($payload['action'] ?? ''));
if ($proposalId === '' || !in_array($action, ['accept', 'reject', 'counter', 'withdraw'], true)) diplomacy_response(['error' => 'invalid_request'], 400);
$store = diplomacy_load_proposals_store();
$threadStore = diplomacy_load_threads_store();
foreach ($store['proposals'] as $idx => $p) {
  if (!is_array($p) || (string)($p['id'] ?? '') !== $proposalId) continue;

  $thread = null;
  foreach ($threadStore['threads'] as $t) if (is_array($t) && (string)($t['id'] ?? '') === (string)($p['thread_id'] ?? '')) { $thread = $t; break; }
  if (!is_array($thread) || !diplomacy_thread_visible($thread, $actor)) diplomacy_response(['error' => 'forbidden'], 403);
  if (!diplomacy_actor_can($thread, $actor, 'ratify')) diplomacy_response(['error' => 'ratify_forbidden'], 403);

  $rat = ['entity_type' => (string)$actor['entity_type'], 'entity_id' => (string)$actor['entity_id'], 'action' => $action, 'note' => mb_substr(trim((string)($payload['note'] ?? '')), 0, 1000), 'at' => diplomacy_now()];
  $p['ratifications'][] = $rat;
  if ($action === 'accept') $p['status'] = 'accepted_in_principle';
  if ($action === 'reject') $p['status'] = 'rejected';
  if ($action === 'counter') $p['status'] = 'countered';
  if ($action === 'withdraw') $p['status'] = 'withdrawn';
  if ($action === 'accept') {
    $treaties = diplomacy_load_treaties_store();
    $parties = !empty($p['target_entities']) ? (array)$p['target_entities'] : array_values(array_map(static fn($x) => ['entity_type' => (string)($x['entity_type'] ?? ''), 'entity_id' => (string)($x['entity_id'] ?? '')], (array)($thread['participants'] ?? [])));
    $treaty = [
      'id' => diplomacy_next_id('dtr'), 'source_proposal_id' => (string)$p['id'], 'created_at' => diplomacy_now(), 'updated_at' => diplomacy_now(),
      'title' => (string)$p['title'], 'treaty_type' => (string)$p['proposal_type'], 'status' => 'active', 'parties' => $parties, 'clauses' => (array)($p['clauses'] ?? []),
      'start_turn' => '', 'end_turn' => '', 'automatic_effects' => [], 'manual_effects' => [], 'enforcement_mode' => 'narrative', 'linked_events' => [],
      'linked_war_id' => (string)($p['linked_war_id'] ?? ''), 'linked_orders' => array_values(array_filter([(string)($p['linked_order_id'] ?? '')])), 'linked_verdicts' => array_values(array_filter([(string)($p['linked_verdict_id'] ?? '')])),
      'violation_log' => [], 'metadata' => ['created_by_ratification' => true],
    ];
    $treaties['treaties'][] = $treaty;
    diplomacy_save_treaties_store($treaties);
    $p['linked_treaty_id'] = (string)$treaty['id'];
    $p['status'] = 'active';
  }
  $p['updated_at'] = diplomacy_now();
  $store['proposals'][$idx] = $p;
  diplomacy_save_proposals_store($store);
  diplomacy_response(['ok' => true, 'proposal' => $p]);
}
diplomacy_response(['error' => 'not_found'], 404);
