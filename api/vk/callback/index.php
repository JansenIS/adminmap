<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/vk_bot_api.php';
require_once dirname(__DIR__, 2) . '/lib/orders_api.php';

function vk_bot_extract_image_url(array $message): string {
  $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
  foreach ($attachments as $a) {
    if (!is_array($a)) continue;
    if (($a['type'] ?? '') === 'photo' && is_array($a['photo'] ?? null)) {
      $sizes = is_array($a['photo']['sizes'] ?? null) ? $a['photo']['sizes'] : [];
      $bestUrl = '';
      $bestArea = -1;
      foreach ($sizes as $size) {
        if (!is_array($size)) continue;
        $url = trim((string)($size['url'] ?? ''));
        if ($url === '') continue;
        $w = (int)($size['width'] ?? 0);
        $h = (int)($size['height'] ?? 0);
        $area = $w * $h;
        if ($area > $bestArea) {
          $bestArea = $area;
          $bestUrl = $url;
        }
      }
      if ($bestUrl !== '') return $bestUrl;
    }
    if (($a['type'] ?? '') === 'doc' && is_array($a['doc'] ?? null)) {
      $ext = mb_strtolower(trim((string)($a['doc']['ext'] ?? '')));
      if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        $url = trim((string)($a['doc']['url'] ?? ''));
        if ($url !== '') return $url;
      }
    }
  }
  return '';
}

$raw = (string)file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) { http_response_code(200); echo 'ok'; exit; }

$cfg = vk_bot_load_config();
if (($payload['type'] ?? '') === 'confirmation') { echo $cfg['confirmation_token']; exit; }
if (!$cfg['enabled']) { echo 'ok'; exit; }
if ($cfg['secret'] !== '' && trim((string)($payload['secret'] ?? '')) !== $cfg['secret']) { echo 'ok'; exit; }

if (($payload['type'] ?? '') !== 'message_new') { echo 'ok'; exit; }
$object = is_array($payload['object'] ?? null) ? $payload['object'] : [];
$message = is_array($object['message'] ?? null) ? $object['message'] : $object;
$userId = (int)($message['from_id'] ?? 0);
if ($userId <= 0) { echo 'ok'; exit; }
$text = trim((string)($message['text'] ?? ''));
$cmd = vk_bot_payload_cmd($message);
if ($cmd === '') $cmd = vk_bot_payload_cmd($object);

$sessions = vk_bot_load_sessions();
$apps = vk_bot_load_applications();
$charApps = vk_bot_load_character_applications();
$state = api_load_state();
$session = vk_bot_user_session($sessions, $userId);
$stage = (string)($session['stage'] ?? 'start');
$data = is_array($session['data'] ?? null) ? $session['data'] : [];


$usage = vk_bot_load_image_usage();
$currentUsage = (int)($usage[(string)$userId]['count'] ?? 0);
$remainingImageGenerations = max(0, vk_bot_image_user_limit() - $currentUsage);

$approvedApp = null;
$hasApprovedStateApp = false;
foreach ($apps as $app) {
  if (!is_array($app)) continue;
  if ((int)($app['vk_user_id'] ?? 0) !== $userId) continue;
  if (($app['status'] ?? '') !== 'approved') continue;
  $resolved = vk_bot_resolve_application_entity($app);
  if (!is_array($resolved)) continue;
  $hasApprovedStateApp = true;
  $approvedApp = $app;
  if (!isset($approvedApp['approved_entity_type']) || !isset($approvedApp['approved_entity_id'])) {
    $approvedApp['approved_entity_type'] = (string)$resolved['entity_type'];
    $approvedApp['approved_entity_id'] = (string)$resolved['entity_id'];
  }
}
$approvedCharacterApp = null;
$pendingCharacterApp = null;
foreach ($charApps as $charApp) {
  if (!is_array($charApp)) continue;
  if ((int)($charApp['vk_user_id'] ?? 0) !== $userId) continue;
  if (($charApp['status'] ?? '') === 'approved') $approvedCharacterApp = $charApp;
  if (($charApp['status'] ?? '') === 'pending') $pendingCharacterApp = $charApp;
}



$vkOrderMenu = static function(int $userId) use ($cfg): void {
  vk_bot_send_message($userId, 'Раздел приказов:', vk_bot_keyboard([
    vk_bot_btn('Подать приказ', 'order_new', 'primary'),
    vk_bot_btn('Мои приказы', 'order_my', 'secondary'),
    vk_bot_btn('Черновики приказов', 'order_drafts', 'secondary'),
    vk_bot_btn('Вердикты', 'order_verdicts', 'positive'),
    vk_bot_btn('Запросы на уточнение', 'order_clarifications', 'negative'),
  ]));
};

$sendMainMenu = static function (int $userId, bool $hasApprovedStateApp, ?array $approvedCharacterApp, ?array $pendingCharacterApp): void {
  $btns = [];
  if (!$hasApprovedStateApp) {
    $btns[] = vk_bot_btn('Регистрация нового государства', 'register_new', 'primary');
    $btns[] = vk_bot_btn('Сесть за существующее государство', 'register_existing', 'secondary');
  } else {
    $btns[] = vk_bot_btn('Войти в панель игрока', 'login_panel', 'positive');
    if (is_array($approvedCharacterApp)) {
      $btns[] = vk_bot_btn('Генеалогия рода', 'family_tree_login', 'primary');
    } else {
      $btns[] = vk_bot_btn('Анкета персонажа', 'character_form_new', 'primary');
    }
  }
  $btns[] = vk_bot_btn('🎨 Портрет персонажа', 'character_image_start', 'primary');
  if ($hasApprovedStateApp) $btns[] = vk_bot_btn('📜 Приказы', 'orders_menu', 'primary');

  $msg = 'Добро пожаловать. Выберите действие:';
  if (is_array($pendingCharacterApp) && !is_array($approvedCharacterApp)) {
    $msg .= "\nАнкета персонажа уже отправлена и ожидает модерации.";
  }
  vk_bot_send_message($userId, $msg, vk_bot_keyboard($btns));
};


$collectExistingEntitiesByType = static function(array $state, string $entityType): array {
  $rows = [];
  if ($entityType === 'minor_houses') {
    $derived = player_admin_minor_houses_from_layer($state);
    foreach ($derived as $id => $row) {
      if (!is_array($row)) continue;
      $name = trim((string)($row['name'] ?? $id));
      if ($name === '') $name = (string)$id;
      $rows[] = ['entity_type' => $entityType, 'entity_id' => (string)$id, 'name' => $name];
    }
  } else {
    foreach (($state[$entityType] ?? []) as $id => $row) {
      if (!is_array($row)) continue;
      $name = trim((string)($row['name'] ?? $id));
      if ($name === '') $name = (string)$id;
      $rows[] = ['entity_type' => $entityType, 'entity_id' => (string)$id, 'name' => $name];
    }
  }
  usort($rows, static function(array $a, array $b): int {
    return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
  });
  return $rows;
};

$sendExistingEntitySelection = static function(int $userId, array &$sessions, array $data, array $entities, string $entityType): void {
  $numberMap = [];
  $lines = [];
  foreach (array_values($entities) as $i => $row) {
    if ($i >= 180) break; // keep payload manageable
    $num = (string)($i + 1);
    $numberMap[$num] = ['entity_type' => $entityType, 'entity_id' => (string)($row['entity_id'] ?? '')];
    $name = trim((string)($row['name'] ?? $row['entity_id'] ?? ''));
    $lines[] = $num . '. ' . $name . ' (' . $entityType . ':' . (string)($row['entity_id'] ?? '') . ')';
  }
  $data['existing_entity_type'] = $entityType;
  $data['entity_number_map'] = $numberMap;
  vk_bot_set_user_session($sessions, $userId, ['stage' => 'choose_existing_entity', 'data' => $data]);
  vk_bot_save_sessions($sessions);

  $chunks = array_chunk($lines, 40);
  if (empty($chunks)) {
    vk_bot_send_message($userId, 'Список сущностей пуст.');
    return;
  }
  $head = "Выберите сущность и отправьте номер из списка.
Также можно отправить ID вручную в формате type:id.
";
  vk_bot_send_message($userId, $head . "
" . implode("
", $chunks[0]));
  for ($i=1; $i<count($chunks); $i++) {
    vk_bot_send_message($userId, "Продолжение списка:
" . implode("
", $chunks[$i]));
  }
};

$sendTerritorySelection = static function (int $userId, array &$sessions, array $data, array $territories): void {
  $numberMap = [];
  $territoryLines = [];
  foreach (array_values($territories) as $i => $t) {
    $num = (string)($i + 1);
    $numberMap[$num] = ['type' => (string)$t['type'], 'id' => (string)$t['id']];
    $territoryLines[] = $num . '. ' . $t['name'] . ' (' . $t['type'] . ':' . $t['id'] . ')';
  }
  $data['territory_number_map'] = $numberMap;
  $session = ['stage' => 'choose_territory', 'data' => $data];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);

  $msg = "Выберите территорию (Королевства и Особые территории).\n"
    . "Отправьте номер из списка или ID вручную в формате type:id.\n\n"
    . "Список:\n"
    . implode("\n", $territoryLines);
  vk_bot_send_message($userId, $msg);
};


if ($cmd === 'orders_menu') {
  $vkOrderMenu($userId);
  echo 'ok'; exit;
}

if ($cmd === 'order_my' || $cmd === 'order_drafts' || $cmd === 'order_verdicts' || $cmd === 'order_clarifications') {
  $store = orders_api_load_store();
  $rows = [];
  foreach (($store['orders'] ?? []) as $o) {
    if (!is_array($o)) continue;
    if ((int)($o['author_vk_user_id'] ?? 0) !== $userId) continue;
    $st = (string)($o['status'] ?? '');
    if ($cmd === 'order_drafts' && $st !== 'draft') continue;
    if ($cmd === 'order_verdicts' && !in_array($st, ['verdict_ready','approved','published'], true)) continue;
    if ($cmd === 'order_clarifications' && $st !== 'needs_clarification') continue;
    $rows[] = '• ' . (string)($o['title'] ?? 'Без названия') . ' [' . $st . '] #' . (string)($o['id'] ?? '');
  }
  vk_bot_send_message($userId, empty($rows) ? 'Записей пока нет.' : implode("
", array_slice($rows, -20)));
  echo 'ok'; exit;
}

if ($cmd === 'order_new') {
  $session = ['stage' => 'order_title', 'data' => ['order_form' => ['action_items' => [], 'public_images' => []]]];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, 'Подача приказа: шаг 1/6. Введите заголовок.');
  echo 'ok'; exit;
}

if (strpos($stage, 'order_') === 0) {
  if ($text === '/cancel' || $cmd === 'cancel') {
    vk_bot_set_user_session($sessions, $userId, ['stage' => 'start', 'data' => []]);
    vk_bot_save_sessions($sessions);
    vk_bot_send_message($userId, 'Подача приказа отменена.');
    echo 'ok'; exit;
  }
  $form = is_array($data['order_form'] ?? null) ? $data['order_form'] : ['action_items' => [], 'public_images' => []];
  if ($stage === 'order_title') {
    if ($text === '') { vk_bot_send_message($userId, 'Введите заголовок приказа.'); echo 'ok'; exit; }
    $form['title'] = $text;
    vk_bot_set_user_session($sessions, $userId, ['stage' => 'order_rp_post', 'data' => ['order_form' => $form]]);
    vk_bot_save_sessions($sessions);
    vk_bot_send_message($userId, 'Шаг 2/6. Введите основной РП-текст.');
    echo 'ok'; exit;
  }
  if ($stage === 'order_rp_post') {
    if ($text === '') { vk_bot_send_message($userId, 'Введите РП-текст приказа.'); echo 'ok'; exit; }
    $form['rp_post'] = $text;
    vk_bot_set_user_session($sessions, $userId, ['stage' => 'order_item_summary', 'data' => ['order_form' => $form]]);
    vk_bot_save_sessions($sessions);
    vk_bot_send_message($userId, 'Шаг 3/6. Введите короткий пункт действия. После каждого пункта отправляйте категорию: economy|politics|laws|diplomacy|military|religion|intrigue|other');
    echo 'ok'; exit;
  }
  if ($stage === 'order_item_summary') {
    if ($text === '') { vk_bot_send_message($userId, 'Введите пункт или «готово».'); echo 'ok'; exit; }
    if (mb_strtolower($text) === 'готово') {
      vk_bot_set_user_session($sessions, $userId, ['stage' => 'order_images', 'data' => ['order_form' => $form]]);
      vk_bot_save_sessions($sessions);
      vk_bot_send_message($userId, 'Шаг 4/6. Прикрепите изображения (фото/док) или напишите «далее».');
      echo 'ok'; exit;
    }
    $form['_pending_summary'] = $text;
    vk_bot_set_user_session($sessions, $userId, ['stage' => 'order_item_category', 'data' => ['order_form' => $form]]);
    vk_bot_save_sessions($sessions);
    vk_bot_send_message($userId, 'Категория для пункта?');
    echo 'ok'; exit;
  }
  if ($stage === 'order_item_category') {
    $cat = trim(mb_strtolower($text));
    if (!in_array($cat, ['economy','politics','laws','diplomacy','military','religion','intrigue','other'], true)) {
      vk_bot_send_message($userId, 'Неверная категория. Используйте economy|politics|laws|diplomacy|military|religion|intrigue|other');
      echo 'ok'; exit;
    }
    $form['action_items'][] = ['category' => $cat, 'summary' => (string)($form['_pending_summary'] ?? ''), 'details' => ''];
    unset($form['_pending_summary']);
    vk_bot_set_user_session($sessions, $userId, ['stage' => 'order_item_summary', 'data' => ['order_form' => $form]]);
    vk_bot_save_sessions($sessions);
    vk_bot_send_message($userId, 'Пункт добавлен. Следующий пункт или «готово».');
    echo 'ok'; exit;
  }
  if ($stage === 'order_images') {
    if (mb_strtolower($text) === 'далее') {
      $preview = 'Проверка приказа:
' . (string)($form['title'] ?? '') . "
Пунктов: " . count($form['action_items'] ?? []) . "
Изображений: " . count($form['public_images'] ?? []);
      vk_bot_set_user_session($sessions, $userId, ['stage' => 'order_confirm', 'data' => ['order_form' => $form]]);
      vk_bot_save_sessions($sessions);
      vk_bot_send_message($userId, $preview . "
Шаг 5/6. Отправить? (да/нет)");
      echo 'ok'; exit;
    }
    $img = vk_bot_extract_image_url($message);
    if ($img !== '') {
      $form['public_images'][] = ['url' => $img, 'visibility' => 'public', 'source' => 'vk'];
      vk_bot_set_user_session($sessions, $userId, ['stage' => 'order_images', 'data' => ['order_form' => $form]]);
      vk_bot_save_sessions($sessions);
      vk_bot_send_message($userId, 'Изображение прикреплено. Ещё или «далее».');
      echo 'ok'; exit;
    }
    vk_bot_send_message($userId, 'Прикрепите фото/док-изображение или отправьте «далее».');
    echo 'ok'; exit;
  }
  if ($stage === 'order_confirm') {
    if (mb_strtolower($text) !== 'да') {
      vk_bot_set_user_session($sessions, $userId, ['stage' => 'start', 'data' => []]);
      vk_bot_save_sessions($sessions);
      vk_bot_send_message($userId, 'Черновик сохранён в сессии. Начните снова через «Подать приказ».');
      echo 'ok'; exit;
    }
    $map = vk_bot_resolve_user_entity_for_orders($apps, $userId);
    if (!is_array($map)) {
      vk_bot_send_message($userId, 'Не найдено одобренное государство для подачи приказа. Проверьте в заявке статус «approved» и поля approved_entity_type/approved_entity_id.');
      echo 'ok'; exit;
    }
    $store = orders_api_load_store();
    $id = orders_api_next_id('ord');
    $items = [];
    foreach (($form['action_items'] ?? []) as $i => $it) {
      if (!is_array($it)) continue;
      $items[] = ['id'=>orders_api_next_id('ai'),'order_id'=>$id,'sort_index'=>$i+1,'category'=>(string)($it['category'] ?? 'other'),'summary'=>(string)($it['summary'] ?? ''),'details'=>(string)($it['details'] ?? ''),'requested_effects_hint'=>'','target_scope'=>'entity'];
    }
    $order = ['id'=>$id,'turn_year'=>orders_api_current_turn_year(),'turn_id'=>'turn_' . orders_api_current_turn_year(),'entity_type'=>$map['entity_type'],'entity_id'=>$map['entity_id'],'character_id'=>null,'author_user_id'=>'','author_vk_user_id'=>$userId,'source'=>'vk','title'=>(string)($form['title'] ?? 'Приказ'),'rp_post'=>(string)($form['rp_post'] ?? ''),'public_images'=>orders_api_normalize_attachment_list(array_values($form['public_images'] ?? []), 'public'),'private_attachments'=>[],'status'=>'submitted','created_at'=>gmdate('c'),'updated_at'=>gmdate('c'),'submitted_at'=>gmdate('c'),'version'=>1,'action_items'=>$items,'verdict'=>null,'effects'=>[],'publication'=>null,'audit_log'=>[],'attachment_registry'=>[]];
    $order['attachment_registry'] = orders_api_build_attachment_registry($order);
    orders_api_audit_append($order,'order_submitted','vk:' . $userId,[]);
    $store['orders'][] = $order;
    orders_api_save_store($store);
    orders_api_event_append('order_submitted', $id, ['source' => 'vk']);
    vk_bot_set_user_session($sessions, $userId, ['stage' => 'start', 'data' => []]);
    vk_bot_save_sessions($sessions);
    vk_bot_send_message($userId, 'Приказ отправлен на рассмотрение. № ' . $id);
    echo 'ok'; exit;
  }
}

if ($cmd === 'start' || $text === '/start' || $text === 'Начать') {
  $sendMainMenu($userId, $hasApprovedStateApp, $approvedCharacterApp, $pendingCharacterApp);
  vk_bot_set_user_session($sessions, $userId, ['stage' => 'start', 'data' => []]);
  vk_bot_save_sessions($sessions);
  echo 'ok'; exit;
}

if ($cmd === 'login_panel' && is_array($approvedApp)) {
  $tok = vk_bot_create_player_admin_token((string)$approvedApp['approved_entity_type'], (string)$approvedApp['approved_entity_id'], (string)($approvedApp['player_admin_token'] ?? ''));
  if (is_array($tok)) {
    foreach ($apps as $i => $app) {
      if (!is_array($app)) continue;
      if ((string)($app['id'] ?? '') !== (string)$approvedApp['id']) continue;
      $apps[$i]['player_admin_token'] = $tok['token'];
      $apps[$i]['player_admin_path'] = $tok['path'];
    }
    vk_bot_save_applications($apps);
    $url = $cfg['public_base_url'] !== '' ? ($cfg['public_base_url'] . $tok['path']) : $tok['path'];
    vk_bot_send_message($userId, 'Новый токен создан. Вход в панель игрока: ' . $url);
  }
  echo 'ok'; exit;
}

if ($cmd === 'family_tree_login' && is_array($approvedCharacterApp)) {
  $path = trim((string)($approvedCharacterApp['genealogy_admin_path'] ?? ''));
  if ($path === '') {
    vk_bot_send_message($userId, 'Ссылка генеалогии пока не создана. Обратитесь к администратору.');
    echo 'ok'; exit;
  }
  $url = $cfg['public_base_url'] !== '' ? ($cfg['public_base_url'] . $path) : $path;
  vk_bot_send_message($userId, 'Вход в генеалогию рода: ' . $url);
  echo 'ok'; exit;
}

if ($cmd === 'character_form_new') {
  if (!is_array($approvedApp)) {
    vk_bot_send_message($userId, 'Сначала получите одобрение заявки на государство.');
    echo 'ok'; exit;
  }
  if (is_array($approvedCharacterApp)) {
    vk_bot_send_message($userId, 'Анкета персонажа уже одобрена. Используйте кнопку «Генеалогия рода».');
    echo 'ok'; exit;
  }
  $session = ['stage' => 'character_birth_year', 'data' => ['character_form' => ['relatives' => []]]];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, 'Анкета персонажа: 1/6 Укажите год рождения правителя (числом).');
  echo 'ok'; exit;
}

if ($cmd === 'register_new') {
  if ($hasApprovedStateApp) {
    vk_bot_send_message($userId, 'У вас уже есть одобренная заявка на государство. Регистрация нового государства недоступна.');
    echo 'ok'; exit;
  }
  $session = ['stage' => 'choose_state_type', 'data' => []];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, 'Выберите тип государства:', vk_bot_keyboard([
    vk_bot_btn('Малый Дом', 'type_minor_house', 'primary'),
    vk_bot_btn('Вольный Город', 'type_free_city', 'positive'),
  ]));
  echo 'ok'; exit;
}

if ($cmd === 'register_existing') {
  if ($hasApprovedStateApp) {
    vk_bot_send_message($userId, 'У вас уже есть одобренная заявка на государство. Повторная регистрация недоступна.');
    echo 'ok'; exit;
  }
  $session = ['stage' => 'choose_existing_entity_type', 'data' => []];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, 'Выберите тип существующей сущности:', vk_bot_keyboard([
    vk_bot_btn('Большой Дом', 'existing_type_great_house', 'primary'),
    vk_bot_btn('Малый Дом', 'existing_type_minor_house', 'secondary'),
    vk_bot_btn('Вольный Город', 'existing_type_free_city', 'positive'),
  ]));
  echo 'ok'; exit;
}

if ($cmd === 'character_image_start') {
  if ($remainingImageGenerations <= 0) {
    vk_bot_send_message($userId, 'Лимит генераций исчерпан (10 из 10). Администратор может сбросить лимит в админке.');
    echo 'ok'; exit;
  }
  $session = ['stage' => 'character_image_prompt', 'data' => $data];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, 'Опишите внешность персонажа в свободной форме.' . "\n" . 'Осталось генераций: ' . $remainingImageGenerations . ' из ' . vk_bot_image_user_limit() . '.');
  echo 'ok'; exit;
}

if ($stage === 'character_image_prompt') {
  if ($text === '') {
    vk_bot_send_message($userId, 'Опишите внешность персонажа текстом.');
    echo 'ok'; exit;
  }
  if ($remainingImageGenerations <= 0) {
    vk_bot_set_user_session($sessions, $userId, ['stage' => 'start', 'data' => []]);
    vk_bot_save_sessions($sessions);
    vk_bot_send_message($userId, 'Лимит генераций исчерпан (10 из 10). Администратор может сбросить лимит в админке.');
    echo 'ok'; exit;
  }

  // Сбрасываем stage до запуска долгой генерации: если VK повторно доставит
  // то же событие из‑за таймаута, бот не должен снова уходить в цикл генерации.
  vk_bot_set_user_session($sessions, $userId, ['stage' => 'start', 'data' => []]);
  vk_bot_save_sessions($sessions);

  vk_bot_send_message($userId, 'Генерирую портрет, это может занять до минуты…');
  $gen = vk_bot_generate_character_image($text);
  $routerHttpCode = (int)($gen['http_code'] ?? 0);
  $routerResponse = (string)($gen['router_response'] ?? '');
  if (!(bool)($gen['ok'] ?? false)) {
    $reason = (string)($gen['error'] ?? 'unknown');
    vk_bot_log_error('character_image_failed user=' . $userId . ' reason=' . $reason);
    vk_bot_append_image_generation_log([
      'vk_user_id' => $userId,
      'prompt' => $text,
      'ok' => false,
      'error' => $reason,
      'http_code' => $routerHttpCode,
      'router_response' => $routerResponse,
    ]);
    $hint = $reason === 'missing_api_key'
      ? 'Не настроен API-ключ RouterAI в админке VK.'
      : 'Не удалось сгенерировать изображение. Попробуйте позже.';
    vk_bot_send_message($userId, $hint);
    echo 'ok'; exit;
  }

  vk_bot_append_image_generation_log([
    'vk_user_id' => $userId,
    'prompt' => $text,
    'ok' => true,
    'error' => '',
    'http_code' => $routerHttpCode,
    'router_response' => $routerResponse,
  ]);

  $rawImage = is_string($gen['raw'] ?? null) ? (string)$gen['raw'] : '';
  $attachment = vk_bot_upload_message_photo_blob($userId, $rawImage, 'character_portrait.png');
  if ($attachment === '') {
    vk_bot_log_error('character_image_upload_failed user=' . $userId);
    vk_bot_send_message($userId, 'Портрет сгенерирован, но не удалось загрузить его в VK. Попробуйте ещё раз позже.');
    echo 'ok'; exit;
  }

  $usage[(string)$userId] = [
    'count' => $currentUsage + 1,
    'updated_at' => time(),
  ];
  vk_bot_save_image_usage($usage);
  $left = max(0, vk_bot_image_user_limit() - (int)$usage[(string)$userId]['count']);

  vk_bot_set_user_session($sessions, $userId, ['stage' => 'start', 'data' => []]);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, 'Готово! Ваш портрет. Осталось генераций: ' . $left . ' из ' . vk_bot_image_user_limit() . '.', null, $attachment);
  echo 'ok'; exit;
}

if ($cmd === 'type_minor_house' || $cmd === 'type_free_city') {
  $data['state_type'] = $cmd === 'type_minor_house' ? 'minor_house' : 'free_city';
  $territories = vk_bot_selectable_territories($state);
  $data['territories'] = $territories;
  $sendTerritorySelection($userId, $sessions, $data, $territories);
  echo 'ok'; exit;
}

if ($stage === 'character_birth_year') {
  $birthYear = preg_match('/^-?\d{1,4}$/', $text) ? (int)$text : null;
  if ($birthYear === null) {
    vk_bot_send_message($userId, 'Укажите корректный год рождения числом (например, 284).');
    echo 'ok'; exit;
  }
  $data['character_form']['birth_year'] = $birthYear;
  $session = ['stage' => 'character_rel_status', 'data' => $data];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, '2/6 Родственники: укажите статус первого родственника (брат/сестра, родитель, ребенок, супруг).');
  echo 'ok'; exit;
}

if ($stage === 'character_rel_status') {
  $normalized = mb_strtolower($text);
  $map = [
    'брат' => 'sibling', 'сестра' => 'sibling', 'брат/сестра' => 'sibling',
    'родитель' => 'parent', 'родители' => 'parent',
    'ребенок' => 'child', 'ребёнок' => 'child', 'дети' => 'child',
    'супруг' => 'spouse', 'супруга' => 'spouse',
  ];
  $relType = $map[$normalized] ?? '';
  if ($relType === '') {
    vk_bot_send_message($userId, 'Некорректный статус. Допустимо: брат/сестра, родитель, ребенок, супруг.');
    echo 'ok'; exit;
  }
  $data['character_form']['current_relative'] = ['status' => $relType];
  $session = ['stage' => 'character_rel_name', 'data' => $data];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, 'Введите имя родственника.');
  echo 'ok'; exit;
}

if ($stage === 'character_rel_name') {
  if ($text === '') { echo 'ok'; exit; }
  $data['character_form']['current_relative']['name'] = $text;
  $session = ['stage' => 'character_rel_birth_year', 'data' => $data];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, 'Введите год рождения родственника.');
  echo 'ok'; exit;
}

if ($stage === 'character_rel_birth_year') {
  $birthYear = preg_match('/^-?\d{1,4}$/', $text) ? (int)$text : null;
  if ($birthYear === null) {
    vk_bot_send_message($userId, 'Некорректный год. Введите число.');
    echo 'ok'; exit;
  }
  $data['character_form']['current_relative']['birth_year'] = $birthYear;
  $session = ['stage' => 'character_rel_photo', 'data' => $data];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, 'Отправьте квадратное фото родственника (опционально) или напишите «пропустить».');
  echo 'ok'; exit;
}

if ($stage === 'character_rel_photo') {
  $photo = '';
  if (mb_strtolower($text) !== 'пропустить' && $text !== '') $photo = $text;
  if ($photo === '') $photo = vk_bot_extract_image_url($message);
  $currentRel = is_array($data['character_form']['current_relative'] ?? null) ? $data['character_form']['current_relative'] : [];
  $currentRel['photo_url'] = $photo;
  $data['character_form']['relatives'][] = $currentRel;
  unset($data['character_form']['current_relative']);
  $session = ['stage' => 'character_rel_more', 'data' => $data];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, 'Родственник добавлен. Напишите «ещё», чтобы добавить родственника, или «готово», чтобы продолжить.');
  echo 'ok'; exit;
}

if ($stage === 'character_rel_more') {
  $t = mb_strtolower($text);
  if ($t === 'ещё' || $t === 'еще') {
    $session = ['stage' => 'character_rel_status', 'data' => $data];
    vk_bot_set_user_session($sessions, $userId, $session);
    vk_bot_save_sessions($sessions);
    vk_bot_send_message($userId, 'Укажите статус следующего родственника.');
    echo 'ok'; exit;
  }
  if ($t !== 'готово') {
    vk_bot_send_message($userId, 'Напишите «ещё» или «готово».');
    echo 'ok'; exit;
  }
  $session = ['stage' => 'character_personality', 'data' => $data];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, '3/6 Характер (чем подробнее, тем лучше):');
  echo 'ok'; exit;
}

if ($stage === 'character_personality') {
  if ($text === '') { echo 'ok'; exit; }
  $data['character_form']['personality'] = $text;
  $session = ['stage' => 'character_biography', 'data' => $data];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, '4/6 Биография (чем подробнее, тем лучше):');
  echo 'ok'; exit;
}

if ($stage === 'character_biography') {
  if ($text === '') { echo 'ok'; exit; }
  $data['character_form']['biography'] = $text;
  $session = ['stage' => 'character_skills', 'data' => $data];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, '5/6 Навыки:');
  echo 'ok'; exit;
}

if ($stage === 'character_skills') {
  if ($text === '') { echo 'ok'; exit; }
  $data['character_form']['skills'] = $text;
  $session = ['stage' => 'character_photo', 'data' => $data];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, '6/6 Фото правителя (квадратное): отправьте изображение или ссылку.');
  echo 'ok'; exit;
}

if ($stage === 'character_photo') {
  $photo = $text !== '' ? $text : '';
  if ($photo === '') $photo = vk_bot_extract_image_url($message);
  if ($photo === '') {
    vk_bot_send_message($userId, 'Не удалось получить фото. Отправьте изображение или ссылку.');
    echo 'ok'; exit;
  }
  $data['character_form']['photo_url'] = $photo;

  $charAppId = 'char_app_' . date('Ymd_His') . '_' . $userId . '_' . random_int(100, 999);
  $charApps[] = [
    'id' => $charAppId,
    'created_at' => time(),
    'status' => 'pending',
    'vk_user_id' => $userId,
    'state_application_id' => (string)($approvedApp['id'] ?? ''),
    'approved_entity_type' => (string)($approvedApp['approved_entity_type'] ?? ''),
    'approved_entity_id' => (string)($approvedApp['approved_entity_id'] ?? ''),
    'form' => $data['character_form'],
  ];
  vk_bot_save_character_applications($charApps);

  vk_bot_set_user_session($sessions, $userId, ['stage' => 'start', 'data' => []]);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, 'Анкета персонажа отправлена и ожидает одобрения в админке.');
  echo 'ok'; exit;
}

if ($stage === 'choose_state_type') {
  if ($text === 'Малый Дом') $cmd = 'type_minor_house';
  if ($text === 'Вольный Город') $cmd = 'type_free_city';
  if ($cmd === 'type_minor_house' || $cmd === 'type_free_city') {
    $data['state_type'] = $cmd === 'type_minor_house' ? 'minor_house' : 'free_city';
    $territories = vk_bot_selectable_territories($state);
    $data['territories'] = $territories;
    $sendTerritorySelection($userId, $sessions, $data, $territories);
    echo 'ok'; exit;
  }
}


if ($cmd === 'existing_type_great_house' || $cmd === 'existing_type_minor_house' || $cmd === 'existing_type_free_city') {
  $entityType = $cmd === 'existing_type_great_house'
    ? 'great_houses'
    : ($cmd === 'existing_type_minor_house' ? 'minor_houses' : 'free_cities');
  $entities = $collectExistingEntitiesByType($state, $entityType);
  if (empty($entities)) {
    vk_bot_send_message($userId, 'Сущностей выбранного типа не найдено. Выберите другой тип.');
    echo 'ok'; exit;
  }
  $sendExistingEntitySelection($userId, $sessions, $data, $entities, $entityType);
  echo 'ok'; exit;
}

if ($stage === 'choose_existing_entity_type') {
  if ($text === 'Большой Дом') $cmd = 'existing_type_great_house';
  if ($text === 'Малый Дом') $cmd = 'existing_type_minor_house';
  if ($text === 'Вольный Город') $cmd = 'existing_type_free_city';
  if ($cmd === 'existing_type_great_house' || $cmd === 'existing_type_minor_house' || $cmd === 'existing_type_free_city') {
    $entityType = $cmd === 'existing_type_great_house'
      ? 'great_houses'
      : ($cmd === 'existing_type_minor_house' ? 'minor_houses' : 'free_cities');
    $entities = $collectExistingEntitiesByType($state, $entityType);
    if (empty($entities)) {
      vk_bot_send_message($userId, 'Сущностей выбранного типа не найдено. Выберите другой тип.');
      echo 'ok'; exit;
    }
    $sendExistingEntitySelection($userId, $sessions, $data, $entities, $entityType);
    echo 'ok'; exit;
  }
}

if ($stage === 'choose_existing_entity') {
  $entityType = trim((string)($data['existing_entity_type'] ?? ''));
  $entityId = '';
  if (preg_match('/^(\d{1,4})$/', $text, $m)) {
    $pick = (string)$m[1];
    $sel = $data['entity_number_map'][$pick] ?? null;
    if (is_array($sel) && (string)($sel['entity_type'] ?? '') === $entityType) {
      $entityId = trim((string)($sel['entity_id'] ?? ''));
    }
  } elseif (preg_match('/^(great_houses|minor_houses|free_cities):(.+)$/u', $text, $m)) {
    if ($m[1] === $entityType) $entityId = trim($m[2]);
  }
  if ($entityType === '' || $entityId === '' || !player_admin_validate_entity_ref($state, $entityType, $entityId)) {
    vk_bot_send_message($userId, 'Некорректный выбор. Отправьте номер из списка или точный ID сущности.');
    echo 'ok'; exit;
  }

  $entity = player_admin_resolve_entity_ref($state, $entityType, $entityId);
  $entityName = trim((string)($entity['name'] ?? $entityId));
  if ($entityName === '') $entityName = $entityId;

  $appId = 'app_' . date('Ymd_His') . '_' . $userId . '_' . random_int(100, 999);
  $apps[] = [
    'id' => $appId,
    'created_at' => time(),
    'status' => 'pending',
    'vk_user_id' => $userId,
    'registration_mode' => 'existing',
    'selected_entity_type' => $entityType,
    'selected_entity_id' => $entityId,
    'selected_entity_name' => $entityName,
  ];
  vk_bot_save_applications($apps);

  vk_bot_set_user_session($sessions, $userId, ['stage' => 'start', 'data' => []]);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, 'Заявка на доступ к существующей сущности отправлена в админку и ожидает одобрения.');
  echo 'ok'; exit;
}

if ($stage === 'choose_territory') {
  $territoryType = ''; $territoryId = '';
  if (strpos($cmd, 'territory:') === 0) {
    $parts = explode(':', $cmd, 3);
    $territoryType = (string)($parts[1] ?? '');
    $territoryId = (string)($parts[2] ?? '');
  } elseif (preg_match('/^(\d{1,3})$/', $text, $m)) {
    $pick = (string)$m[1];
    $sel = $data['territory_number_map'][$pick] ?? null;
    if (is_array($sel)) {
      $territoryType = trim((string)($sel['type'] ?? ''));
      $territoryId = trim((string)($sel['id'] ?? ''));
    }
  } elseif (preg_match('/^(kingdoms|special_territories):(.+)$/u', $text, $m)) {
    $territoryType = $m[1]; $territoryId = trim($m[2]);
  }
  if ($territoryType === '' || $territoryId === '') { echo 'ok'; exit; }

  $free = vk_bot_free_provinces_for_territory($state, $territoryType, $territoryId);
  if (empty($free)) {
    vk_bot_send_message($userId, 'На выбранной территории нет свободных провинций. Выберите другую.');
    echo 'ok'; exit;
  }

  $imgPath = vk_bot_render_territory_free_map($state, $territoryType, $territoryId, $free);
  $numberMap = [];
  foreach ($free as $i => $row) $numberMap[(string)($i + 1)] = (int)$row['pid'];
  $data['territory_type'] = $territoryType;
  $data['territory_id'] = $territoryId;
  $data['province_number_map'] = $numberMap;
  $data['territory_image_path'] = $imgPath;
  $session = ['stage' => 'choose_province_number', 'data' => $data];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);

  $msg = "Выберите свободную провинцию: отправьте номер от 1 до " . count($free) . ".";
  if (is_string($imgPath) && $imgPath !== '') {
    if ($cfg['public_base_url'] !== '') {
      $msg .= "\nКарта территории: " . $cfg['public_base_url'] . $imgPath;
    } else {
      vk_bot_log_error('render_map_warning: public_base_url_empty image_path=' . $imgPath);
      $msg .= "\nКарта с нумерацией создана, но ссылка недоступна: не настроен public_base_url.";
    }
  } else {
    $renderReason = vk_bot_get_last_render_error() ?? 'unknown';
    vk_bot_log_error('render_map_warning: image_not_generated reason=' . $renderReason . ' type=' . $territoryType . ' id=' . $territoryId);
    $msg .= "\nНе удалось сгенерировать карту с нумерацией (" . $renderReason . ") — обратитесь к администратору.";
  }
  vk_bot_send_message($userId, $msg);
  echo 'ok'; exit;
}

if ($stage === 'choose_province_number') {
  $num = trim($text);
  $pid = (int)(($data['province_number_map'][$num] ?? 0));
  if ($pid <= 0) {
    vk_bot_send_message($userId, 'Некорректный номер. Отправьте число из списка.');
    echo 'ok'; exit;
  }
  $data['chosen_pid'] = $pid;
  $data['form'] = [];
  $session = ['stage' => 'form_state_name', 'data' => $data];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, '1/6 Название государства:');
  echo 'ok'; exit;
}

$formOrder = [
  'form_state_name' => ['k' => 'state_name', 'next' => 'form_capital_name', 'q' => '2/6 Название столицы:'],
  'form_capital_name' => ['k' => 'capital_name', 'next' => 'form_ruler_name', 'q' => '3/6 Полное имя правителя:'],
  'form_ruler_name' => ['k' => 'ruler_name', 'next' => 'form_ruler_house', 'q' => '4/6 Род правителя:'],
  'form_ruler_house' => ['k' => 'ruler_house', 'next' => 'form_lore', 'q' => '5/6 Краткий лор государства:'],
  'form_lore' => ['k' => 'lore', 'next' => 'form_coa_svg', 'q' => '6/6 Герб (SVG файлом или SVG-текстом):'],
];
if (isset($formOrder[$stage])) {
  $def = $formOrder[$stage];
  if ($text === '') { echo 'ok'; exit; }
  $data['form'][$def['k']] = $text;
  $session = ['stage' => $def['next'], 'data' => $data];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, $def['q']);
  echo 'ok'; exit;
}

if ($stage === 'form_coa_svg') {
  $coa = $text;
  if (trim($coa) === '' && is_array($message['attachments'] ?? null)) {
    foreach ($message['attachments'] as $a) {
      if (!is_array($a) || ($a['type'] ?? '') !== 'doc') continue;
      $doc = $a['doc'] ?? null;
      if (!is_array($doc)) continue;
      $ext = mb_strtolower(trim((string)($doc['ext'] ?? '')));
      if ($ext !== 'svg') continue;
      $coa = trim((string)($doc['url'] ?? ''));
      break;
    }
  }
  if (trim($coa) === '') {
    vk_bot_send_message($userId, 'Не удалось получить SVG. Пришлите SVG-файл или SVG-текст.');
    echo 'ok'; exit;
  }
  $data['form']['coa_svg'] = $coa;

  $appId = 'app_' . date('Ymd_His') . '_' . $userId . '_' . random_int(100, 999);
  $apps[] = [
    'id' => $appId,
    'created_at' => time(),
    'status' => 'pending',
    'vk_user_id' => $userId,
    'state_type' => (string)($data['state_type'] ?? ''),
    'territory_type' => (string)($data['territory_type'] ?? ''),
    'territory_id' => (string)($data['territory_id'] ?? ''),
    'chosen_pid' => (int)($data['chosen_pid'] ?? 0),
    'territory_image_path' => (string)($data['territory_image_path'] ?? ''),
    'form' => $data['form'],
  ];
  vk_bot_save_applications($apps);

  vk_bot_set_user_session($sessions, $userId, ['stage' => 'start', 'data' => []]);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, 'Заявка отправлена в админку и ожидает одобрения.');
  echo 'ok'; exit;
}

echo 'ok';
