<?php

declare(strict_types=1);

function diplomacy_next_id(string $prefix): string { return $prefix . '_' . substr(hash('sha256', microtime(true) . '|' . random_int(1, PHP_INT_MAX)), 0, 14); }
function diplomacy_now(): string { return gmdate('c'); }

function diplomacy_normalize_entity(string $type, string $id): array { return ['entity_type' => trim($type), 'entity_id' => trim($id)]; }

function diplomacy_actor(array $state): array {
  $adminToken = trim((string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''));
  if ($adminToken !== '' && in_array($adminToken, orders_api_admin_tokens(), true)) return ['role' => 'admin', 'entity_type' => 'admin', 'entity_id' => 'admin', 'entity_name' => 'Администратор'];
  $token = player_admin_token_from_request();
  if ($token !== '') {
    $session = player_admin_resolve_session($state, $token);
    if (is_array($session)) return ['role' => 'player', 'entity_type' => (string)$session['entity_type'], 'entity_id' => (string)$session['entity_id'], 'entity_name' => (string)($session['entity_name'] ?? $session['entity_id'])];
  }
  return ['role' => 'public', 'entity_type' => '', 'entity_id' => '', 'entity_name' => ''];
}

function diplomacy_require_actor(array $state): array {
  $actor = diplomacy_actor($state);
  if (($actor['role'] ?? 'public') === 'public') diplomacy_response(['error' => 'auth_required'], 403);
  return $actor;
}

function diplomacy_participant_match(array $thread, string $type, string $id): bool {
  foreach ((array)($thread['participants'] ?? []) as $p) {
    if (!is_array($p)) continue;
    if ((string)($p['entity_type'] ?? '') === $type && (string)($p['entity_id'] ?? '') === $id) return true;
  }
  return false;
}

function diplomacy_participant_row(array $thread, string $type, string $id): ?array {
  foreach ((array)($thread['participants'] ?? []) as $p) {
    if (!is_array($p)) continue;
    if ((string)($p['entity_type'] ?? '') === $type && (string)($p['entity_id'] ?? '') === $id) return $p;
  }
  return null;
}

function diplomacy_actor_can(array $thread, array $actor, string $capability): bool {
  if (($actor['role'] ?? '') === 'admin') return true;
  $row = diplomacy_participant_row($thread, (string)($actor['entity_type'] ?? ''), (string)($actor['entity_id'] ?? ''));
  if (!is_array($row)) return false;
  $map = ['send' => 'can_send', 'propose' => 'can_propose', 'ratify' => 'can_ratify', 'edit_draft' => 'can_edit_draft'];
  $key = $map[$capability] ?? '';
  if ($key === '') return false;
  return (bool)($row[$key] ?? false);
}

function diplomacy_thread_visible(array $thread, array $actor): bool {
  if (($actor['role'] ?? '') === 'admin') return true;
  $vis = (string)($thread['visibility'] ?? 'participants_only');
  if ($vis === 'public_summary') return true;
  return diplomacy_participant_match($thread, (string)$actor['entity_type'], (string)$actor['entity_id']);
}

function diplomacy_entity_label(array $state, string $type, string $id): string {
  foreach (['kingdoms', 'great_houses', 'minor_houses', 'free_cities', 'special_territories'] as $bucket) {
    if ($bucket !== $type) continue;
    $row = $state[$bucket][$id] ?? null;
    if (is_array($row)) return (string)($row['name'] ?? $id);
  }
  return $type . ':' . $id;
}

function diplomacy_resolve_entity(array $state, string $needle): ?array {
  $needle = trim($needle);
  if ($needle === '') return null;
  if (strpos($needle, ':') !== false) {
    [$t, $id] = array_pad(explode(':', $needle, 2), 2, '');
    if ($t !== '' && $id !== '') return ['entity_type' => trim($t), 'entity_id' => trim($id), 'name' => diplomacy_entity_label($state, trim($t), trim($id))];
  }
  $lc = mb_strtolower($needle, 'UTF-8');
  foreach (['kingdoms', 'great_houses', 'minor_houses', 'free_cities', 'special_territories'] as $bucket) {
    foreach (($state[$bucket] ?? []) as $id => $row) {
      if (!is_array($row)) continue;
      $name = trim((string)($row['name'] ?? $id));
      $alias = trim((string)($row['short_code'] ?? ''));
      if ((string)$id === $needle || mb_strtolower((string)$id, 'UTF-8') === $lc || mb_strtolower($name, 'UTF-8') === $lc || ($alias !== '' && mb_strtolower($alias, 'UTF-8') === $lc)) {
        return ['entity_type' => $bucket, 'entity_id' => (string)$id, 'name' => $name];
      }
    }
  }
  return null;
}

function diplomacy_paginate(array $rows, int $page, int $perPage): array {
  $total = count($rows);
  $page = max(1, $page); $perPage = max(1, min(200, $perPage));
  return ['items' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'page' => $page, 'per_page' => $perPage, 'total' => $total];
}

function diplomacy_target_from_thread(array $thread, array $actor): array {
  foreach ((array)($thread['participants'] ?? []) as $p) {
    if (!is_array($p)) continue;
    $type = (string)($p['entity_type'] ?? '');
    $id = (string)($p['entity_id'] ?? '');
    if ($type === (string)($actor['entity_type'] ?? '') && $id === (string)($actor['entity_id'] ?? '')) continue;
    return ['target_type' => 'entity', 'target_entity_type' => $type, 'target_entity_id' => $id, 'target_character_id' => '', 'target_channel_id' => ''];
  }
  return ['target_type' => 'entity', 'target_entity_type' => '', 'target_entity_id' => '', 'target_character_id' => '', 'target_channel_id' => ''];
}

function diplomacy_to_telegraph(array $message, array $actor, array $payload, ?array $thread = null): string {
  $store = telegraph_load_messages_store();
  $now = diplomacy_now();
  $attachments = (array)($message['attachments'] ?? []);
  if (isset($payload['proposal_attachment']) && is_array($payload['proposal_attachment'])) $attachments[] = ['type' => 'diplomatic_proposal', 'payload' => $payload['proposal_attachment']];
  $target = is_array($thread) ? diplomacy_target_from_thread($thread, $actor) : ['target_type' => 'entity', 'target_entity_type' => trim((string)($payload['target_entity_type'] ?? '')), 'target_entity_id' => trim((string)($payload['target_entity_id'] ?? '')), 'target_character_id' => '', 'target_channel_id' => ''];
  $tg = [
    'id' => telegraph_next_id('tg'), 'created_at' => $now, 'updated_at' => $now, 'turn' => 'turn_unknown', 'year' => 0,
    'scope' => 'diplomatic', 'delivery_mode' => 'instant',
    'sender' => ['sender_type' => 'entity', 'sender_vk_user_id' => 0, 'sender_entity_type' => (string)$actor['entity_type'], 'sender_entity_id' => (string)$actor['entity_id'], 'sender_character_id' => (string)($payload['sender_character_id'] ?? ''), 'sender_display_name' => (string)($actor['entity_name'] ?? '')],
    'target' => $target,
    'visibility' => ['public_to_all' => false, 'visible_to_sender' => true, 'visible_to_target' => true, 'visible_to_admin' => true],
    'source' => ['source_type' => (string)($message['source_type'] ?? 'web'), 'source_chat_id' => 0, 'source_message_id' => ''],
    'content' => ['title' => '', 'body' => (string)($message['body'] ?? ''), 'short_preview' => mb_substr((string)($message['body'] ?? ''), 0, 120), 'tags' => (array)($message['tags'] ?? []), 'attachments' => $attachments],
    'routing' => ['relay_to_vk_public_chat' => false, 'include_in_public_feed' => false, 'include_in_entity_feed' => true, 'include_in_chronicle_candidate' => false],
    'moderation' => ['status' => 'approved', 'moderation_note' => '', 'moderated_by' => '', 'moderated_at' => ''],
    'game_hooks' => ['linked_order_id' => (string)($message['linked_order_id'] ?? ''), 'linked_verdict_id' => (string)($message['linked_verdict_id'] ?? ''), 'linked_event_ids' => [], 'linked_diplomacy_thread_id' => (string)($message['thread_id'] ?? ''), 'linked_war_id' => (string)($message['linked_war_id'] ?? '')],
    'delivery' => ['read_by_sender' => true, 'read_by_target' => false, 'unread_counter_keys' => [], 'delivered_to_vk' => []],
    'npc_hooks' => ['npc_reaction_status' => 'pending', 'llm_context_exported_at' => '', 'reaction_candidates' => []],
  ];
  $store['messages'][] = $tg;
  telegraph_save_messages_store($store);
  return (string)$tg['id'];
}
