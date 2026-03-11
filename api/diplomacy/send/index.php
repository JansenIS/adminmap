<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

diplomacy_require_feature();
$state = api_load_state();
$actor = diplomacy_require_actor($state);
$payload = diplomacy_request_payload();
$idem = trim((string)($payload['idempotency_key'] ?? ''));
if ($idem !== '' && !diplomacy_claim_idempotency('send:' . $idem)) diplomacy_response(['ok' => true, 'duplicate' => true]);

$threadStore = diplomacy_load_threads_store();
$messageStore = diplomacy_load_messages_store();
$threadId = trim((string)($payload['thread_id'] ?? ''));
$thread = null;
$threadIndex = -1;
foreach ($threadStore['threads'] as $idx => $row) {
  if (is_array($row) && (string)($row['id'] ?? '') === $threadId) { $thread = $row; $threadIndex = $idx; break; }
}
if (!is_array($thread)) {
  $target = is_array($payload['target'] ?? null) ? $payload['target'] : diplomacy_resolve_entity($state, (string)($payload['target_entity'] ?? ''));
  if (!is_array($target)) diplomacy_response(['error' => 'thread_or_target_required'], 400);
  $threadId = diplomacy_next_id('dth');
  $now = diplomacy_now();
  $thread = [
    'id' => $threadId, 'created_at' => $now, 'updated_at' => $now, 'created_by_type' => $actor['role'], 'created_by_user_id' => '',
    'created_by_entity_type' => (string)$actor['entity_type'], 'created_by_entity_id' => (string)$actor['entity_id'],
    'title' => mb_substr(trim((string)($payload['title'] ?? 'Новая дипломатическая ветка')), 0, 180),
    'status' => 'open', 'kind' => trim((string)($payload['kind'] ?? 'bilateral')) ?: 'bilateral',
    'participants' => [
      ['entity_type' => (string)$actor['entity_type'], 'entity_id' => (string)$actor['entity_id'], 'role' => 'initiator', 'can_send' => true, 'can_ratify' => true, 'can_propose' => true, 'can_edit_draft' => true, 'joined_at' => $now, 'left_at' => '', 'hidden_from_public' => false],
      ['entity_type' => (string)$target['entity_type'], 'entity_id' => (string)$target['entity_id'], 'role' => 'responder', 'can_send' => true, 'can_ratify' => true, 'can_propose' => true, 'can_edit_draft' => true, 'joined_at' => $now, 'left_at' => '', 'hidden_from_public' => false],
    ],
    'visibility' => (string)($payload['visibility'] ?? 'participants_only'), 'linked_war_id' => trim((string)($payload['linked_war_id'] ?? '')), 'linked_order_id' => trim((string)($payload['linked_order_id'] ?? '')), 'linked_verdict_id' => trim((string)($payload['linked_verdict_id'] ?? '')),
    'linked_chronicle_ids' => [], 'latest_message_at' => $now, 'unread_counters' => [], 'tags' => (array)($payload['tags'] ?? []), 'metadata' => (array)($payload['metadata'] ?? []),
  ];
  $threadStore['threads'][] = $thread;
  $threadIndex = count($threadStore['threads']) - 1;
}
if (!diplomacy_thread_visible($thread, $actor)) diplomacy_response(['error' => 'forbidden'], 403);
if (!diplomacy_actor_can($thread, $actor, 'send')) diplomacy_response(['error' => 'send_forbidden'], 403);

$body = mb_substr(trim((string)($payload['body'] ?? '')), 0, 5000);
if ($body === '') diplomacy_response(['error' => 'body_required'], 400);
$msg = [
  'id' => diplomacy_next_id('dmsg'), 'thread_id' => $threadId, 'created_at' => diplomacy_now(),
  'sender_entity_type' => (string)$actor['entity_type'], 'sender_entity_id' => (string)$actor['entity_id'], 'sender_character_id' => trim((string)($payload['sender_character_id'] ?? '')),
  'source_type' => trim((string)($payload['source_type'] ?? 'web')) ?: 'web', 'message_type' => trim((string)($payload['message_type'] ?? 'note')) ?: 'note',
  'body' => $body, 'attachments' => is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [], 'tags' => is_array($payload['tags'] ?? null) ? $payload['tags'] : [],
  'linked_telegraph_message_id' => '', 'visibility' => trim((string)($payload['visibility'] ?? 'participants')) ?: 'participants', 'moderation_status' => 'approved',
  'linked_order_id' => trim((string)($payload['linked_order_id'] ?? '')), 'linked_verdict_id' => trim((string)($payload['linked_verdict_id'] ?? '')), 'linked_war_id' => trim((string)($payload['linked_war_id'] ?? '')),
  'metadata' => (array)($payload['metadata'] ?? []),
];
$msg['linked_telegraph_message_id'] = diplomacy_to_telegraph($msg, $actor, $payload, $thread);
$messageStore['messages'][] = $msg;

$thread['latest_message_at'] = (string)$msg['created_at'];
$thread['updated_at'] = (string)$msg['created_at'];
$thread['status'] = 'pending_response';
foreach ((array)$thread['participants'] as $p) {
  if (!is_array($p)) continue;
  $key = (string)($p['entity_type'] ?? '') . ':' . (string)($p['entity_id'] ?? '');
  if ($key === (string)$actor['entity_type'] . ':' . (string)$actor['entity_id']) continue;
  $thread['unread_counters'][$key] = (int)($thread['unread_counters'][$key] ?? 0) + 1;
}
$threadStore['threads'][$threadIndex] = $thread;

diplomacy_save_threads_store($threadStore);
diplomacy_save_messages_store($messageStore);
diplomacy_append_log('send_message', ['thread_id' => $threadId, 'message_id' => $msg['id'], 'actor' => $actor]);

diplomacy_response(['ok' => true, 'thread' => $thread, 'message' => $msg], 201);
