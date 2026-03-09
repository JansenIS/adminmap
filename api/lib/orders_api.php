<?php

declare(strict_types=1);

require_once __DIR__ . '/state_api.php';
require_once __DIR__ . '/player_admin_api.php';
require_once __DIR__ . '/turn_api.php';
require_once __DIR__ . '/vk_bot_api.php';

function orders_api_base_dir(): string { return api_repo_root() . '/data/orders'; }
function orders_api_orders_path(): string { return orders_api_base_dir() . '/orders.json'; }
function orders_api_events_path(): string { return orders_api_base_dir() . '/events.json'; }
function orders_api_publications_path(): string { return orders_api_base_dir() . '/publications.json'; }
function orders_api_outbox_path(): string { return orders_api_base_dir() . '/vk_outbox.json'; }
function orders_api_admin_tokens_path(): string { return api_repo_root() . '/data/admin_tokens.json'; }

function orders_api_ensure_store(): void {
  $base = orders_api_base_dir();
  if (!is_dir($base)) @mkdir($base, 0775, true);
  if (!is_file(orders_api_orders_path())) {
    api_atomic_write_json(orders_api_orders_path(), ['orders' => orders_api_seed_orders(), 'updated_at' => gmdate('c')]);
  }
  if (!is_file(orders_api_events_path())) api_atomic_write_json(orders_api_events_path(), ['events' => []]);
  if (!is_file(orders_api_publications_path())) api_atomic_write_json(orders_api_publications_path(), ['rows' => []]);
  if (!is_file(orders_api_outbox_path())) api_atomic_write_json(orders_api_outbox_path(), ['rows' => []]);
}

function orders_api_seed_orders(): array {
  $now = gmdate('c');
  return [
    [
      'id' => 'ord_seed_001','turn_year' => 1201,'turn_id' => 'turn_1201','entity_type' => 'great_houses','entity_id' => 'Герцогство Людендорф','character_id' => null,
      'author_user_id' => 'seed_player_1','author_vk_user_id' => 0,'source' => 'web','title' => 'Донесение о восстановлении северных пашен',
      'rp_post' => 'После бурь и мора дом собирает людей, чтобы вернуть пашни, наладить обозы и укрепить границы.',
      'public_images' => [],'private_attachments' => [],'status' => 'published','created_at' => $now,'updated_at' => $now,'submitted_at' => $now,'version' => 3,
      'action_items' => [
        ['id'=>'ai_seed_1','sort_index'=>1,'category'=>'economy','summary'=>'Осушить поймы и вернуть пашни','details'=>'Собрать артелей в уезде Северная Гать','requested_effects_hint'=>'income+','target_scope'=>'province'],
        ['id'=>'ai_seed_2','sort_index'=>2,'category'=>'military','summary'=>'Укрепить заставы','details'=>'Поставить дозоры на переправах','requested_effects_hint'=>'garrison+','target_scope'=>'province'],
      ],
      'verdict' => [
        'id'=>'ver_seed_1','order_id'=>'ord_seed_001','admin_user_id'=>'seed_admin','public_verdict_text'=>'Пашни восстановлены частично; заставы у переправ усилены.',
        'private_notes'=>'','clarification_request_text'=>'','finalized_at'=>$now,'published_at'=>$now,
        'rolls'=>[
          ['id'=>'vr_seed_1','order_action_item_id'=>'ai_seed_1','dice'=>'d20','roll_raw'=>14,'modifier'=>2,'total'=>16,'outcome_tier'=>'success','locked'=>true,'rolled_by'=>'seed_admin','rolled_at'=>$now],
          ['id'=>'vr_seed_2','order_action_item_id'=>'ai_seed_2','dice'=>'d20','roll_raw'=>11,'modifier'=>1,'total'=>12,'outcome_tier'=>'partial_success','locked'=>true,'rolled_by'=>'seed_admin','rolled_at'=>$now],
        ]
      ],
      'effects' => [
        ['id'=>'ef_seed_1','order_id'=>'ord_seed_001','order_action_item_id'=>'ai_seed_1','effect_type'=>'entity_income_delta','payload'=>['delta'=>120,'reason'=>'Восстановление пашен'],'is_enabled'=>true,'applied'=>true,'applied_at'=>$now,'applied_by'=>'seed_admin'],
      ],
      'publication' => ['id'=>'pub_seed_1','order_id'=>'ord_seed_001','event_feed_entry_id'=>'evt_seed_1','vk_wall_post_id'=>'wall_seed_1','public_payload_snapshot'=>[]],
      'audit_log' => [],
    ],
    [
      'id'=>'ord_seed_002','turn_year'=>1201,'turn_id'=>'turn_1201','entity_type'=>'free_cities','entity_id'=>'Вольный Город Ферран','character_id'=>null,
      'author_user_id'=>'seed_player_2','author_vk_user_id'=>12345,'source'=>'vk','title'=>'Донесение о торговом караване',
      'rp_post'=>'Городские старейшины отправляют караван в восточные степи и ищут договор с соседями.',
      'public_images'=>[],'private_attachments'=>[],'status'=>'needs_clarification','created_at'=>$now,'updated_at'=>$now,'submitted_at'=>$now,'version'=>2,
      'action_items'=>[['id'=>'ai_seed_3','sort_index'=>1,'category'=>'diplomacy','summary'=>'Заключить торговое соглашение','details'=>'С купцами степного тракта','requested_effects_hint'=>'treaty','target_scope'=>'treaty']],
      'verdict' => ['id'=>'ver_seed_2','order_id'=>'ord_seed_002','admin_user_id'=>'seed_admin','public_verdict_text'=>'','private_notes'=>'Нужны имена посредников','clarification_request_text'=>'Уточните, какой дом даёт гарантов и сроки пошлины.','finalized_at'=>'','published_at'=>'','rolls'=>[]],
      'effects'=>[],'publication'=>null,'audit_log'=>[]
    ],
  ];
}

function orders_api_load_store(): array {
  orders_api_ensure_store();
  $raw = @file_get_contents(orders_api_orders_path());
  $dec = is_string($raw) ? json_decode($raw, true) : null;
  if (!is_array($dec)) $dec = ['orders' => []];
  if (!is_array($dec['orders'] ?? null)) $dec['orders'] = [];
  return $dec;
}

function orders_api_save_store(array $store): bool {
  $store['updated_at'] = gmdate('c');
  return api_atomic_write_json(orders_api_orders_path(), $store);
}

function orders_api_response(array $payload, int $status = 200): void {
  $mt = max(api_state_mtime(), api_file_mtime(orders_api_orders_path()));
  api_json_response($payload, $status, $mt);
}

function orders_api_request_payload(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') return [];
  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) orders_api_response(['error' => 'invalid_json'], 400);
  return $decoded;
}

function orders_api_admin_tokens(): array {
  $raw = @file_get_contents(orders_api_admin_tokens_path());
  $dec = is_string($raw) ? json_decode($raw, true) : null;
  $tokens = [];
  if (is_array($dec)) {
    foreach ($dec as $t) {
      $v = trim((string)$t);
      if ($v !== '') $tokens[] = $v;
    }
  }
  if (empty($tokens)) $tokens[] = 'dev-admin-token';
  return $tokens;
}

function orders_api_require_admin(): string {
  $token = trim((string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''));
  if ($token === '' || !in_array($token, orders_api_admin_tokens(), true)) {
    orders_api_response(['error' => 'admin_auth_required'], 403);
  }
  return 'admin:' . substr(hash('sha1', $token), 0, 10);
}

function orders_api_require_player_session(array $state): array {
  $token = player_admin_token_from_request();
  if ($token === '') orders_api_response(['error' => 'player_token_required'], 400);
  $session = player_admin_resolve_session($state, $token);
  if (!is_array($session)) orders_api_response(['error' => 'invalid_or_expired_player_token'], 403);
  return $session;
}


function orders_api_payload_version(array $payload): int {
  $header = trim((string)($_SERVER['HTTP_IF_MATCH'] ?? ''));
  if ($header !== '') return (int)$header;
  return (int)($payload['version'] ?? 0);
}

function orders_api_assert_version(array $order, array $payload, bool $required = true): void {
  $provided = orders_api_payload_version($payload);
  $expected = (int)($order['version'] ?? 0);
  if ($required && $provided <= 0) orders_api_response(['error' => 'version_required', 'expected' => $expected], 409);
  if ($provided > 0 && $provided !== $expected) orders_api_response(['error' => 'version_conflict', 'expected' => $expected, 'provided' => $provided], 409);
}

function orders_api_next_id(string $prefix): string {
  return $prefix . '_' . substr(hash('sha256', microtime(true) . '|' . random_int(1, PHP_INT_MAX)), 0, 12);
}

function orders_api_find_index(array $orders, string $id): int {
  foreach ($orders as $i => $o) {
    if ((string)($o['id'] ?? '') === $id) return (int)$i;
  }
  return -1;
}

function orders_api_outcome_tiers(): array {
  return [
    ['max' => 1, 'tier' => 'catastrophic_failure'],
    ['min' => 2, 'max' => 6, 'tier' => 'failure'],
    ['min' => 7, 'max' => 10, 'tier' => 'weak_result'],
    ['min' => 11, 'max' => 14, 'tier' => 'partial_success'],
    ['min' => 15, 'max' => 19, 'tier' => 'success'],
    ['min' => 20, 'tier' => 'major_success'],
  ];
}

function orders_api_tier_for_total(int $total): string {
  foreach (orders_api_outcome_tiers() as $row) {
    $min = (int)($row['min'] ?? -999);
    $max = (int)($row['max'] ?? 999);
    if ($total >= $min && $total <= $max) return (string)$row['tier'];
  }
  return 'partial_success';
}

function orders_api_status_transition_allowed(string $from, string $to): bool {
  $map = [
    'draft' => ['submitted'],
    'submitted' => ['pending_review','needs_clarification','rejected'],
    'pending_review' => ['needs_clarification','rejected','verdict_ready'],
    'needs_clarification' => ['submitted','rejected'],
    'verdict_ready' => ['approved','published'],
    'approved' => ['published'],
    'rejected' => [],
    'published' => [],
  ];
  return in_array($to, $map[$from] ?? [], true);
}

function orders_api_audit_append(array &$order, string $eventType, string $actor, array $payload = []): void {
  if (!is_array($order['audit_log'] ?? null)) $order['audit_log'] = [];
  $order['audit_log'][] = [
    'id' => orders_api_next_id('audit'),
    'order_id' => (string)($order['id'] ?? ''),
    'event_type' => $eventType,
    'actor' => $actor,
    'payload' => $payload,
    'created_at' => gmdate('c'),
  ];
}

function orders_api_event_append(string $eventType, string $orderId, array $payload = []): void {
  $path = orders_api_events_path();
  $raw = @file_get_contents($path);
  $dec = is_string($raw) ? json_decode($raw, true) : null;
  if (!is_array($dec)) $dec = ['events' => []];
  if (!is_array($dec['events'] ?? null)) $dec['events'] = [];
  $dec['events'][] = ['id'=>orders_api_next_id('evt'),'event_type'=>$eventType,'order_id'=>$orderId,'payload'=>$payload,'created_at'=>gmdate('c')];
  api_atomic_write_json($path, $dec);
}

function orders_api_current_turn_year(): int {
  $index = turn_api_load_index();
  $turns = is_array($index['turns'] ?? null) ? $index['turns'] : [];
  $maxYear = 0;
  foreach ($turns as $row) {
    $y = (int)($row['year'] ?? 0);
    if ($y > $maxYear) $maxYear = $y;
  }
  return $maxYear > 0 ? $maxYear : (int)date('Y');
}

function orders_api_filter_public(array $order, bool $isAdmin = false, bool $isOwner = false): array {
  if ($isAdmin || $isOwner) return $order;
  $safe = $order;
  if (is_array($safe['verdict'] ?? null)) {
    unset($safe['verdict']['private_notes']);
    unset($safe['verdict']['clarification_request_text']);
  }
  if (is_array($safe['effects'] ?? null)) {
    foreach ($safe['effects'] as &$e) {
      if (!is_array($e)) continue;
      $e['payload'] = ['summary' => (string)($e['effect_type'] ?? '')];
    }
    unset($e);
  }
  return $safe;
}


function orders_api_turn_bucket(array &$turn, string $key): array {
  $bucket = $turn[$key] ?? [];
  if (!is_array($bucket)) $bucket = [];
  return $bucket;
}

function orders_api_apply_effect_to_state(array &$state, array &$turn, array $order, array $effect, string $adminUserId): void {
  $type = (string)($effect['effect_type'] ?? '');
  $payload = is_array($effect['payload'] ?? null) ? $effect['payload'] : [];
  $entityType = (string)($order['entity_type'] ?? '');
  $entityId = (string)($order['entity_id'] ?? '');
  $delta = (float)($payload['delta'] ?? 0);

  if ($type === 'treasury_delta') {
    if (!is_array($state[$entityType][$entityId] ?? null)) return;
    $cur = (float)($state[$entityType][$entityId]['treasury'] ?? 0);
    $state[$entityType][$entityId]['treasury'] = $cur + $delta;
    if (!is_array($turn['treasury_ledger'] ?? null)) $turn['treasury_ledger'] = [];
    $turn['treasury_ledger'][] = [
      'ts' => gmdate('c'),'order_id' => (string)$order['id'],'effect_id' => (string)($effect['id'] ?? ''),'by'=>$adminUserId,
      'entity_type'=>$entityType,'entity_id'=>$entityId,'delta'=>$delta,'reason'=>(string)($payload['reason'] ?? 'order_effect'),
    ];
    return;
  }

  if ($type === 'entity_income_delta') {
    if (!is_array($state[$entityType][$entityId] ?? null)) return;
    $cur = (float)($state[$entityType][$entityId]['income'] ?? 0);
    $state[$entityType][$entityId]['income'] = $cur + $delta;
    return;
  }

  if ($type === 'province_income_delta') {
    $pid = (string)($payload['pid'] ?? '');
    if ($pid === '' || !is_array($state['provinces'][$pid] ?? null)) return;
    $cur = (float)($state['provinces'][$pid]['income'] ?? 0);
    $state['provinces'][$pid]['income'] = $cur + $delta;
    return;
  }

  if ($type === 'building_add' || $type === 'building_remove') {
    $pid = (string)($payload['pid'] ?? '');
    $name = trim((string)($payload['name'] ?? ''));
    if ($pid === '' || $name === '' || !is_array($state['provinces'][$pid] ?? null)) return;
    $buildings = $state['provinces'][$pid]['buildings'] ?? [];
    if (!is_array($buildings)) $buildings = [];
    if ($type === 'building_add' && !in_array($name, $buildings, true)) $buildings[] = $name;
    if ($type === 'building_remove') $buildings = array_values(array_filter($buildings, static fn($v) => (string)$v !== $name));
    $state['provinces'][$pid]['buildings'] = $buildings;
    return;
  }

  if (in_array($type, ['law_add','law_remove','law_update'], true)) {
    if (!is_array($state[$entityType][$entityId] ?? null)) return;
    $laws = $state[$entityType][$entityId]['laws'] ?? [];
    if (!is_array($laws)) $laws = [];
    $code = trim((string)($payload['code'] ?? ''));
    $text = trim((string)($payload['text'] ?? ''));
    if ($code === '') return;
    if ($type === 'law_add' || $type === 'law_update') $laws[$code] = ['code' => $code, 'text' => $text];
    if ($type === 'law_remove') unset($laws[$code]);
    $state[$entityType][$entityId]['laws'] = $laws;
    return;
  }

  if ($type === 'garrison_change' || $type === 'militia_change') {
    $pid = (string)($payload['pid'] ?? '');
    $field = $type === 'garrison_change' ? 'garrison' : 'militia';
    if ($pid !== '' && is_array($state['provinces'][$pid] ?? null)) {
      $cur = (int)($state['provinces'][$pid][$field] ?? 0);
      $deltaInt = (int)($payload['delta'] ?? 0);
      $state['provinces'][$pid][$field] = max(0, $cur + $deltaInt);
    }
    return;
  }

  if (in_array($type, ['army_create','unit_raise'], true)) {
    if (!is_array($state[$entityType][$entityId] ?? null)) return;
    $armies = $state[$entityType][$entityId]['armies'] ?? [];
    if (!is_array($armies)) $armies = [];
    $armyId = trim((string)($payload['army_id'] ?? orders_api_next_id('army')));
    $armies[$armyId] = [
      'id' => $armyId,
      'name' => trim((string)($payload['name'] ?? 'Новая дружина')),
      'province_pid' => (int)($payload['pid'] ?? 0),
      'strength' => max(1, (int)($payload['strength'] ?? 10)),
      'unit_type' => trim((string)($payload['unit_type'] ?? 'infantry')),
    ];
    $state[$entityType][$entityId]['armies'] = $armies;
    return;
  }

  if ($type === 'unit_disband') {
    $armyId = trim((string)($payload['army_id'] ?? ''));
    if ($armyId !== '' && is_array($state[$entityType][$entityId]['armies'] ?? null)) {
      unset($state[$entityType][$entityId]['armies'][$armyId]);
    }
    return;
  }

  if ($type === 'army_merge') {
    $from = trim((string)($payload['from_army_id'] ?? ''));
    $into = trim((string)($payload['into_army_id'] ?? ''));
    $armies = is_array($state[$entityType][$entityId]['armies'] ?? null) ? $state[$entityType][$entityId]['armies'] : [];
    if ($from !== '' && $into !== '' && is_array($armies[$from] ?? null) && is_array($armies[$into] ?? null)) {
      $armies[$into]['strength'] = (int)($armies[$into]['strength'] ?? 0) + (int)($armies[$from]['strength'] ?? 0);
      unset($armies[$from]);
      $state[$entityType][$entityId]['armies'] = $armies;
    }
    return;
  }

  if ($type === 'army_split') {
    $source = trim((string)($payload['source_army_id'] ?? ''));
    $newId = trim((string)($payload['new_army_id'] ?? orders_api_next_id('army')));
    $size = max(1, (int)($payload['split_strength'] ?? 1));
    $armies = is_array($state[$entityType][$entityId]['armies'] ?? null) ? $state[$entityType][$entityId]['armies'] : [];
    if ($source !== '' && is_array($armies[$source] ?? null)) {
      $src = (int)($armies[$source]['strength'] ?? 0);
      if ($src > $size) {
        $armies[$source]['strength'] = $src - $size;
        $copy = $armies[$source];
        $copy['id'] = $newId;
        $copy['name'] = trim((string)($payload['new_name'] ?? ('Отделение ' . ($copy['name'] ?? $source))));
        $copy['strength'] = $size;
        $armies[$newId] = $copy;
        $state[$entityType][$entityId]['armies'] = $armies;
      }
    }
    return;
  }

  if (in_array($type, ['treaty_create','treaty_update','trade_agreement_create','war_declare','war_end','vassalage_change','province_control_change','entity_relation_note','map_event_note'], true)) {
    $bucketKey = in_array($type, ['treaty_create','treaty_update','trade_agreement_create'], true) ? 'treaties' : 'order_effect_registry';
    $bucket = orders_api_turn_bucket($turn, $bucketKey);
    $bucket[] = [
      'order_id' => (string)$order['id'],
      'effect_id' => (string)($effect['id'] ?? ''),
      'effect_type' => $type,
      'entity_type' => $entityType,
      'entity_id' => $entityId,
      'payload' => $payload,
      'by' => $adminUserId,
      'ts' => gmdate('c'),
    ];
    $turn[$bucketKey] = $bucket;
    return;
  }

  if (!is_array($turn['order_effect_log'] ?? null)) $turn['order_effect_log'] = [];
  $turn['order_effect_log'][] = ['order_id'=>$order['id'],'effect_id'=>$effect['id'] ?? '', 'effect_type'=>$type, 'payload'=>$payload, 'ts'=>gmdate('c')];
}

function orders_api_apply_effects(array &$order, string $adminUserId): array {
  $state = api_load_state();
  $year = (int)($order['turn_year'] ?? orders_api_current_turn_year());
  $turn = turn_api_load_turn($year) ?? ['year' => $year, 'status' => 'draft', 'treasury_ledger' => []];
  $applied = [];
  foreach (($order['effects'] ?? []) as &$effect) {
    if (!is_array($effect)) continue;
    if (!(bool)($effect['is_enabled'] ?? false)) continue;
    if ((bool)($effect['applied'] ?? false)) continue;
    orders_api_apply_effect_to_state($state, $turn, $order, $effect, $adminUserId);
    $effect['applied'] = true;
    $effect['applied_at'] = gmdate('c');
    $effect['applied_by'] = $adminUserId;
    $applied[] = (string)($effect['id'] ?? '');
    orders_api_event_append('order_effect_applied', (string)$order['id'], ['effect_id' => (string)($effect['id'] ?? '')]);
  }
  unset($effect);
  api_save_state($state);
  turn_api_save_turn($turn);
  return $applied;
}

function orders_api_publish(array &$order, string $adminUserId): array {
  $public = [
    'id' => orders_api_next_id('feed'),
    'type' => 'order_publication',
    'order_id' => (string)$order['id'],
    'turn_year' => (int)($order['turn_year'] ?? 0),
    'entity_type' => (string)($order['entity_type'] ?? ''),
    'entity_id' => (string)($order['entity_id'] ?? ''),
    'title' => (string)($order['title'] ?? ''),
    'rp_post' => (string)($order['rp_post'] ?? ''),
    'public_verdict_text' => (string)(($order['verdict']['public_verdict_text'] ?? '') ?: ''),
    'images' => is_array($order['public_images'] ?? null) ? $order['public_images'] : [],
    'categories' => array_values(array_unique(array_map(static fn($x) => (string)($x['category'] ?? 'other'), is_array($order['action_items'] ?? null) ? $order['action_items'] : []))),
    'created_at' => gmdate('c'),
  ];
  $pubPath = orders_api_publications_path();
  $raw = @file_get_contents($pubPath);
  $dec = is_string($raw) ? json_decode($raw, true) : null;
  if (!is_array($dec)) $dec = ['rows' => []];
  if (!is_array($dec['rows'] ?? null)) $dec['rows'] = [];
  $dec['rows'][] = $public;
  api_atomic_write_json($pubPath, $dec);

  $order['publication'] = [
    'id' => orders_api_next_id('publication'),'order_id' => (string)$order['id'],'event_feed_entry_id' => (string)$public['id'],
    'vk_wall_post_id' => '', 'public_payload_snapshot' => $public,
  ];
  orders_api_event_append('order_published', (string)$order['id'], ['feed_id' => (string)$public['id']]);

  $outRaw = @file_get_contents(orders_api_outbox_path());
  $out = is_string($outRaw) ? json_decode($outRaw, true) : null;
  if (!is_array($out)) $out = ['rows' => []];
  if (!is_array($out['rows'] ?? null)) $out['rows'] = [];
  $out['rows'][] = [
    'id' => orders_api_next_id('vkjob'),
    'type' => 'vk_wall_publish',
    'order_id' => (string)$order['id'],
    'status' => 'pending_wall_post',
    'attempts' => 0,
    'last_error' => '',
    'payload' => $public,
    'created_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
  ];
  api_atomic_write_json(orders_api_outbox_path(), $out);
  orders_api_event_append('vk_wall_publish_requested', (string)$order['id'], []);

  // notify player in bot (best-effort)
  $vkUid = (int)($order['author_vk_user_id'] ?? 0);
  if ($vkUid > 0) {
    $msg = "Вердикт по приказу «" . (string)$order['title'] . "» опубликован в летописи мира.";
    vk_bot_send_message($vkUid, $msg);
    orders_api_event_append('player_notified', (string)$order['id'], ['channel' => 'vk']);
  }

  return $public;
}

function orders_api_process_outbox(): array {
  $raw = @file_get_contents(orders_api_outbox_path());
  $out = is_string($raw) ? json_decode($raw, true) : null;
  if (!is_array($out)) $out = ['rows' => []];
  if (!is_array($out['rows'] ?? null)) $out['rows'] = [];

  $done = 0; $failed = 0;
  foreach ($out['rows'] as &$job) {
    if (!is_array($job)) continue;
    if ((string)($job['type'] ?? '') !== 'vk_wall_publish') continue;
    if ((string)($job['status'] ?? '') === 'wall_posted') continue;

    $job['attempts'] = (int)($job['attempts'] ?? 0) + 1;
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $text = "Летопись хода " . (string)($payload['turn_year'] ?? '') . "\n"
      . (string)($payload['entity_id'] ?? '') . "\n"
      . (string)($payload['title'] ?? '') . "\n\n"
      . (string)($payload['rp_post'] ?? '') . "\n\nВердикт:\n"
      . (string)($payload['public_verdict_text'] ?? '');

    $cfg = vk_bot_load_config();
    if (!$cfg['enabled'] || $cfg['access_token'] === '' || $cfg['group_id'] === '') {
      $job['status'] = 'wall_post_failed';
      $job['last_error'] = 'vk_not_configured';
      $failed++;
    } else {
      $ownerId = '-' . preg_replace('/[^0-9]/', '', (string)$cfg['group_id']);
      $resp = vk_bot_vk_api_call('wall.post', [
        'owner_id' => $ownerId,
        'from_group' => 1,
        'message' => mb_substr($text, 0, 3900),
      ]);
      if (is_array($resp) && isset($resp['response']['post_id'])) {
        $job['status'] = 'wall_posted';
        $job['wall_post_id'] = (string)$resp['response']['post_id'];
        $job['last_error'] = '';
        $done++;
        orders_api_event_append('vk_wall_published', (string)($job['order_id'] ?? ''), ['post_id' => $job['wall_post_id']]);
      } else {
        $job['status'] = 'wall_post_failed';
        $job['last_error'] = 'vk_api_error';
        $failed++;
      }
    }
    $job['updated_at'] = gmdate('c');
  }
  unset($job);

  api_atomic_write_json(orders_api_outbox_path(), $out);
  return ['processed' => $done + $failed, 'posted' => $done, 'failed' => $failed];
}
