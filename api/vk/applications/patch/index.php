<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/lib/vk_bot_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, vk_bot_data_mtime());
}
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, vk_bot_data_mtime());
$appId = trim((string)($payload['id'] ?? ''));
$action = trim((string)($payload['action'] ?? 'update'));
if ($appId === '') api_json_response(['error' => 'missing_id'], 400, vk_bot_data_mtime());

$apps = vk_bot_load_applications();
$idx = null;
foreach ($apps as $i => $row) {
  if (!is_array($row)) continue;
  if ((string)($row['id'] ?? '') !== $appId) continue;
  $idx = $i; break;
}
if ($idx === null) api_json_response(['error' => 'not_found'], 404, vk_bot_data_mtime());
$app = $apps[$idx];

if ($action === 'update') {
  $patch = $payload['patch'] ?? null;
  if (!is_array($patch)) api_json_response(['error' => 'invalid_patch'], 400, vk_bot_data_mtime());
  $app = array_merge($app, $patch);
  $apps[$idx] = $app;
  vk_bot_save_applications($apps);
  api_json_response(['ok' => true, 'item' => $app], 200, vk_bot_data_mtime());
}

if ($action === 'approve') {
  if (($app['status'] ?? '') !== 'pending') api_json_response(['error' => 'status_not_pending'], 400, vk_bot_data_mtime());
  $state = api_load_state();
  $pid = (int)($app['chosen_pid'] ?? 0);
  if ($pid <= 0 || !is_array($state['provinces'][(string)$pid] ?? null)) api_json_response(['error' => 'province_not_found'], 400, vk_bot_data_mtime());
  $form = is_array($app['form'] ?? null) ? $app['form'] : [];
  $stateName = trim((string)($form['state_name'] ?? 'Новая держава'));
  $capitalName = trim((string)($form['capital_name'] ?? ('Провинция ' . $pid)));
  $rulerName = trim((string)($form['ruler_name'] ?? ''));
  $rulerHouse = trim((string)($form['ruler_house'] ?? ''));
  $lore = trim((string)($form['lore'] ?? ''));
  $coa = trim((string)($form['coa_svg'] ?? ''));
  $kind = trim((string)($app['state_type'] ?? 'minor_house'));

  $entityId = vk_bot_slug($stateName);
  $entityType = $kind === 'free_city' ? 'free_cities' : 'minor_houses';
  $entity = [
    'name' => $stateName,
    'color' => '#777777',
    'capital_pid' => $pid,
    'province_pids' => [$pid],
    'ruler' => $rulerName,
    'emblem_svg' => str_starts_with($coa, '<svg') ? $coa : '',
  ];
  if (!isset($state[$entityType]) || !is_array($state[$entityType])) $state[$entityType] = [];
  $suffix = 1;
  $baseId = $entityId;
  while (isset($state[$entityType][$entityId])) { $suffix++; $entityId = $baseId . '_' . $suffix; }
  $state[$entityType][$entityId] = $entity;

  if ($entityType === 'minor_houses') {
    $greatHouseId = trim((string)($state['provinces'][(string)$pid]['great_house_id'] ?? ''));
    if ($greatHouseId === '' || !is_array($state['great_houses'][$greatHouseId] ?? null)) {
      api_json_response(['error' => 'great_house_not_found_for_minor_house', 'pid' => $pid], 400, vk_bot_data_mtime());
    }
    if (!is_array($state['great_houses'][$greatHouseId]['minor_house_layer'] ?? null)) {
      $state['great_houses'][$greatHouseId]['minor_house_layer'] = ['vassals' => []];
    }
    if (!is_array($state['great_houses'][$greatHouseId]['minor_house_layer']['vassals'] ?? null)) {
      $state['great_houses'][$greatHouseId]['minor_house_layer']['vassals'] = [];
    }
    $vassals = $state['great_houses'][$greatHouseId]['minor_house_layer']['vassals'];
    $exists = false;
    foreach ($vassals as $v) {
      if (!is_array($v)) continue;
      if (trim((string)($v['id'] ?? '')) !== $entityId) continue;
      $exists = true;
      break;
    }
    if (!$exists) {
      $vassals[] = [
        'id' => $entityId,
        'name' => $stateName,
        'ruler' => $rulerName,
        'province_pids' => [$pid],
      ];
      $state['great_houses'][$greatHouseId]['minor_house_layer']['vassals'] = $vassals;
    }
  }

  $prov = $state['provinces'][(string)$pid];
  $prov['name'] = $capitalName;
  $prov['owner'] = $rulerName;
  $prov['wiki_description'] = $lore;
  if (str_starts_with($coa, '<svg')) $prov['emblem_svg'] = $coa;
  if ($entityType === 'minor_houses') {
    $prov['minor_house_id'] = $entityId;
    $prov['free_city_id'] = '';
    if (trim((string)($prov['great_house_id'] ?? '')) === '') {
      api_json_response(['error' => 'minor_house_requires_great_house', 'pid' => $pid], 400, vk_bot_data_mtime());
    }
  } else {
    $prov['free_city_id'] = $entityId;
    $prov['minor_house_id'] = '';
  }
  $state['provinces'][(string)$pid] = $prov;

  if (!is_array($state['people'] ?? null)) $state['people'] = [];
  if ($rulerName !== '' && !in_array($rulerName, $state['people'], true)) $state['people'][] = $rulerName;
  if (!is_array($state['people_profiles'] ?? null)) $state['people_profiles'] = [];
  if ($rulerName !== '') {
    $state['people_profiles'][$rulerName] = [
      'photo_url' => '',
      'bio' => $rulerHouse !== '' ? ('Род: ' . $rulerHouse) : '',
    ];
  }

  if (!api_save_state($state)) api_json_response(['error' => 'state_write_failed'], 500, vk_bot_data_mtime());

  $genealogyPath = api_repo_root() . '/data/genealogy_tree.json';
  $genealogy = vk_bot_load_json_file($genealogyPath, ['characters' => [], 'relationships' => [], 'clans' => []]);
  if (!is_array($genealogy['characters'] ?? null)) $genealogy['characters'] = [];
  if ($rulerName !== '') {
    $charId = 'vk_' . substr(hash('sha1', $rulerName . ':' . $entityId), 0, 12);
    $genealogy['characters'][] = [
      'id' => $charId,
      'name' => $rulerName,
      'title' => $stateName,
      'birth_year' => null,
      'death_year' => null,
      'photo_url' => '',
      'clan' => $rulerHouse,
      'clan_branch_type' => 'main',
      'is_clan_founder' => false,
      'notes' => '',
    ];
  }
  api_atomic_write_json($genealogyPath, $genealogy);

  $tok = vk_bot_create_player_admin_token($entityType, $entityId, (string)($app['player_admin_token'] ?? ''));
  $cfg = vk_bot_load_config();
  $path = is_array($tok) ? (string)$tok['path'] : '';
  $fullLink = ($cfg['public_base_url'] !== '' && $path !== '') ? ($cfg['public_base_url'] . $path) : $path;

  $app['status'] = 'approved';
  $app['approved_at'] = time();
  $app['approved_entity_type'] = $entityType;
  $app['approved_entity_id'] = $entityId;
  $app['player_admin_token'] = is_array($tok) ? (string)$tok['token'] : '';
  $app['player_admin_path'] = $path;
  $apps[$idx] = $app;
  vk_bot_save_applications($apps);

  $userId = (int)($app['vk_user_id'] ?? 0);
  if ($userId > 0 && $fullLink !== '') {
    vk_bot_send_message($userId, 'Ваша заявка одобрена! Вход в панель игрока: ' . $fullLink . "\nКнопка «Войти в панель игрока» в боте теперь активна.");
  }

  api_json_response(['ok' => true, 'item' => $app], 200, vk_bot_data_mtime());
}

if ($action === 'reject') {
  $app['status'] = 'rejected';
  $app['rejected_at'] = time();
  $apps[$idx] = $app;
  vk_bot_save_applications($apps);
  api_json_response(['ok' => true, 'item' => $app], 200, vk_bot_data_mtime());
}

api_json_response(['error' => 'unknown_action'], 400, vk_bot_data_mtime());
