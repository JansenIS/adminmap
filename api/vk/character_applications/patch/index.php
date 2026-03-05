<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/lib/vk_bot_api.php';
require_once dirname(__DIR__, 3) . '/lib/genealogy_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, vk_bot_data_mtime());
}
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, vk_bot_data_mtime());
$appId = trim((string)($payload['id'] ?? ''));
$action = trim((string)($payload['action'] ?? 'update'));
if ($appId === '') api_json_response(['error' => 'missing_id'], 400, vk_bot_data_mtime());

$apps = vk_bot_load_character_applications();
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
  vk_bot_save_character_applications($apps);
  api_json_response(['ok' => true, 'item' => $app], 200, vk_bot_data_mtime());
}

if ($action === 'approve') {
  if (($app['status'] ?? '') !== 'pending') api_json_response(['error' => 'status_not_pending'], 400, vk_bot_data_mtime());

  $form = is_array($app['form'] ?? null) ? $app['form'] : [];
  $birthYear = isset($form['birth_year']) && $form['birth_year'] !== '' ? (int)$form['birth_year'] : null;
  $personality = trim((string)($form['personality'] ?? ''));
  $biography = trim((string)($form['biography'] ?? ''));
  $skills = trim((string)($form['skills'] ?? ''));
  $photo = trim((string)($form['photo_url'] ?? ''));
  $relatives = is_array($form['relatives'] ?? null) ? $form['relatives'] : [];

  $entityType = trim((string)($app['approved_entity_type'] ?? ''));
  $entityId = trim((string)($app['approved_entity_id'] ?? ''));
  if ($entityType === '' || $entityId === '') api_json_response(['error' => 'missing_entity_binding'], 400, vk_bot_data_mtime());

  $state = api_load_state();
  $entity = is_array($state[$entityType][$entityId] ?? null) ? $state[$entityType][$entityId] : null;
  if (!is_array($entity)) api_json_response(['error' => 'entity_not_found'], 400, vk_bot_data_mtime());

  $rulerName = trim((string)($entity['ruler'] ?? ''));
  $stateName = trim((string)($entity['name'] ?? $entityId));
  $storedRulerPhoto = $photo !== '' ? vk_bot_store_remote_photo($photo, $rulerName !== '' ? $rulerName : 'ruler') : null;
  if ($photo !== '' && !is_string($storedRulerPhoto)) api_json_response(['error' => 'ruler_photo_upload_failed'], 400, vk_bot_data_mtime());
  $clan = '';
  $profile = is_array($state['people_profiles'][$rulerName] ?? null) ? $state['people_profiles'][$rulerName] : null;
  if (is_array($profile) && preg_match('/^Род:\s*(.+)$/um', (string)($profile['bio'] ?? ''), $m)) {
    $clan = trim((string)($m[1] ?? ''));
  }

  if (!is_array($state['people_profiles'] ?? null)) $state['people_profiles'] = [];
  if (!is_array($state['people_profiles'][$rulerName] ?? null)) $state['people_profiles'][$rulerName] = ['photo_url' => '', 'bio' => ''];

  $bioParts = [];
  if ($personality !== '') $bioParts[] = "## Характер\n" . $personality;
  if ($biography !== '') $bioParts[] = "## Биография\n" . $biography;
  if ($skills !== '') $bioParts[] = "## Навыки\n" . $skills;
  $state['people_profiles'][$rulerName]['bio'] = trim(implode("\n\n", $bioParts));
  if (is_string($storedRulerPhoto) && $storedRulerPhoto !== '') $state['people_profiles'][$rulerName]['photo_url'] = $storedRulerPhoto;

  $capitalPid = (int)($entity['capital_pid'] ?? 0);
  if ($capitalPid > 0 && is_array($state['provinces'][(string)$capitalPid] ?? null)) {
    $prov = $state['provinces'][(string)$capitalPid];
    if (is_string($storedRulerPhoto) && $storedRulerPhoto !== '') $prov['ruler_photo_url'] = $storedRulerPhoto;
    if ($birthYear !== null) $prov['ruler_birth_year'] = $birthYear;
    $state['provinces'][(string)$capitalPid] = $prov;
  }

  if (!api_save_state($state)) api_json_response(['error' => 'state_write_failed'], 500, vk_bot_data_mtime());

  $genealogy = genealogy_load();
  $rulerCharId = 'vk_' . substr(hash('sha1', $rulerName . ':' . $entityId), 0, 12);
  $rulerIdx = genealogy_find_character_index($genealogy['characters'] ?? [], $rulerCharId);
  if ($rulerIdx < 0) {
    $rulerCharId = genealogy_new_character_id($genealogy['characters'] ?? []);
    $genealogy['characters'][] = [
      'id' => $rulerCharId,
      'name' => $rulerName,
      'title' => $stateName,
      'birth_year' => $birthYear,
      'death_year' => null,
      'photo_url' => is_string($storedRulerPhoto) ? $storedRulerPhoto : '',
      'clan' => $clan,
      'clan_branch_type' => 'main',
      'is_clan_founder' => false,
      'notes' => '',
    ];
  } else {
    if ($birthYear !== null) $genealogy['characters'][$rulerIdx]['birth_year'] = $birthYear;
    if (is_string($storedRulerPhoto) && $storedRulerPhoto !== '') $genealogy['characters'][$rulerIdx]['photo_url'] = $storedRulerPhoto;
    if ($clan !== '') $genealogy['characters'][$rulerIdx]['clan'] = $clan;
  }

  foreach ($relatives as $rel) {
    if (!is_array($rel)) continue;
    $name = trim((string)($rel['name'] ?? ''));
    if ($name === '') continue;
    $status = trim((string)($rel['status'] ?? ''));
    $relBirthYear = isset($rel['birth_year']) && $rel['birth_year'] !== '' ? (int)$rel['birth_year'] : null;
    $relPhoto = trim((string)($rel['photo_url'] ?? ''));
    $storedRelPhoto = $relPhoto !== '' ? vk_bot_store_remote_photo($relPhoto, $name) : null;
    if ($relPhoto !== '' && !is_string($storedRelPhoto)) api_json_response(['error' => 'relative_photo_upload_failed', 'name' => $name], 400, vk_bot_data_mtime());

    $charId = genealogy_new_character_id($genealogy['characters'] ?? []);
    $genealogy['characters'][] = [
      'id' => $charId,
      'name' => $name,
      'title' => '',
      'birth_year' => $relBirthYear,
      'death_year' => null,
      'photo_url' => is_string($storedRelPhoto) ? $storedRelPhoto : '',
      'clan' => $clan,
      'clan_branch_type' => 'main',
      'is_clan_founder' => false,
      'notes' => '',
    ];

    $relationship = null;
    if ($status === 'parent') {
      $relationship = ['type' => 'parent_child', 'source_id' => $charId, 'target_id' => $rulerCharId, 'union_id' => null, 'parents_union_id' => null];
    } elseif ($status === 'child') {
      $relationship = ['type' => 'parent_child', 'source_id' => $rulerCharId, 'target_id' => $charId, 'union_id' => null, 'parents_union_id' => null];
    } elseif ($status === 'sibling') {
      $relationship = ['type' => 'siblings', 'source_id' => $rulerCharId, 'target_id' => $charId, 'union_id' => null, 'parents_union_id' => null];
    } elseif ($status === 'spouse') {
      $relationship = ['type' => 'spouses', 'source_id' => $rulerCharId, 'target_id' => $charId, 'union_id' => null, 'parents_union_id' => null];
    }
    if (is_array($relationship) && !genealogy_relationship_exists($genealogy['relationships'] ?? [], $relationship)) {
      $genealogy['relationships'][] = $relationship;
    }
  }

  if (!genealogy_save($genealogy)) api_json_response(['error' => 'genealogy_write_failed'], 500, vk_bot_data_mtime());

  $tok = vk_bot_create_genealogy_admin_token($clan, $entityType, $entityId, (string)($app['genealogy_admin_token'] ?? ''));
  $cfg = vk_bot_load_config();
  $path = is_array($tok) ? (string)$tok['path'] : '';
  $fullLink = ($cfg['public_base_url'] !== '' && $path !== '') ? ($cfg['public_base_url'] . $path) : $path;

  $app['status'] = 'approved';
  $app['approved_at'] = time();
  $app['genealogy_admin_token'] = is_array($tok) ? (string)$tok['token'] : '';
  $app['genealogy_admin_path'] = $path;
  $apps[$idx] = $app;
  vk_bot_save_character_applications($apps);

  $userId = (int)($app['vk_user_id'] ?? 0);
  if ($userId > 0 && $fullLink !== '') {
    vk_bot_send_message($userId, 'Анкета персонажа одобрена! Вход в генеалогию рода: ' . $fullLink . "\nКнопка «Генеалогия рода» в боте теперь активна.");
  }

  api_json_response(['ok' => true, 'item' => $app], 200, vk_bot_data_mtime());
}

if ($action === 'reject') {
  $app['status'] = 'rejected';
  $app['rejected_at'] = time();
  $apps[$idx] = $app;
  vk_bot_save_character_applications($apps);
  api_json_response(['ok' => true, 'item' => $app], 200, vk_bot_data_mtime());
}

api_json_response(['error' => 'unknown_action'], 400, vk_bot_data_mtime());
