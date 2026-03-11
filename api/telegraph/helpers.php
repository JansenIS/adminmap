<?php

declare(strict_types=1);

function telegraph_next_id(string $prefix): string {
  return $prefix . '_' . substr(hash('sha256', microtime(true) . '|' . random_int(1, PHP_INT_MAX)), 0, 14);
}

function telegraph_now_iso(): string { return gmdate('c'); }

function telegraph_turn_year(): array {
  $row = orders_api_turn_year_from_turn_mechanics();
  if (($row['ok'] ?? false) === true) {
    return ['turn' => (string)($row['turn']['id'] ?? ('turn_' . (int)($row['year'] ?? 0))), 'year' => (int)($row['year'] ?? 0)];
  }
  return ['turn' => 'turn_unknown', 'year' => 0];
}

function telegraph_normalize_tags($tags): array {
  $src = is_array($tags) ? $tags : preg_split('/[,;\n]/u', (string)$tags);
  $out = [];
  foreach ((array)$src as $tag) {
    $v = mb_substr(trim((string)$tag), 0, 40);
    if ($v !== '') $out[$v] = true;
  }
  return array_keys($out);
}

function telegraph_actor_from_request(array $state): array {
  $adminToken = trim((string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''));
  if ($adminToken !== '' && in_array($adminToken, orders_api_admin_tokens(), true)) {
    return ['role' => 'admin', 'sender_type' => 'admin', 'sender_display_name' => 'Администратор', 'entity_type' => 'admin', 'entity_id' => 'admin'];
  }
  $playerToken = player_admin_token_from_request();
  if ($playerToken !== '') {
    $session = player_admin_resolve_session($state, $playerToken);
    if (is_array($session)) {
      return [
        'role' => 'player',
        'sender_type' => 'entity',
        'sender_display_name' => (string)($session['entity_name'] ?? (($session['entity_type'] ?? '') . ':' . ($session['entity_id'] ?? ''))),
        'entity_type' => (string)($session['entity_type'] ?? ''),
        'entity_id' => (string)($session['entity_id'] ?? ''),
      ];
    }
  }
  return ['role' => 'public', 'sender_type' => 'system', 'sender_display_name' => 'Публичный просмотр', 'entity_type' => '', 'entity_id' => ''];
}

function telegraph_resolve_vk_sender_entity(int $vkUserId): ?array {
  $apps = vk_bot_load_applications();
  foreach ($apps as $app) {
    if (!is_array($app)) continue;
    if ((int)($app['vk_user_id'] ?? 0) !== $vkUserId) continue;
    if (($app['status'] ?? '') !== 'approved') continue;
    $resolved = vk_bot_resolve_application_entity($app);
    if (!is_array($resolved)) continue;
    return [
      'entity_type' => (string)$resolved['entity_type'],
      'entity_id' => (string)$resolved['entity_id'],
      'entity_name' => (string)($resolved['name'] ?? ((string)$resolved['entity_id'])),
    ];
  }
  return null;
}

function telegraph_resolve_target_entity(array $state, string $needle): ?array {
  $needle = trim($needle);
  if ($needle === '') return null;
  if (strpos($needle, ':') !== false) {
    [$t, $id] = array_pad(explode(':', $needle, 2), 2, '');
    $t = trim($t); $id = trim($id);
    if ($t !== '' && $id !== '') return ['entity_type' => $t, 'entity_id' => $id, 'name' => $id];
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

function telegraph_message_visible_for(array $msg, array $actor): bool {
  if (($actor['role'] ?? '') === 'admin') return true;
  $scope = (string)($msg['scope'] ?? 'public');
  if ($scope === 'public' || ($scope === 'system' && (bool)($msg['visibility']['public_to_all'] ?? false))) return true;
  $entityType = (string)($actor['entity_type'] ?? '');
  $entityId = (string)($actor['entity_id'] ?? '');
  if ($entityType === '' || $entityId === '') return false;
  $senderOk = ((string)($msg['sender']['sender_entity_type'] ?? '') === $entityType && (string)($msg['sender']['sender_entity_id'] ?? '') === $entityId);
  $targetOk = ((string)($msg['target']['target_entity_type'] ?? '') === $entityType && (string)($msg['target']['target_entity_id'] ?? '') === $entityId);
  return $senderOk || $targetOk;
}

function telegraph_unread_counts(array $messages, array $actor): array {
  $counts = ['total' => 0, 'public' => 0, 'private' => 0, 'diplomatic' => 0, 'system' => 0, 'admin' => 0];
  $entityType = (string)($actor['entity_type'] ?? '');
  $entityId = (string)($actor['entity_id'] ?? '');
  foreach ($messages as $msg) {
    if (!is_array($msg) || !telegraph_message_visible_for($msg, $actor)) continue;
    $scope = (string)($msg['scope'] ?? 'public');
    $read = true;
    if ($entityType !== '' && $entityId !== '') {
      $target = ((string)($msg['target']['target_entity_type'] ?? '') === $entityType && (string)($msg['target']['target_entity_id'] ?? '') === $entityId);
      $sender = ((string)($msg['sender']['sender_entity_type'] ?? '') === $entityType && (string)($msg['sender']['sender_entity_id'] ?? '') === $entityId);
      if ($target) $read = (bool)($msg['delivery']['read_by_target'] ?? false);
      if ($sender) $read = (bool)($msg['delivery']['read_by_sender'] ?? false);
    }
    if ($read) continue;
    $counts['total']++;
    if (isset($counts[$scope])) $counts[$scope]++;
  }
  return $counts;
}

function telegraph_collect_enabled_relay_channels(): array {
  $channels = telegraph_load_channels_store();
  $enabled = [];
  foreach (($channels['channels'] ?? []) as $ch) {
    if (!is_array($ch) || !(bool)($ch['enabled'] ?? false) || !(bool)($ch['relay_public'] ?? false)) continue;
    $enabled[] = (int)($ch['chat_id'] ?? 0);
  }
  return array_values(array_filter(array_unique($enabled), static fn($v) => $v > 0));
}

function telegraph_process_relay_queue(array $store, array $enabledChannels, int $limit = 20, ?string $specificMessageId = null): array {
  $processed = [];
  $count = 0;

  foreach ($store['messages'] as &$msg) {
    if ($count >= $limit) break;
    $msgId = trim((string)($msg['id'] ?? ''));
    if ($msgId === '') continue;
    if ($specificMessageId !== null && $specificMessageId !== '' && $msgId !== $specificMessageId) continue;
    if ((string)($msg['scope'] ?? '') !== 'public') continue;
    if ((string)($msg['moderation']['status'] ?? '') !== 'approved') continue;
    if (!(bool)($msg['routing']['relay_to_vk_public_chat'] ?? false)) continue;

    $sourceChatId = (int)($msg['source']['source_chat_id'] ?? 0);
    $title = trim((string)($msg['content']['title'] ?? ''));
    $body = trim((string)($msg['content']['body'] ?? ''));
    $text = '📨 Телеграф' . ($title !== '' ? (': ' . $title) : '') . "\n" . $body;

    $sent = 0;
    $errors = 0;
    foreach ($enabledChannels as $chatId) {
      if ($chatId <= 0 || $chatId === $sourceChatId) continue;
      if (telegraph_relay_already_done($msgId, $chatId)) continue;

      $cfg = vk_bot_load_config();
      if (trim((string)($cfg['access_token'] ?? '')) === '') {
        telegraph_append_relay_log(['message_id' => $msgId, 'chat_id' => $chatId, 'status' => 'error', 'error' => 'missing_access_token', 'source' => 'relay_worker']);
        $errors++;
        continue;
      }
      vk_bot_send_peer_message($chatId, $text);
      telegraph_append_relay_log(['message_id' => $msgId, 'chat_id' => $chatId, 'status' => 'sent', 'error' => '', 'source' => 'relay_worker']);
      $sent++;
      $msg['delivery']['delivered_to_vk'] = array_values(array_unique(array_merge((array)($msg['delivery']['delivered_to_vk'] ?? []), [$chatId])));
    }

    if (($sent + $errors) > 0) {
      $msg['updated_at'] = telegraph_now_iso();
      $processed[] = ['id' => $msgId, 'sent' => $sent, 'errors' => $errors];
      $count++;
    }
    if ($specificMessageId !== null && $specificMessageId !== '') break;
  }
  unset($msg);

  return ['store' => $store, 'processed' => $processed];
}
