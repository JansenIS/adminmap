<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

telegraph_require_feature();
$state = api_load_state();
$actor = telegraph_actor_from_request($state);
$payload = telegraph_request_payload();
$settings = telegraph_load_settings_store();
$scope = trim((string)($payload['scope'] ?? 'public'));
if (!in_array($scope, ['public', 'private', 'diplomatic', 'admin', 'system'], true)) telegraph_response(['error' => 'invalid_scope'], 400);

if (($actor['role'] ?? '') === 'public') telegraph_response(['error' => 'auth_required'], 403);
if (($actor['role'] ?? '') !== 'admin' && in_array($scope, ['admin', 'system'], true)) telegraph_response(['error' => 'admin_scope_only'], 403);

$idem = trim((string)($payload['idempotency_key'] ?? ''));
if ($idem !== '' && !telegraph_claim_idempotency('send:' . $idem)) telegraph_response(['ok' => true, 'duplicate' => true]);

$target = is_array($payload['target'] ?? null) ? $payload['target'] : [];
$targetType = trim((string)($target['target_type'] ?? ($scope === 'public' ? 'none' : 'entity')));
$targetEntityType = trim((string)($target['target_entity_type'] ?? ''));
$targetEntityId = trim((string)($target['target_entity_id'] ?? ''));
if (in_array($scope, ['private', 'diplomatic'], true) && ($targetEntityType === '' || $targetEntityId === '')) {
  telegraph_response(['error' => 'target_required'], 400);
}

$turnYear = telegraph_turn_year();
$now = telegraph_now_iso();
$title = mb_substr(trim((string)($payload['title'] ?? '')), 0, 140);
$body = mb_substr(trim((string)($payload['body'] ?? '')), 0, 5000);
if ($body === '') telegraph_response(['error' => 'body_required'], 400);
$autoApproveWeb = (bool)($settings['auto_approve_web_public'] ?? false);
$status = ($scope === 'public' && ($actor['role'] ?? '') !== 'admin' && !$autoApproveWeb) ? 'pending' : 'approved';


$senderEntityType = (string)($actor['entity_type'] ?? '');
$senderEntityId = (string)($actor['entity_id'] ?? '');
$senderType = (string)($actor['sender_type'] ?? 'entity');
$senderDisplayName = (string)($actor['sender_display_name'] ?? '');
$senderCharacterId = (string)($actor['sender_character_id'] ?? '');

if (($actor['role'] ?? '') === 'admin') {
  $senderOverride = is_array($payload['sender_override'] ?? null) ? $payload['sender_override'] : [];
  $overrideType = trim((string)($senderOverride['sender_entity_type'] ?? ''));
  $overrideId = trim((string)($senderOverride['sender_entity_id'] ?? ''));
  if (($overrideType !== '' || $overrideId !== '') && ($overrideType === '' || $overrideId === '')) {
    telegraph_response(['error' => 'sender_override_invalid'], 400);
  }
  if ($overrideType !== '' && $overrideId !== '') {
    $senderRow = telegraph_find_entity_in_state($state, $overrideType, $overrideId);
    if (!is_array($senderRow)) telegraph_response(['error' => 'sender_not_found'], 400);
    $profile = telegraph_resolve_entity_sender_profile($state, $overrideType, $overrideId, (string)($senderRow['name'] ?? $overrideId));
    $senderEntityType = $overrideType;
    $senderEntityId = $overrideId;
    $senderType = 'entity';
    $senderDisplayName = (string)($profile['sender_display_name'] ?? $overrideId);
    $senderCharacterId = (string)($profile['sender_character_id'] ?? '');
  }
}

$msg = [
  'id' => telegraph_next_id('tg'),
  'created_at' => $now,
  'updated_at' => $now,
  'turn' => (string)$turnYear['turn'],
  'year' => (int)$turnYear['year'],
  'scope' => $scope,
  'delivery_mode' => 'instant',
  'sender' => [
    'sender_type' => $senderType,
    'sender_vk_user_id' => 0,
    'sender_entity_type' => $senderEntityType,
    'sender_entity_id' => $senderEntityId,
    'sender_character_id' => $senderCharacterId,
    'sender_display_name' => $senderDisplayName,
  ],
  'target' => [
    'target_type' => $targetType,
    'target_entity_type' => $targetEntityType,
    'target_entity_id' => $targetEntityId,
    'target_character_id' => '',
    'target_channel_id' => trim((string)($target['target_channel_id'] ?? '')),
  ],
  'visibility' => [
    'public_to_all' => $scope === 'public',
    'visible_to_sender' => true,
    'visible_to_target' => in_array($scope, ['private', 'diplomatic'], true),
    'visible_to_admin' => true,
  ],
  'source' => ['source_type' => 'web', 'source_chat_id' => 0, 'source_message_id' => ''],
  'content' => [
    'title' => $title,
    'body' => $body,
    'short_preview' => mb_substr($body, 0, 120),
    'tags' => telegraph_normalize_tags($payload['tags'] ?? []),
    'attachments' => is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [],
  ],
  'routing' => [
    'relay_to_vk_public_chat' => (bool)($payload['relay_to_vk_public_chat'] ?? false),
    'include_in_public_feed' => $scope === 'public',
    'include_in_entity_feed' => true,
    'include_in_chronicle_candidate' => (bool)($payload['include_in_chronicle_candidate'] ?? ($scope === 'public')),
  ],
  'moderation' => ['status' => $status, 'moderation_note' => '', 'moderated_by' => '', 'moderated_at' => ''],
  'game_hooks' => [
    'linked_order_id' => trim((string)($payload['linked_order_id'] ?? '')),
    'linked_verdict_id' => trim((string)($payload['linked_verdict_id'] ?? '')),
    'linked_event_ids' => is_array($payload['linked_event_ids'] ?? null) ? $payload['linked_event_ids'] : [],
    'linked_diplomacy_thread_id' => trim((string)($payload['linked_diplomacy_thread_id'] ?? '')),
    'linked_war_id' => trim((string)($payload['linked_war_id'] ?? '')),
  ],
  'delivery' => [
    'read_by_sender' => true,
    'read_by_target' => false,
    'unread_counter_keys' => [],
    'delivered_to_vk' => [],
  ],
  'npc_hooks' => ['npc_reaction_status' => 'pending', 'llm_context_exported_at' => '', 'reaction_candidates' => []],
];

$store = telegraph_load_messages_store();
$store['messages'][] = $msg;
telegraph_save_messages_store($store);
telegraph_response(['ok' => true, 'message' => $msg], 201);
