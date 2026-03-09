<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/orders_api.php';
$state = api_load_state();
$session = orders_api_require_player_session($state);
$store = orders_api_load_store();
$out = [];
foreach ($store['orders'] as $o) {
  if (!is_array($o)) continue;
  if ((string)($o['entity_type'] ?? '') !== (string)$session['entity_type']) continue;
  if ((string)($o['entity_id'] ?? '') !== (string)$session['entity_id']) continue;
  $out[] = $o;
}
orders_api_response(['ok'=>true,'items'=>$out]);
