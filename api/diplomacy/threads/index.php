<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

diplomacy_require_feature();
$state = api_load_state();
$actor = diplomacy_actor($state);
$store = diplomacy_load_threads_store();
$rows = [];
foreach ($store['threads'] as $thread) {
  if (!is_array($thread)) continue;
  if (!diplomacy_thread_visible($thread, $actor)) continue;
  if (trim((string)($_GET['status'] ?? '')) !== '' && (string)$thread['status'] !== (string)$_GET['status']) continue;
  if (trim((string)($_GET['kind'] ?? '')) !== '' && (string)$thread['kind'] !== (string)$_GET['kind']) continue;
  $participant = trim((string)($_GET['participant'] ?? ''));
  if ($participant !== '') {
    $resolved = diplomacy_resolve_entity($state, $participant);
    if (!is_array($resolved) || !diplomacy_participant_match($thread, (string)$resolved['entity_type'], (string)$resolved['entity_id'])) continue;
  }
  if ((int)($_GET['unread_only'] ?? 0) === 1) {
    $key = (string)$actor['entity_type'] . ':' . (string)$actor['entity_id'];
    if ((int)($thread['unread_counters'][$key] ?? 0) <= 0) continue;
  }
  $rows[] = $thread;
}
usort($rows, static fn($a, $b) => strcmp((string)($b['latest_message_at'] ?? ''), (string)($a['latest_message_at'] ?? '')));
$page = (int)($_GET['page'] ?? 1); $perPage = (int)($_GET['per_page'] ?? 50);
$pack = diplomacy_paginate($rows, $page, $perPage);
diplomacy_response(['ok' => true, 'threads' => $pack['items'], 'page' => $pack['page'], 'per_page' => $pack['per_page'], 'total' => $pack['total']]);
