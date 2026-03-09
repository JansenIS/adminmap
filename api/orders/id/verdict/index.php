<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/lib/orders_api.php';
$admin = orders_api_require_admin();
$id = trim((string)($_GET['id'] ?? ''));
$p = orders_api_request_payload();
if ($id === '') orders_api_response(['error'=>'id_required'],400);
orders_api_acquire_order_lock($id, $admin);
$store = orders_api_load_store();
$idx = orders_api_find_index($store['orders'], $id);
if ($idx < 0) orders_api_response(['error'=>'not_found'],404);
$order = $store['orders'][$idx];
orders_api_assert_version($order, $p, true);
if (!is_array($order['verdict'] ?? null)) $order['verdict'] = ['id'=>orders_api_next_id('ver'),'order_id'=>$id,'admin_user_id'=>$admin,'public_verdict_text'=>'','private_notes'=>'','clarification_request_text'=>'','finalized_at'=>'','published_at'=>'','rolls'=>[]];
$order['verdict']['public_verdict_text'] = mb_substr(trim((string)($p['public_verdict_text'] ?? '')),0,8000);
$order['verdict']['private_notes'] = mb_substr(trim((string)($p['private_notes'] ?? '')),0,8000);
$order['verdict']['admin_user_id'] = $admin;
if (isset($p['effects']) && is_array($p['effects'])) {
  $effects = [];
  foreach ($p['effects'] as $e) {
    if (!is_array($e)) continue;
    $effects[] = [
      'id' => (string)($e['id'] ?? orders_api_next_id('ef')),
      'order_id' => $id,
      'order_action_item_id' => (string)($e['order_action_item_id'] ?? ''),
      'effect_type' => (string)($e['effect_type'] ?? 'map_event_note'),
      'payload' => is_array($e['payload'] ?? null) ? $e['payload'] : ['note' => (string)($e['payload'] ?? '')],
      'is_enabled' => (bool)($e['is_enabled'] ?? true),
      'applied' => (bool)($e['applied'] ?? false),
      'applied_at' => (string)($e['applied_at'] ?? ''),
      'applied_by' => (string)($e['applied_by'] ?? ''),
    ];
  }
  $order['effects'] = $effects;
}
$order['status'] = 'verdict_ready';
$order['verdict']['finalized_at'] = gmdate('c');
$order['version'] = (int)$order['version'] + 1;
$order['updated_at'] = gmdate('c');
orders_api_audit_append($order,'verdict_saved',$admin,['effects_count'=>count($order['effects'] ?? [])]);
$store['orders'][$idx] = $order;
orders_api_save_store($store);
orders_api_emit_order_etag($order);
orders_api_response(['ok'=>true,'order'=>$order,'etag'=>orders_api_order_etag($order)]);
