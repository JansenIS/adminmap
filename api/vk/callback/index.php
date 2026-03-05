<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/vk_bot_api.php';

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
$state = api_load_state();
$session = vk_bot_user_session($sessions, $userId);
$stage = (string)($session['stage'] ?? 'start');
$data = is_array($session['data'] ?? null) ? $session['data'] : [];

$approvedApp = null;
foreach ($apps as $app) {
  if (!is_array($app)) continue;
  if ((int)($app['vk_user_id'] ?? 0) !== $userId) continue;
  if (($app['status'] ?? '') !== 'approved') continue;
  $approvedApp = $app;
}

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

if ($cmd === 'start' || $text === '/start' || $text === 'Начать') {
  $btns = [vk_bot_btn('Регистрация нового государства', 'register_new', 'primary')];
  if (is_array($approvedApp)) $btns[] = vk_bot_btn('Войти в панель игрока', 'login_panel', 'positive');
  else $btns[] = vk_bot_btn('Сесть за существующее (скоро)', 'existing_disabled', 'secondary');
  vk_bot_send_message($userId, 'Добро пожаловать. Выберите действие:', vk_bot_keyboard($btns));
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

if ($cmd === 'register_new') {
  $session = ['stage' => 'choose_state_type', 'data' => []];
  vk_bot_set_user_session($sessions, $userId, $session);
  vk_bot_save_sessions($sessions);
  vk_bot_send_message($userId, 'Выберите тип государства:', vk_bot_keyboard([
    vk_bot_btn('Малый Дом', 'type_minor_house', 'primary'),
    vk_bot_btn('Вольный Город', 'type_free_city', 'positive'),
  ]));
  echo 'ok'; exit;
}

if ($cmd === 'existing_disabled') {
  vk_bot_send_message($userId, 'Функция посадки за существующее государство пока не активна.');
  echo 'ok'; exit;
}

if ($cmd === 'type_minor_house' || $cmd === 'type_free_city') {
  $data['state_type'] = $cmd === 'type_minor_house' ? 'minor_house' : 'free_city';
  $territories = vk_bot_selectable_territories($state);
  $data['territories'] = $territories;
  $sendTerritorySelection($userId, $sessions, $data, $territories);
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
