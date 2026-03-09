<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/lib/orders_api.php';
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'PATCH' && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') orders_api_response(['error'=>'method_not_allowed'],405);
$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') orders_api_response(['error'=>'id_required'],400);
$payload = orders_api_request_payload();
$store = orders_api_load_store();
$idx = orders_api_find_index($store['orders'], $id);
if ($idx < 0) orders_api_response(['error'=>'not_found'],404);
$order = $store['orders'][$idx];
$state = api_load_state();
$session = orders_api_require_player_session($state);
if ((string)($order['entity_type'] ?? '') !== (string)$session['entity_type'] || (string)($order['entity_id'] ?? '') !== (string)$session['entity_id']) orders_api_response(['error'=>'forbidden'],403);
if (!in_array((string)($order['status'] ?? ''), ['draft','needs_clarification','submitted'], true)) orders_api_response(['error'=>'status_locked'],409);
$ver = (int)($payload['version'] ?? 0);
if ($ver > 0 && $ver !== (int)($order['version'] ?? 0)) orders_api_response(['error'=>'version_conflict','expected'=>$order['version']],409);
foreach (['title','rp_post'] as $f) if (isset($payload[$f])) $order[$f] = mb_substr(trim((string)$payload[$f]),0,20000);
if (isset($payload['public_images'])) $order['public_images'] = orders_api_normalize_attachment_list($payload['public_images'], 'public');
if (isset($payload['private_attachments'])) $order['private_attachments'] = orders_api_normalize_attachment_list($payload['private_attachments'], 'private');
if (isset($payload['action_items']) && is_array($payload['action_items'])) {
  $items = [];
  foreach ($payload['action_items'] as $i => $it) {
    if (!is_array($it)) continue;
    $items[] = ['id'=>(string)($it['id'] ?? orders_api_next_id('ai')),'order_id'=>$id,'sort_index'=>(int)($it['sort_index'] ?? ($i+1)),'category'=>(string)($it['category'] ?? 'other'),'summary'=>mb_substr(trim((string)($it['summary'] ?? '')),0,300),'details'=>mb_substr(trim((string)($it['details'] ?? '')),0,3000),'requested_effects_hint'=>mb_substr(trim((string)($it['requested_effects_hint'] ?? '')),0,500),'target_scope'=>mb_substr(trim((string)($it['target_scope'] ?? '')),0,120)];
  }
  $order['action_items'] = $items;
}
$order['attachment_registry'] = orders_api_build_attachment_registry($order);
$order['version'] = (int)($order['version'] ?? 0) + 1;
$order['updated_at'] = gmdate('c');
orders_api_audit_append($order,'order_updated','player:' . $session['entity_id'],['version'=>$order['version']]);
$store['orders'][$idx] = $order;
orders_api_save_store($store);
orders_api_response(['ok'=>true,'order'=>$order]);
