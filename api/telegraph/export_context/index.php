<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

telegraph_require_feature();
$state = api_load_state();
$actor = telegraph_actor_from_request($state);
$store = telegraph_load_messages_store();
$rows = [];
foreach ($store['messages'] as $msg) {
  if (!is_array($msg) || !telegraph_message_visible_for($msg, $actor)) continue;
  $rows[] = [
    'id' => (string)($msg['id'] ?? ''),
    'scope' => (string)($msg['scope'] ?? ''),
    'title' => (string)($msg['content']['title'] ?? ''),
    'body' => (string)($msg['content']['body'] ?? ''),
    'created_at' => (string)($msg['created_at'] ?? ''),
    'sender' => (string)($msg['sender']['sender_display_name'] ?? ''),
    'thread' => (string)($msg['game_hooks']['linked_diplomacy_thread_id'] ?? ''),
    'npc_reaction_status' => (string)($msg['npc_hooks']['npc_reaction_status'] ?? 'pending'),
  ];
}
telegraph_response(['ok' => true, 'rows' => array_slice($rows, -200), 'exported_at' => telegraph_now_iso()]);
