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

function orders_api_locks_path(): string { return orders_api_base_dir() . '/locks.json'; }

function orders_api_order_etag(array $order): string {
  $id = (string)($order['id'] ?? 'order');
  $ver = (int)($order['version'] ?? 0);
  return 'W/"ord-' . $id . '-v' . $ver . '"';
}

function orders_api_emit_order_etag(array $order): void {
  header('ETag: ' . orders_api_order_etag($order));
}

function orders_api_load_locks(): array {
  if (!is_file(orders_api_locks_path())) return ['locks' => []];
  $raw = @file_get_contents(orders_api_locks_path());
  $dec = is_string($raw) ? json_decode($raw, true) : null;
  if (!is_array($dec)) $dec = ['locks' => []];
  if (!is_array($dec['locks'] ?? null)) $dec['locks'] = [];
  return $dec;
}

function orders_api_save_locks(array $locks): void {
  if (!is_array($locks['locks'] ?? null)) $locks['locks'] = [];
  api_atomic_write_json(orders_api_locks_path(), $locks);
}

function orders_api_acquire_order_lock(string $orderId, string $actor, int $ttlSec = 180): array {
  $now = time();
  $locks = orders_api_load_locks();
  $existing = $locks['locks'][$orderId] ?? null;
  $hdrToken = trim((string)($_SERVER['HTTP_X_ORDER_LOCK_TOKEN'] ?? ''));

  if (is_array($existing)) {
    $expTs = strtotime((string)($existing['expires_at'] ?? ''));
    $expired = ($expTs === false) || ($expTs <= $now);
    if (!$expired) {
      $ownedByActor = ((string)($existing['actor'] ?? '') === $actor);
      $ownedByToken = ($hdrToken !== '' && hash_equals((string)($existing['token'] ?? ''), $hdrToken));
      if (!$ownedByActor && !$ownedByToken) {
        orders_api_response([
          'error' => 'order_locked',
          'order_id' => $orderId,
          'locked_by' => (string)($existing['actor'] ?? ''),
          'expires_at' => (string)($existing['expires_at'] ?? ''),
        ], 423);
      }
    }
  }

  $token = trim((string)($existing['token'] ?? ''));
  if ($token === '') $token = orders_api_next_id('lock');
  $locks['locks'][$orderId] = [
    'order_id' => $orderId,
    'actor' => $actor,
    'token' => $token,
    'acquired_at' => gmdate('c'),
    'expires_at' => gmdate('c', $now + $ttlSec),
  ];
  orders_api_save_locks($locks);
  header('X-Order-Lock-Token: ' . $token);
  header('X-Order-Lock-Expires-At: ' . $locks['locks'][$orderId]['expires_at']);
  return $locks['locks'][$orderId];
}

function orders_api_ensure_store(): void {
  $base = orders_api_base_dir();
  if (!is_dir($base)) @mkdir($base, 0775, true);
  if (!is_file(orders_api_orders_path())) {
    api_atomic_write_json(orders_api_orders_path(), ['orders' => orders_api_seed_orders(), 'updated_at' => gmdate('c')]);
  }
  if (!is_file(orders_api_events_path())) api_atomic_write_json(orders_api_events_path(), ['events' => []]);
  if (!is_file(orders_api_publications_path())) api_atomic_write_json(orders_api_publications_path(), ['rows' => []]);
  if (!is_file(orders_api_outbox_path())) api_atomic_write_json(orders_api_outbox_path(), ['rows' => []]);
  if (!is_file(orders_api_locks_path())) api_atomic_write_json(orders_api_locks_path(), ['locks' => []]);
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
  foreach ($dec['orders'] as &$order) {
    if (!is_array($order)) continue;
    $order['public_images'] = orders_api_normalize_attachment_list($order['public_images'] ?? [], 'public');
    $order['private_attachments'] = orders_api_normalize_attachment_list($order['private_attachments'] ?? [], 'private');
    if (!is_array($order['attachment_registry'] ?? null)) $order['attachment_registry'] = orders_api_build_attachment_registry($order);
  }
  unset($order);
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


function orders_api_normalize_attachment($row, string $defaultVisibility = 'private'): ?array {
  if (!is_array($row)) {
    if (is_string($row) && trim($row) !== '') {
      return [
        'id' => orders_api_next_id('att'),
        'url' => trim($row),
        'visibility' => $defaultVisibility,
        'mime' => '',
        'size_bytes' => 0,
        'file_name' => '',
        'source' => 'web',
        'uploaded_at' => gmdate('c'),
        'kind' => 'file',
        'title' => '',
        'description' => '',
        'tags' => [],
        'access_scope' => $defaultVisibility,
        'checksum_sha1' => '',
        'meta' => [],
        'vk_attachment' => '',
        'vk_access_key' => '',
      ];
    }
    return null;
  }
  $url = trim((string)($row['url'] ?? ''));
  $vkAttachment = trim((string)($row['vk_attachment'] ?? ''));
  if ($url === '' && $vkAttachment === '') return null;
  $vis = trim((string)($row['visibility'] ?? $defaultVisibility));
  if (!in_array($vis, ['public','private'], true)) $vis = $defaultVisibility;
  return [
    'id' => trim((string)($row['id'] ?? orders_api_next_id('att'))),
    'url' => $url,
    'visibility' => $vis,
    'mime' => trim((string)($row['mime'] ?? '')),
    'size_bytes' => max(0, (int)($row['size_bytes'] ?? 0)),
    'file_name' => trim((string)($row['file_name'] ?? '')),
    'source' => trim((string)($row['source'] ?? 'web')),
    'uploaded_at' => trim((string)($row['uploaded_at'] ?? gmdate('c'))),
    'kind' => trim((string)($row['kind'] ?? 'file')),
    'title' => mb_substr(trim((string)($row['title'] ?? '')), 0, 200),
    'description' => mb_substr(trim((string)($row['description'] ?? '')), 0, 1000),
    'tags' => array_values(array_filter(array_map(static fn($x) => mb_substr(trim((string)$x),0,50), is_array($row['tags'] ?? null) ? $row['tags'] : []), static fn($x) => $x !== '')),
    'access_scope' => in_array(trim((string)($row['access_scope'] ?? $vis)), ['public','private','owner_only','admin_only'], true) ? trim((string)($row['access_scope'] ?? $vis)) : $vis,
    'checksum_sha1' => preg_match('/^[a-f0-9]{40}$/i', (string)($row['checksum_sha1'] ?? '')) ? strtolower((string)$row['checksum_sha1']) : '',
    'meta' => is_array($row['meta'] ?? null) ? $row['meta'] : [],
    'vk_attachment' => $vkAttachment,
    'vk_access_key' => trim((string)($row['vk_access_key'] ?? '')),
  ];
}

function orders_api_normalize_attachment_list($rows, string $defaultVisibility = 'private'): array {
  $out = [];
  foreach ((array)$rows as $row) {
    $norm = orders_api_normalize_attachment($row, $defaultVisibility);
    if ($norm) $out[] = $norm;
  }
  return $out;
}


function orders_api_build_attachment_registry(array $order): array {
  $registry = [];
  $push = static function(array $att, string $channel) use (&$registry, $order): void {
    $key = trim((string)($att['id'] ?? ''));
    if ($key === '') $key = trim((string)($att['url'] ?? ''));
    if ($key === '') return;
    $registry[$key] = [
      'id' => (string)($att['id'] ?? ''),
      'url' => (string)($att['url'] ?? ''),
      'file_name' => (string)($att['file_name'] ?? ''),
      'mime' => (string)($att['mime'] ?? ''),
      'size_bytes' => (int)($att['size_bytes'] ?? 0),
      'visibility' => (string)($att['visibility'] ?? ($channel === 'public_images' ? 'public' : 'private')),
      'access_scope' => (string)($att['access_scope'] ?? ($channel === 'public_images' ? 'public' : 'private')),
      'channel' => $channel,
      'kind' => (string)($att['kind'] ?? 'file'),
      'title' => (string)($att['title'] ?? ''),
      'description' => (string)($att['description'] ?? ''),
      'tags' => is_array($att['tags'] ?? null) ? $att['tags'] : [],
      'checksum_sha1' => (string)($att['checksum_sha1'] ?? ''),
      'source' => (string)($att['source'] ?? 'web'),
      'uploaded_at' => (string)($att['uploaded_at'] ?? ''),
      'meta' => is_array($att['meta'] ?? null) ? $att['meta'] : [],
      'owner_entity_type' => (string)($order['entity_type'] ?? ''),
      'owner_entity_id' => (string)($order['entity_id'] ?? ''),
      'last_bound_order_id' => (string)($order['id'] ?? ''),
      'last_bound_at' => gmdate('c'),
    ];
  };

  foreach ((array)($order['public_images'] ?? []) as $att) if (is_array($att)) $push($att, 'public_images');
  foreach ((array)($order['private_attachments'] ?? []) as $att) if (is_array($att)) $push($att, 'private_attachments');
  return array_values($registry);
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
  if ($header !== '') {
    if (preg_match('/(\d+)/', $header, $m)) return (int)$m[1];
  }
  return (int)($payload['version'] ?? 0);
}

function orders_api_assert_version(array $order, array $payload, bool $required = true): void {
  $provided = orders_api_payload_version($payload);
  $expected = (int)($order['version'] ?? 0);
  if ($required && $provided <= 0) {
    orders_api_response([
      'error' => 'version_required',
      'expected' => $expected,
      'latest_order_version' => $expected,
      'latest_order_snapshot' => $order,
    ], 409);
  }
  if ($provided > 0 && $provided !== $expected) {
    orders_api_response([
      'error' => 'version_conflict',
      'expected' => $expected,
      'provided' => $provided,
      'latest_order_version' => $expected,
      'latest_order_snapshot' => $order,
    ], 409);
  }
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

function orders_api_turn_year_from_turn_mechanics(): array {
  $index = turn_api_load_index();
  $turns = is_array($index['turns'] ?? null) ? $index['turns'] : [];
  $years = [];
  foreach ($turns as $row) {
    $y = is_array($row) ? (int)($row['year'] ?? 0) : (int)$row;
    if ($y > 0) $years[] = $y;
  }
  if (empty($years)) {
    return [
      'ok' => false,
      'error' => 'turn_year_unavailable',
      'message' => 'Не удалось определить год хода строго из turn-механики: data/turns/index.json не содержит ни одного хода. Создайте/опубликуйте ход через turn_admin.',
      'details' => ['turn_count' => 0, 'index_updated_at' => (string)($index['updated_at'] ?? '')],
    ];
  }
  sort($years);
  $year = (int)$years[count($years)-1];
  $turn = turn_api_load_turn($year);
  if (!is_array($turn)) {
    return [
      'ok' => false,
      'error' => 'turn_payload_missing',
      'message' => 'Не удалось определить год хода строго из turn-механики: индекс указывает на год ' . $year . ', но файл turn_' . $year . '.json отсутствует или повреждён.',
      'details' => ['year' => $year],
    ];
  }
  return ['ok' => true, 'year' => $year, 'turn' => $turn];
}

function orders_api_current_turn_year(): int {
  $resolved = orders_api_turn_year_from_turn_mechanics();
  if (($resolved['ok'] ?? false) === true) return (int)($resolved['year'] ?? 0);
  return 0;
}

function orders_api_filter_public(array $order, bool $isAdmin = false, bool $isOwner = false): array {
  if ($isAdmin || $isOwner) return $order;
  $safe = $order;
  if (is_array($safe['verdict'] ?? null)) {
    unset($safe['verdict']['private_notes']);
    unset($safe['verdict']['clarification_request_text']);
  }
  if (is_array($safe['public_images'] ?? null)) {
    $safe['public_images'] = array_values(array_filter($safe['public_images'], static fn($x) => is_array($x) ? (($x['visibility'] ?? 'public') === 'public') : true));
  }
  if (is_array($safe['private_attachments'] ?? null)) {
    $safe['private_attachments'] = [];
  }
  if (is_array($safe['attachment_registry'] ?? null)) {
    $safe['attachment_registry'] = array_values(array_filter($safe['attachment_registry'], static fn($x) => is_array($x) && (($x['visibility'] ?? 'private') === 'public')));
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

  if (!is_array($state['diplomacy'] ?? null)) $state['diplomacy'] = [];
  if (!is_array($state['economy'] ?? null)) $state['economy'] = [];
  if (!is_array($state['military'] ?? null)) $state['military'] = [];

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

  if (!is_array($state['military']['armies'] ?? null)) $state['military']['armies'] = [];
  if (!is_array($state[$entityType][$entityId]['armies'] ?? null)) $state[$entityType][$entityId]['armies'] = [];

  if (in_array($type, ['army_create','unit_raise'], true)) {
    if (!is_array($state[$entityType][$entityId] ?? null)) return;
    $armyId = trim((string)($payload['army_id'] ?? orders_api_next_id('army')));
    $row = [
      'id' => $armyId,
      'name' => trim((string)($payload['name'] ?? 'Новая дружина')),
      'province_pid' => (string)($payload['pid'] ?? ''),
      'strength' => max(1, (int)($payload['strength'] ?? 10)),
      'unit_type' => trim((string)($payload['unit_type'] ?? 'infantry')),
      'owner_entity_type' => $entityType,
      'owner_entity_id' => $entityId,
      'updated_at' => gmdate('c'),
    ];
    $state[$entityType][$entityId]['armies'][$armyId] = $row;
    $state['military']['armies'][$armyId] = $row;
    return;
  }

  if ($type === 'unit_disband') {
    $armyId = trim((string)($payload['army_id'] ?? ''));
    if ($armyId !== '') {
      unset($state[$entityType][$entityId]['armies'][$armyId]);
      unset($state['military']['armies'][$armyId]);
    }
    return;
  }

  if ($type === 'army_merge') {
    $from = trim((string)($payload['from_army_id'] ?? ''));
    $into = trim((string)($payload['into_army_id'] ?? ''));
    $armies = is_array($state[$entityType][$entityId]['armies'] ?? null) ? $state[$entityType][$entityId]['armies'] : [];
    if ($from !== '' && $into !== '' && is_array($armies[$from] ?? null) && is_array($armies[$into] ?? null)) {
      $armies[$into]['strength'] = (int)($armies[$into]['strength'] ?? 0) + (int)($armies[$from]['strength'] ?? 0);
      $armies[$into]['updated_at'] = gmdate('c');
      unset($armies[$from]);
      $state[$entityType][$entityId]['armies'] = $armies;
      $state['military']['armies'][$into] = $armies[$into];
      unset($state['military']['armies'][$from]);
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
        $armies[$source]['updated_at'] = gmdate('c');
        $copy = $armies[$source];
        $copy['id'] = $newId;
        $copy['name'] = trim((string)($payload['new_name'] ?? ('Отделение ' . ($copy['name'] ?? $source))));
        $copy['strength'] = $size;
        $copy['updated_at'] = gmdate('c');
        $armies[$newId] = $copy;
        $state[$entityType][$entityId]['armies'] = $armies;
        $state['military']['armies'][$source] = $armies[$source];
        $state['military']['armies'][$newId] = $copy;
      }
    }
    return;
  }

  if ($type === 'province_control_change') {
    $pid = trim((string)($payload['pid'] ?? ''));
    $newType = trim((string)($payload['target_entity_type'] ?? $entityType));
    $newId = trim((string)($payload['target_entity_id'] ?? $entityId));
    if ($pid !== '' && is_array($state['provinces'][$pid] ?? null)) {
      $map = ['great_houses' => 'great_house_id', 'minor_houses' => 'minor_house_id', 'free_cities' => 'free_city_id'];
      foreach ($map as $f) $state['provinces'][$pid][$f] = '';
      if (isset($map[$newType])) $state['provinces'][$pid][$map[$newType]] = $newId;
      $state['provinces'][$pid]['owner'] = $newId;
      $state['provinces'][$pid]['control_updated_at'] = gmdate('c');
    }
    return;
  }

  if (!is_array($state['diplomacy']['treaties'] ?? null)) $state['diplomacy']['treaties'] = [];
  if (!is_array($state['economy']['trade_agreements'] ?? null)) $state['economy']['trade_agreements'] = [];
  if (!is_array($state['diplomacy']['wars'] ?? null)) $state['diplomacy']['wars'] = [];
  if (!is_array($state['diplomacy']['vassalage'] ?? null)) $state['diplomacy']['vassalage'] = [];
  if (!is_array($state['diplomacy']['relation_notes'] ?? null)) $state['diplomacy']['relation_notes'] = [];
  if (!is_array($state['map_event_log'] ?? null)) $state['map_event_log'] = [];

  if ($type === 'treaty_create' || $type === 'treaty_update') {
    $tid = trim((string)($payload['treaty_id'] ?? orders_api_next_id('treaty')));
    $row = is_array($state['diplomacy']['treaties'][$tid] ?? null) ? $state['diplomacy']['treaties'][$tid] : [];
    $row['id'] = $tid;
    $row['title'] = trim((string)($payload['title'] ?? ($row['title'] ?? 'Договор')));
    $row['parties'] = is_array($payload['parties'] ?? null) ? $payload['parties'] : ($row['parties'] ?? [[$entityType, $entityId]]);
    $row['terms'] = (string)($payload['terms'] ?? ($row['terms'] ?? ''));
    $row['status'] = trim((string)($payload['status'] ?? ($type === 'treaty_create' ? 'active' : ($row['status'] ?? 'active'))));
    $row['updated_at'] = gmdate('c');
    $state['diplomacy']['treaties'][$tid] = $row;
    return;
  }

  if ($type === 'trade_agreement_create') {
    $aid = trim((string)($payload['agreement_id'] ?? orders_api_next_id('trade')));
    $state['economy']['trade_agreements'][$aid] = [
      'id' => $aid,
      'title' => trim((string)($payload['title'] ?? 'Торговое соглашение')),
      'parties' => is_array($payload['parties'] ?? null) ? $payload['parties'] : [[$entityType, $entityId]],
      'route' => (string)($payload['route'] ?? ''),
      'tariff' => (float)($payload['tariff'] ?? 0),
      'status' => trim((string)($payload['status'] ?? 'active')),
      'updated_at' => gmdate('c'),
    ];
    return;
  }

  if ($type === 'war_declare' || $type === 'war_end') {
    $wid = trim((string)($payload['war_id'] ?? orders_api_next_id('war')));
    $row = is_array($state['diplomacy']['wars'][$wid] ?? null) ? $state['diplomacy']['wars'][$wid] : [];
    $row['id'] = $wid;
    $row['attackers'] = is_array($payload['attackers'] ?? null) ? $payload['attackers'] : ($row['attackers'] ?? [[$entityType, $entityId]]);
    $row['defenders'] = is_array($payload['defenders'] ?? null) ? $payload['defenders'] : ($row['defenders'] ?? []);
    $row['status'] = $type === 'war_end' ? 'ended' : 'active';
    if ($type === 'war_declare' && empty($row['started_at'])) $row['started_at'] = gmdate('c');
    if ($type === 'war_end') $row['ended_at'] = gmdate('c');
    $row['updated_at'] = gmdate('c');
    $state['diplomacy']['wars'][$wid] = $row;
    return;
  }

  if ($type === 'vassalage_change') {
    $vType = trim((string)($payload['vassal_entity_type'] ?? ''));
    $vId = trim((string)($payload['vassal_entity_id'] ?? ''));
    $sType = trim((string)($payload['suzerain_entity_type'] ?? $entityType));
    $sId = trim((string)($payload['suzerain_entity_id'] ?? $entityId));
    if ($vType !== '' && $vId !== '') {
      $key = $vType . ':' . $vId;
      $state['diplomacy']['vassalage'][$key] = [
        'vassal_entity_type' => $vType,
        'vassal_entity_id' => $vId,
        'suzerain_entity_type' => $sType,
        'suzerain_entity_id' => $sId,
        'status' => trim((string)($payload['status'] ?? 'active')),
        'updated_at' => gmdate('c'),
      ];
    }
    return;
  }

  if ($type === 'entity_relation_note') {
    $left = trim((string)($payload['left_entity'] ?? ($entityType . ':' . $entityId)));
    $right = trim((string)($payload['right_entity'] ?? ''));
    $note = trim((string)($payload['note'] ?? ''));
    if ($left !== '' && $right !== '' && $note !== '') {
      $state['diplomacy']['relation_notes'][] = [
        'left_entity' => $left,
        'right_entity' => $right,
        'note' => $note,
        'order_id' => (string)$order['id'],
        'effect_id' => (string)($effect['id'] ?? ''),
        'by' => $adminUserId,
        'ts' => gmdate('c'),
      ];
    }
    return;
  }

  if ($type === 'map_event_note') {
    $row = [
      'id' => orders_api_next_id('mev'),
      'title' => trim((string)($payload['title'] ?? (string)($order['title'] ?? 'Событие на карте'))),
      'note' => trim((string)($payload['note'] ?? (string)($payload['text'] ?? ''))),
      'pid' => trim((string)($payload['pid'] ?? '')),
      'order_id' => (string)$order['id'],
      'effect_id' => (string)($effect['id'] ?? ''),
      'by' => $adminUserId,
      'ts' => gmdate('c'),
    ];
    $state['map_event_log'][] = $row;
    if (!is_array($turn['map_events'] ?? null)) $turn['map_events'] = [];
    $turn['map_events'][] = $row;
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


function orders_api_vk_player_verdict_message(array $order): string {
  $title = trim((string)($order['title'] ?? 'Без названия'));
  $year = (int)($order['turn_year'] ?? 0);
  $verdict = is_array($order['verdict'] ?? null) ? $order['verdict'] : [];
  $publicText = trim((string)($verdict['public_verdict_text'] ?? ''));
  $privateNotes = trim((string)($verdict['private_notes'] ?? ''));
  $rolls = is_array($verdict['rolls'] ?? null) ? $verdict['rolls'] : [];
  $effects = is_array($order['effects'] ?? null) ? $order['effects'] : [];

  $lines = [];
  $lines[] = '📜 Вердикт по приказу опубликован';
  $lines[] = 'Приказ: ' . $title;
  if ($year > 0) $lines[] = 'Ход/год: ' . $year;

  if ($publicText !== '') {
    $lines[] = '';
    $lines[] = 'РП-вердикт:';
    $lines[] = mb_substr($publicText, 0, 2500);
  }

  if ($privateNotes !== '') {
    $lines[] = '';
    $lines[] = 'Комментарий вердиктора:';
    $lines[] = mb_substr($privateNotes, 0, 1500);
  }

  if (!empty($rolls)) {
    $lines[] = '';
    $lines[] = 'Результаты кубов:';
    foreach ($rolls as $r) {
      if (!is_array($r)) continue;
      $itemId = trim((string)($r['order_action_item_id'] ?? ''));
      $raw = (int)($r['roll_raw'] ?? 0);
      $mod = (int)($r['modifier'] ?? 0);
      $total = (int)($r['total'] ?? ($raw + $mod));
      $tier = trim((string)($r['outcome_tier'] ?? ''));
      $lines[] = '• ' . ($itemId !== '' ? $itemId : 'пункт') . ': d20=' . $raw . ($mod >= 0 ? '+' : '') . $mod . ' => ' . $total . ($tier !== '' ? (' (' . $tier . ')') : '');
    }
  }

  $enabledEffects = [];
  foreach ($effects as $ef) {
    if (!is_array($ef)) continue;
    if (!(bool)($ef['is_enabled'] ?? false)) continue;
    $enabledEffects[] = $ef;
  }
  if (!empty($enabledEffects)) {
    $lines[] = '';
    $lines[] = 'Последствия / механические изменения:';
    foreach ($enabledEffects as $ef) {
      $type = trim((string)($ef['effect_type'] ?? 'effect'));
      $pl = is_array($ef['payload'] ?? null) ? $ef['payload'] : [];
      $parts = [];
      foreach (['pid','delta','reason','note','title','target_entity_id'] as $k) {
        if (!array_key_exists($k, $pl)) continue;
        $v = trim((string)$pl[$k]);
        if ($v === '') continue;
        $parts[] = $k . '=' . mb_substr($v, 0, 80);
      }
      $lines[] = '• ' . $type . (empty($parts) ? '' : (' [' . implode('; ', $parts) . ']'));
    }
  }

  return mb_substr(implode("
", $lines), 0, 3900);
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
    $msg = orders_api_vk_player_verdict_message($order);
    vk_bot_send_message($vkUid, $msg);
    orders_api_event_append('player_notified', (string)$order['id'], ['channel' => 'vk']);
  }

  return $public;
}


function orders_api_outbox_status_for_order(string $orderId): ?array {
  $raw = @file_get_contents(orders_api_outbox_path());
  $out = is_string($raw) ? json_decode($raw, true) : null;
  $rows = is_array($out['rows'] ?? null) ? $out['rows'] : [];
  for ($i = count($rows)-1; $i >= 0; $i--) {
    $job = $rows[$i] ?? null;
    if (!is_array($job)) continue;
    if ((string)($job['order_id'] ?? '') !== $orderId) continue;
    if ((string)($job['type'] ?? '') !== 'vk_wall_publish') continue;
    return [
      'id' => (string)($job['id'] ?? ''),
      'status' => (string)($job['status'] ?? ''),
      'attempts' => (int)($job['attempts'] ?? 0),
      'last_error' => (string)($job['last_error'] ?? ''),
      'last_error_details' => is_array($job['last_error_details'] ?? null) ? $job['last_error_details'] : [],
      'next_attempt_at' => (string)($job['next_attempt_at'] ?? ''),
      'updated_at' => (string)($job['updated_at'] ?? ''),
      'wall_post_id' => (string)($job['wall_post_id'] ?? ''),
    ];
  }
  return null;
}

function orders_api_process_outbox(): array {
  $raw = @file_get_contents(orders_api_outbox_path());
  $out = is_string($raw) ? json_decode($raw, true) : null;
  if (!is_array($out)) $out = ['rows' => []];
  if (!is_array($out['rows'] ?? null)) $out['rows'] = [];

  $done = 0; $failed = 0;
  $maxAttempts = 5;
  $baseRetrySeconds = 300;
  $nowTs = time();
  foreach ($out['rows'] as &$job) {
    if (!is_array($job)) continue;
    if ((string)($job['type'] ?? '') !== 'vk_wall_publish') continue;
    if ((string)($job['status'] ?? '') === 'wall_posted') continue;
    if ((string)($job['status'] ?? '') === 'wall_post_failed_permanent') continue;

    $nextTs = strtotime((string)($job['next_attempt_at'] ?? ''));
    if ($nextTs !== false && $nextTs > $nowTs) continue;

    $attempt = (int)($job['attempts'] ?? 0) + 1;
    $job['attempts'] = $attempt;
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $text = "Летопись хода " . (string)($payload['turn_year'] ?? '') . "\n"
      . (string)($payload['entity_id'] ?? '') . "\n"
      . (string)($payload['title'] ?? '') . "\n\n"
      . (string)($payload['rp_post'] ?? '') . "\n\nВердикт:\n"
      . (string)($payload['public_verdict_text'] ?? '');

    $cfg = vk_bot_load_config();
    if (!$cfg['enabled'] || $cfg['access_token'] === '' || $cfg['group_id'] === '') {
      $job['status'] = 'wall_post_failed_retry';
      $job['last_error'] = 'vk_not_configured';
      $job['last_error_details'] = [
        'enabled' => (bool)$cfg['enabled'],
        'has_access_token' => $cfg['access_token'] !== '',
        'group_id' => (string)$cfg['group_id'],
      ];
      $failed++;
    } else {
      $ownerId = '-' . preg_replace('/[^0-9]/', '', (string)$cfg['group_id']);
      $attachment = '';
      $images = (array)($payload['images'] ?? []);
      foreach ($images as $img) {
        $attachment = vk_bot_try_build_wall_photo_attachment($img);
        if ($attachment !== '') break;
      }
      if (!empty($images) && $attachment === '') {
        $job['status'] = 'wall_post_failed_retry';
        $job['last_error'] = 'image_attachment_unresolved';
        $job['last_error_details'] = [
          'images_count' => count($images),
          'hint' => 'Не удалось подготовить attachment для wall.post',
          'vk_api_error' => vk_bot_get_last_api_error(),
        ];
        $failed++;
        if ($attempt >= $maxAttempts) {
          $job['status'] = 'wall_post_failed_permanent';
          $job['next_attempt_at'] = '';
        } else {
          $retryDelay = $baseRetrySeconds * (2 ** ($attempt - 1));
          $job['next_attempt_at'] = gmdate('c', $nowTs + $retryDelay);
        }
        $job['updated_at'] = gmdate('c');
        continue;
      }
      $params = [
        'owner_id' => $ownerId,
        'from_group' => 1,
        'message' => mb_substr($text, 0, 3900),
      ];
      if ($attachment !== '') $params['attachments'] = $attachment;

      $resp = vk_bot_vk_api_call('wall.post', $params);
      if (is_array($resp) && isset($resp['response']['post_id'])) {
        $job['status'] = 'wall_posted';
        $job['wall_post_id'] = (string)$resp['response']['post_id'];
        $job['last_error'] = '';
        $job['last_error_details'] = [];
        $job['next_attempt_at'] = '';
        $done++;
        orders_api_event_append('vk_wall_published', (string)($job['order_id'] ?? ''), ['post_id' => $job['wall_post_id']]);
      } else {
        $job['status'] = 'wall_post_failed_retry';
        $job['last_error'] = 'vk_api_error';
        $job['last_error_details'] = vk_bot_get_last_api_error();
        $failed++;
      }
    }

    if ((string)$job['status'] !== 'wall_posted') {
      if ($attempt >= $maxAttempts) {
        $job['status'] = 'wall_post_failed_permanent';
        $job['next_attempt_at'] = '';
      } else {
        $retryDelay = $baseRetrySeconds * (2 ** ($attempt - 1));
        $job['next_attempt_at'] = gmdate('c', $nowTs + $retryDelay);
      }
    }
    $job['updated_at'] = gmdate('c');
  }
  unset($job);

  api_atomic_write_json(orders_api_outbox_path(), $out);
  return ['processed' => $done + $failed, 'posted' => $done, 'failed' => $failed];
}
