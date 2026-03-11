<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

telegraph_require_feature();
$state = api_load_state();
$actor = telegraph_actor_from_request($state);
if (($actor['role'] ?? '') === 'public') telegraph_response(['error' => 'auth_required'], 403);
$payload = telegraph_request_payload();
$id = trim((string)($payload['id'] ?? ''));
if ($id === '') telegraph_response(['error' => 'id_required'], 400);

$store = telegraph_load_messages_store();
$updated = false;
foreach ($store['messages'] as &$msg) {
  if (!is_array($msg) || (string)($msg['id'] ?? '') !== $id) continue;
  if (!telegraph_message_visible_for($msg, $actor)) telegraph_response(['error' => 'forbidden'], 403);
  $isSender = (string)($msg['sender']['sender_entity_type'] ?? '') === (string)($actor['entity_type'] ?? '')
    && (string)($msg['sender']['sender_entity_id'] ?? '') === (string)($actor['entity_id'] ?? '');
  if ($isSender) $msg['delivery']['read_by_sender'] = true;
  else $msg['delivery']['read_by_target'] = true;
  $msg['updated_at'] = telegraph_now_iso();
  $updated = true;
}
unset($msg);
if (!$updated) telegraph_response(['error' => 'not_found'], 404);
telegraph_save_messages_store($store);
telegraph_response(['ok' => true]);
