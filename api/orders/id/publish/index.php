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
if (!in_array((string)($order['status'] ?? ''), ['approved','verdict_ready'], true)) orders_api_response(['error'=>'publish_not_allowed_for_status','status'=>$order['status'] ?? ''],409);
$turnResolved = orders_api_turn_year_from_turn_mechanics();
if (!(bool)($turnResolved['ok'] ?? false)) orders_api_response(['error'=>(string)($turnResolved['error'] ?? 'turn_year_unavailable'),'message'=>(string)($turnResolved['message'] ?? 'Не удалось определить год хода из turn-механики'),'details'=>$turnResolved['details'] ?? []], 409);
$turnYear = (int)($turnResolved['year'] ?? 0);
if ((int)($order['turn_year'] ?? 0) !== $turnYear) {
  orders_api_response([
    'error' => 'order_turn_mismatch',
    'message' => 'Публикация запрещена: год приказа не совпадает с актуальным годом turn-механики. Исправьте приказ или создайте корректный ход.',
    'details' => ['order_turn_year' => (int)($order['turn_year'] ?? 0), 'turn_mechanics_year' => $turnYear],
  ], 409);
}
$pub = orders_api_publish($order, $admin);
if (is_array($order['verdict'] ?? null)) $order['verdict']['published_at'] = gmdate('c');
$order['status'] = 'published';
$order['version'] = (int)$order['version'] + 1;
$order['updated_at'] = gmdate('c');
orders_api_audit_append($order,'order_published',$admin,['feed_id'=>$pub['id'] ?? '']);
$store['orders'][$idx] = $order;
orders_api_save_store($store);
orders_api_emit_order_etag($order);
orders_api_response(['ok'=>true,'publication'=>$pub,'order'=>$order,'etag'=>orders_api_order_etag($order)]);
