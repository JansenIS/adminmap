<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

diplomacy_require_feature();
orders_api_require_admin();
$payload = diplomacy_request_payload();
$action = trim((string)($payload['action'] ?? ''));
if (!in_array($action, ['approve', 'reject', 'partially_approve', 'mark_violated', 'terminate', 'force_activate', 'close_thread'], true)) diplomacy_response(['error' => 'invalid_action'], 400);

if (trim((string)($payload['proposal_id'] ?? '')) !== '') {
  $store = diplomacy_load_proposals_store();
  foreach ($store['proposals'] as $idx => $p) {
    if (!is_array($p) || (string)($p['id'] ?? '') !== (string)$payload['proposal_id']) continue;
    if (in_array($action, ['approve', 'partially_approve', 'force_activate'], true)) $p['status'] = 'active';
    if ($action === 'reject') $p['status'] = 'rejected';
    $p['linked_verdict_id'] = trim((string)($payload['linked_verdict_id'] ?? ($p['linked_verdict_id'] ?? '')));
    $p['updated_at'] = diplomacy_now();
    $store['proposals'][$idx] = $p;
    diplomacy_save_proposals_store($store);
    diplomacy_response(['ok' => true, 'proposal' => $p]);
  }
}
if (trim((string)($payload['treaty_id'] ?? '')) !== '') {
  $store = diplomacy_load_treaties_store();
  foreach ($store['treaties'] as $idx => $t) {
    if (!is_array($t) || (string)($t['id'] ?? '') !== (string)$payload['treaty_id']) continue;
    if ($action === 'mark_violated') {
      $t['status'] = 'violated';
      $t['violation_log'][] = ['at' => diplomacy_now(), 'note' => (string)($payload['note'] ?? ''), 'linked_verdict_id' => trim((string)($payload['linked_verdict_id'] ?? ''))];
    }
    if ($action === 'terminate') $t['status'] = 'terminated';
    if ($action === 'force_activate') $t['status'] = 'active';
    $t['updated_at'] = diplomacy_now();
    $store['treaties'][$idx] = $t;
    diplomacy_save_treaties_store($store);
    diplomacy_response(['ok' => true, 'treaty' => $t]);
  }
}
if (trim((string)($payload['thread_id'] ?? '')) !== '') {
  $store = diplomacy_load_threads_store();
  foreach ($store['threads'] as $idx => $t) {
    if (!is_array($t) || (string)($t['id'] ?? '') !== (string)$payload['thread_id']) continue;
    if ($action === 'close_thread') $t['status'] = 'closed';
    if ($action === 'force_activate') $t['status'] = 'active';
    $t['linked_verdict_id'] = trim((string)($payload['linked_verdict_id'] ?? ($t['linked_verdict_id'] ?? '')));
    $t['updated_at'] = diplomacy_now();
    $store['threads'][$idx] = $t;
    diplomacy_save_threads_store($store);
    diplomacy_response(['ok' => true, 'thread' => $t]);
  }
}
diplomacy_response(['error' => 'target_not_found'], 404);
