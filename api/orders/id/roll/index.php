<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/lib/orders_api.php';
$admin = orders_api_require_admin();
$id = trim((string)($_GET['id'] ?? ''));
$p = orders_api_request_payload();
$itemId = trim((string)($p['order_action_item_id'] ?? ''));
$modifier = max(-9, min(9, (int)($p['modifier'] ?? 0)));
if ($id === '' || $itemId === '') orders_api_response(['error'=>'id_and_item_required'],400);
$store = orders_api_load_store();
$idx = orders_api_find_index($store['orders'], $id);
if ($idx < 0) orders_api_response(['error'=>'not_found'],404);
$order = $store['orders'][$idx];
orders_api_assert_version($order, $p, true);
$foundItem = false;
foreach (($order['action_items'] ?? []) as $ai) {
  if ((string)($ai['id'] ?? '') === $itemId) { $foundItem = true; break; }
}
if (!$foundItem) orders_api_response(['error'=>'item_not_found'],404);
if (!is_array($order['verdict'] ?? null)) $order['verdict'] = ['id'=>orders_api_next_id('ver'),'order_id'=>$id,'admin_user_id'=>$admin,'public_verdict_text'=>'','private_notes'=>'','clarification_request_text'=>'','finalized_at'=>'','published_at'=>'','rolls'=>[]];
$rolls = is_array($order['verdict']['rolls'] ?? null) ? $order['verdict']['rolls'] : [];
foreach ($rolls as $existing) {
  if ((string)($existing['order_action_item_id'] ?? '') === $itemId && (bool)($existing['locked'] ?? false)) orders_api_response(['error'=>'roll_locked'],409);
}
$raw = random_int(1,20); $total = $raw + $modifier;
$tier = (string)($p['outcome_tier_override'] ?? orders_api_tier_for_total($total));
$roll = ['id'=>orders_api_next_id('vr'),'order_action_item_id'=>$itemId,'dice'=>'d20','roll_raw'=>$raw,'modifier'=>$modifier,'total'=>$total,'outcome_tier'=>$tier,'locked'=>true,'rolled_by'=>$admin,'rolled_at'=>gmdate('c')];
$replaced = false;
foreach ($rolls as $k => $row) {
  if ((string)($row['order_action_item_id'] ?? '') !== $itemId) continue;
  $rolls[$k] = $roll; $replaced = true;
}
if (!$replaced) $rolls[] = $roll;
$order['verdict']['rolls'] = $rolls;
$order['status'] = 'pending_review';
$order['version'] = (int)$order['version'] + 1;
$order['updated_at'] = gmdate('c');
orders_api_audit_append($order,'order_roll_locked',$admin,['item_id'=>$itemId,'raw'=>$raw,'modifier'=>$modifier,'tier'=>$tier]);
orders_api_event_append('order_roll_locked',$id,['item_id'=>$itemId]);
$store['orders'][$idx] = $order;
orders_api_save_store($store);
orders_api_response(['ok'=>true,'roll'=>$roll,'order'=>$order]);
