<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

diplomacy_require_feature();
$state = api_load_state();
$actor = diplomacy_require_actor($state);
$payload = diplomacy_request_payload();
$threadId = trim((string)($payload['thread_id'] ?? ''));
if ($threadId === '') diplomacy_response(['error' => 'thread_id_required'], 400);
$threadStore = diplomacy_load_threads_store();
$thread = null;
foreach ($threadStore['threads'] as $row) if (is_array($row) && (string)($row['id'] ?? '') === $threadId) { $thread = $row; break; }
if (!is_array($thread)) diplomacy_response(['error' => 'thread_not_found'], 404);
if (!diplomacy_thread_visible($thread, $actor)) diplomacy_response(['error' => 'forbidden'], 403);
if (!diplomacy_actor_can($thread, $actor, 'propose')) diplomacy_response(['error' => 'propose_forbidden'], 403);

$type = trim((string)($payload['proposal_type'] ?? 'custom'));
$title = mb_substr(trim((string)($payload['title'] ?? ('Proposal: ' . $type))), 0, 200);
$summary = mb_substr(trim((string)($payload['summary'] ?? '')), 0, 2000);
if ($summary === '') diplomacy_response(['error' => 'summary_required'], 400);
$defaultTargets = [];
foreach ((array)($thread['participants'] ?? []) as $p) {
  if (!is_array($p)) continue;
  $defaultTargets[] = ['entity_type' => (string)($p['entity_type'] ?? ''), 'entity_id' => (string)($p['entity_id'] ?? '')];
}

$proposal = [
  'id' => diplomacy_next_id('dpr'), 'thread_id' => $threadId, 'created_at' => diplomacy_now(), 'updated_at' => diplomacy_now(),
  'created_by_entity_type' => (string)$actor['entity_type'], 'created_by_entity_id' => (string)$actor['entity_id'],
  'proposal_type' => $type, 'title' => $title, 'summary' => $summary, 'status' => 'pending',
  'clauses' => is_array($payload['clauses'] ?? null) ? $payload['clauses'] : [], 'target_entities' => is_array($payload['target_entities'] ?? null) && !empty($payload['target_entities']) ? $payload['target_entities'] : $defaultTargets,
  'ratification_requirements' => is_array($payload['ratification_requirements'] ?? null) ? $payload['ratification_requirements'] : [], 'ratifications' => [],
  'effective_from' => trim((string)($payload['effective_from'] ?? '')), 'effective_until' => trim((string)($payload['effective_until'] ?? '')),
  'linked_treaty_id' => '', 'linked_order_id' => trim((string)($payload['linked_order_id'] ?? '')), 'linked_verdict_id' => trim((string)($payload['linked_verdict_id'] ?? '')), 'linked_war_id' => trim((string)($payload['linked_war_id'] ?? '')),
  'linked_chronicle_ids' => [], 'metadata' => (array)($payload['metadata'] ?? []),
];
$store = diplomacy_load_proposals_store();
$store['proposals'][] = $proposal;
diplomacy_save_proposals_store($store);

$messagesStore = diplomacy_load_messages_store();
$proposalMsg = [
  'id' => diplomacy_next_id('dmsg'), 'thread_id' => $threadId, 'created_at' => diplomacy_now(),
  'sender_entity_type' => (string)$actor['entity_type'], 'sender_entity_id' => (string)$actor['entity_id'], 'sender_character_id' => '',
  'source_type' => 'web', 'message_type' => 'proposal',
  'body' => 'Proposal: ' . $title . "\n" . $summary, 'attachments' => [['type' => 'proposal', 'proposal_id' => (string)$proposal['id']]], 'tags' => ['proposal', $type],
  'linked_telegraph_message_id' => '', 'visibility' => 'participants', 'moderation_status' => 'approved',
  'linked_order_id' => (string)$proposal['linked_order_id'], 'linked_verdict_id' => (string)$proposal['linked_verdict_id'], 'linked_war_id' => (string)$proposal['linked_war_id'],
  'metadata' => ['system_generated' => true],
];
$proposalMsg['linked_telegraph_message_id'] = diplomacy_to_telegraph($proposalMsg, $actor, ['proposal_attachment' => $proposal], $thread);
$messagesStore['messages'][] = $proposalMsg;
diplomacy_save_messages_store($messagesStore);

diplomacy_response(['ok' => true, 'proposal' => $proposal], 201);
