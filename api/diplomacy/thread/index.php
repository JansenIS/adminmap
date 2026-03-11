<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

diplomacy_require_feature();
$state = api_load_state();
$actor = diplomacy_actor($state);
$threadId = trim((string)($_GET['thread_id'] ?? ''));
if ($threadId === '') diplomacy_response(['error' => 'thread_id_required'], 400);
$threadStore = diplomacy_load_threads_store();
$messageStore = diplomacy_load_messages_store();
$proposalStore = diplomacy_load_proposals_store();
$thread = null;
foreach ($threadStore['threads'] as $row) if (is_array($row) && (string)($row['id'] ?? '') === $threadId) { $thread = $row; break; }
if (!is_array($thread)) diplomacy_response(['error' => 'not_found'], 404);
if (!diplomacy_thread_visible($thread, $actor)) diplomacy_response(['error' => 'forbidden'], 403);

if (($actor['role'] ?? '') !== 'admin') {
  $key = (string)$actor['entity_type'] . ':' . (string)$actor['entity_id'];
  if ((int)($thread['unread_counters'][$key] ?? 0) > 0) {
    foreach ($threadStore['threads'] as $i => $row) {
      if (!is_array($row) || (string)($row['id'] ?? '') !== $threadId) continue;
      $row['unread_counters'][$key] = 0;
      $row['updated_at'] = diplomacy_now();
      $threadStore['threads'][$i] = $row;
      $thread = $row;
      break;
    }
    diplomacy_save_threads_store($threadStore);
  }
}

$messages = array_values(array_filter($messageStore['messages'], static fn($m) => is_array($m) && (string)($m['thread_id'] ?? '') === $threadId));
$proposals = array_values(array_filter($proposalStore['proposals'], static fn($p) => is_array($p) && (string)($p['thread_id'] ?? '') === $threadId));
diplomacy_response(['ok' => true, 'thread' => $thread, 'messages' => $messages, 'proposals' => $proposals]);
