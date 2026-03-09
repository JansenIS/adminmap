<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/orders_api.php';
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$store = orders_api_load_store();
$state = api_load_state();
if ($method === 'GET') {
  $isAdmin = trim((string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '')) !== '' && in_array(trim((string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '')), orders_api_admin_tokens(), true);
  $owner = null;
  if (!$isAdmin) {
    $owner = orders_api_require_player_session($state);
  }
  $rows = [];
  foreach ($store['orders'] as $o) {
    if (!is_array($o)) continue;
    if (!$isAdmin && is_array($owner)) {
      if ((string)($o['entity_type'] ?? '') !== (string)$owner['entity_type'] || (string)($o['entity_id'] ?? '') !== (string)$owner['entity_id']) continue;
    }
    $rows[] = orders_api_filter_public($o, $isAdmin, true);
  }
  orders_api_response(['ok' => true, 'items' => $rows]);
}
if ($method === 'POST') {
  $session = orders_api_require_player_session($state);
  $p = orders_api_request_payload();
  $year = (int)($p['turn_year'] ?? orders_api_current_turn_year());
  $id = orders_api_next_id('ord');
  $items = [];
  foreach (($p['action_items'] ?? []) as $i => $it) {
    if (!is_array($it)) continue;
    $items[] = [
      'id' => orders_api_next_id('ai'),
      'order_id' => $id,
      'sort_index' => (int)($it['sort_index'] ?? ($i + 1)),
      'category' => (string)($it['category'] ?? 'other'),
      'summary' => mb_substr(trim((string)($it['summary'] ?? '')), 0, 300),
      'details' => mb_substr(trim((string)($it['details'] ?? '')), 0, 3000),
      'requested_effects_hint' => mb_substr(trim((string)($it['requested_effects_hint'] ?? '')), 0, 500),
      'target_scope' => mb_substr(trim((string)($it['target_scope'] ?? '')), 0, 80),
    ];
  }
  $order = [
    'id' => $id,'turn_year'=>$year,'turn_id'=>'turn_'.$year,'entity_type'=>(string)$session['entity_type'],'entity_id'=>(string)$session['entity_id'],'character_id'=>null,
    'author_user_id' => 'player_token','author_vk_user_id'=>0,'source'=>(string)($p['source'] ?? 'web'),'title'=>mb_substr(trim((string)($p['title'] ?? 'Безымянный приказ')),0,200),
    'rp_post'=>mb_substr(trim((string)($p['rp_post'] ?? '')),0,20000),
    'public_images'=>orders_api_normalize_attachment_list($p['public_images'] ?? [], 'public'),
    'private_attachments'=>orders_api_normalize_attachment_list($p['private_attachments'] ?? [], 'private'),
    'status'=>'draft','created_at'=>gmdate('c'),'updated_at'=>gmdate('c'),'submitted_at'=>'','version'=>1,
    'action_items'=>$items,'verdict'=>null,'effects'=>[],'publication'=>null,'audit_log'=>[],
    'attachment_registry'=>[],
  ];
  $order['attachment_registry'] = orders_api_build_attachment_registry($order);
  orders_api_audit_append($order, 'order_created', 'player:' . $session['entity_id'], []);
  $store['orders'][] = $order;
  orders_api_save_store($store);
  orders_api_response(['ok'=>true,'order'=>$order], 201);
}
orders_api_response(['error' => 'method_not_allowed'], 405);
