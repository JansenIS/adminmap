<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

telegraph_require_feature();
$adminActor = orders_api_require_admin();
$payload = telegraph_request_payload();
$id = trim((string)($payload['id'] ?? ''));
$action = trim((string)($payload['action'] ?? ''));
if ($id === '' || !in_array($action, ['approve', 'reject', 'needs_clarification'], true)) {
  telegraph_response(['error' => 'invalid_payload'], 400);
}
$status = $action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'needs_clarification');

$store = telegraph_load_messages_store();
$found = null;
foreach ($store['messages'] as &$msg) {
  if (!is_array($msg) || (string)($msg['id'] ?? '') !== $id) continue;
  $msg['moderation']['status'] = $status;
  $msg['moderation']['moderation_note'] = mb_substr(trim((string)($payload['moderation_note'] ?? '')), 0, 400);
  $msg['moderation']['moderated_by'] = $adminActor;
  $msg['moderation']['moderated_at'] = telegraph_now_iso();
  $msg['updated_at'] = telegraph_now_iso();
  $found = $msg;
}
unset($msg);
if (!is_array($found)) telegraph_response(['error' => 'not_found'], 404);
telegraph_save_messages_store($store);
telegraph_response(['ok' => true, 'message' => $found]);
