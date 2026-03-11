<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

telegraph_require_feature();
$state = api_load_state();
$actor = telegraph_actor_from_request($state);
$store = telegraph_load_messages_store();
$messages = array_values(array_filter($store['messages'], static fn($m) => is_array($m)));

$scope = trim((string)($_GET['scope'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$sourceType = trim((string)($_GET['source_type'] ?? ''));
$threadId = trim((string)($_GET['thread_id'] ?? ''));
$entityTypeFilter = trim((string)($_GET['entity_type'] ?? ''));
$entityIdFilter = trim((string)($_GET['entity_id'] ?? ''));
$q = mb_strtolower(trim((string)($_GET['q'] ?? '')), 'UTF-8');
$linkedOrderId = trim((string)($_GET['linked_order_id'] ?? ''));
$linkedVerdictId = trim((string)($_GET['linked_verdict_id'] ?? ''));
$unreadOnly = (string)($_GET['unread_only'] ?? '') === '1';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));

$out = [];
foreach ($messages as $msg) {
  if (!telegraph_message_visible_for($msg, $actor)) continue;
  if ($scope !== '' && (string)($msg['scope'] ?? '') !== $scope) continue;
  if ($status !== '' && (string)($msg['moderation']['status'] ?? '') !== $status) continue;
  if ($sourceType !== '' && (string)($msg['source']['source_type'] ?? '') !== $sourceType) continue;
  if ($threadId !== '' && (string)($msg['game_hooks']['linked_diplomacy_thread_id'] ?? '') !== $threadId) continue;
  if ($entityTypeFilter !== '' || $entityIdFilter !== '') {
    $senderMatch = ($entityTypeFilter === '' || (string)($msg['sender']['sender_entity_type'] ?? '') === $entityTypeFilter)
      && ($entityIdFilter === '' || (string)($msg['sender']['sender_entity_id'] ?? '') === $entityIdFilter);
    $targetMatch = ($entityTypeFilter === '' || (string)($msg['target']['target_entity_type'] ?? '') === $entityTypeFilter)
      && ($entityIdFilter === '' || (string)($msg['target']['target_entity_id'] ?? '') === $entityIdFilter);
    if (!$senderMatch && !$targetMatch) continue;
  }
  if ($linkedOrderId !== '' && (string)($msg['game_hooks']['linked_order_id'] ?? '') !== $linkedOrderId) continue;
  if ($linkedVerdictId !== '' && (string)($msg['game_hooks']['linked_verdict_id'] ?? '') !== $linkedVerdictId) continue;
  if ($q !== '') {
    $hay = mb_strtolower(
      (string)($msg['content']['title'] ?? '') . ' ' . (string)($msg['content']['body'] ?? '') . ' ' . (string)($msg['sender']['sender_display_name'] ?? ''),
      'UTF-8'
    );
    if (mb_strpos($hay, $q) === false) continue;
  }
  if ($unreadOnly) {
    $entityType = (string)($actor['entity_type'] ?? '');
    $entityId = (string)($actor['entity_id'] ?? '');
    $target = ((string)($msg['target']['target_entity_type'] ?? '') === $entityType && (string)($msg['target']['target_entity_id'] ?? '') === $entityId);
    if ($target && (bool)($msg['delivery']['read_by_target'] ?? false)) continue;
  }
  $out[] = $msg;
}
usort($out, static fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
$total = count($out);
$offset = ($page - 1) * $perPage;
$rows = array_slice($out, $offset, $perPage);

telegraph_response(['ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'actor' => $actor]);
