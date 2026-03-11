<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

telegraph_require_feature();
$state = api_load_state();
$actor = telegraph_actor_from_request($state);
$store = telegraph_load_messages_store();
$messages = array_values(array_filter($store['messages'], static fn($m) => is_array($m) && telegraph_message_visible_for($m, $actor)));

$orderId = trim((string)($_GET['order_id'] ?? ''));
$verdictId = trim((string)($_GET['verdict_id'] ?? ''));

if ($orderId !== '' || $verdictId !== '') {
  $rows = [];
  foreach ($messages as $msg) {
    if ($orderId !== '' && (string)($msg['game_hooks']['linked_order_id'] ?? '') !== $orderId) continue;
    if ($verdictId !== '' && (string)($msg['game_hooks']['linked_verdict_id'] ?? '') !== $verdictId) continue;
    $rows[] = $msg;
  }
  usort($rows, static fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
  telegraph_response(['ok' => true, 'order_id' => $orderId, 'verdict_id' => $verdictId, 'rows' => array_slice($rows, 0, 50)]);
}


$summary = [];
$verdictSummary = [];
foreach ($messages as $msg) {
  $oid = trim((string)($msg['game_hooks']['linked_order_id'] ?? ''));
  if ($oid === '') continue;
  if (!isset($summary[$oid])) {
    $summary[$oid] = ['order_id' => $oid, 'total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'needs_clarification' => 0];
  }
  $summary[$oid]['total']++;
  $st = (string)($msg['moderation']['status'] ?? '');
  if (isset($summary[$oid][$st])) $summary[$oid][$st]++;

  $vid = trim((string)($msg['game_hooks']['linked_verdict_id'] ?? ''));
  if ($vid !== '') {
    if (!isset($verdictSummary[$vid])) {
      $verdictSummary[$vid] = ['verdict_id' => $vid, 'total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'needs_clarification' => 0];
    }
    $verdictSummary[$vid]['total']++;
    if (isset($verdictSummary[$vid][$st])) $verdictSummary[$vid][$st]++;
  }
}

telegraph_response(['ok' => true, 'items' => array_values($summary), 'verdict_items' => array_values($verdictSummary)]);
