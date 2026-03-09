<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/lib/orders_api.php';
$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') orders_api_response(['error'=>'id_required'],400);
$store = orders_api_load_store();
$idx = orders_api_find_index($store['orders'], $id);
if ($idx < 0) orders_api_response(['error'=>'not_found'],404);
$order = $store['orders'][$idx];
$state = api_load_state();
$isAdmin = trim((string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '')) !== '' && in_array(trim((string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '')), orders_api_admin_tokens(), true);
if (!$isAdmin) {
  $session = orders_api_require_player_session($state);
  if ((string)($order['entity_type'] ?? '') !== (string)$session['entity_type'] || (string)($order['entity_id'] ?? '') !== (string)$session['entity_id']) {
    orders_api_response(['error'=>'forbidden'],403);
  }
}
orders_api_response(['ok'=>true,'order'=>orders_api_filter_public($order,$isAdmin,true)]);
