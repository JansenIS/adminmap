<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/lib/vk_bot_api.php';
require_once dirname(__DIR__, 3) . '/lib/genealogy_api.php';

/**
 * @param array<string,mixed> $base
 * @param array<string,mixed> $patch
 * @return array<string,mixed>
 */
function vk_character_apps_merge_patch(array $base, array $patch): array
{
  foreach ($patch as $key => $value) {
    if (
      is_string($key)
      && isset($base[$key])
      && is_array($base[$key])
      && is_array($value)
      && array_is_list($base[$key]) === false
      && array_is_list($value) === false
    ) {
      /** @var array<string,mixed> $nestedBase */
      $nestedBase = $base[$key];
      /** @var array<string,mixed> $nestedPatch */
      $nestedPatch = $value;
      $base[$key] = vk_character_apps_merge_patch($nestedBase, $nestedPatch);
      continue;
    }
    $base[$key] = $value;
  }
  return $base;
}

function vk_character_apps_union_id_for_pair(array $relationships, string $leftId, string $rightId): string
{
  foreach ($relationships as $row) {
    if (!is_array($row) || (string)($row['type'] ?? '') !== 'spouses') continue;
    $sourceId = (string)($row['source_id'] ?? '');
    $targetId = (string)($row['target_id'] ?? '');
    if (!(($sourceId === $leftId && $targetId === $rightId) || ($sourceId === $rightId && $targetId === $leftId))) continue;
    $unionId = trim((string)($row['union_id'] ?? ''));
    if ($unionId !== '') return $unionId;
  }

  $pair = [$leftId, $rightId];
  sort($pair, SORT_STRING);
  return 'u_' . substr(hash('sha1', implode(':', $pair)), 0, 10);
}

function vk_character_apps_upsert_parent_child_relationship(array &$relationships, string $parentId, string $childId, ?string $parentsUnionId = null): void
{
  $normalizedUnionId = is_string($parentsUnionId) && $parentsUnionId !== '' ? $parentsUnionId : null;
  foreach ($relationships as $idx => $row) {
    if (!is_array($row) || (string)($row['type'] ?? '') !== 'parent_child') continue;
    if ((string)($row['source_id'] ?? '') !== $parentId || (string)($row['target_id'] ?? '') !== $childId) continue;
    if ($normalizedUnionId !== null && trim((string)($row['parents_union_id'] ?? '')) === '') {
      $relationships[$idx]['parents_union_id'] = $normalizedUnionId;
    }
    return;
  }

  $relationships[] = [
    'type' => 'parent_child',
    'source_id' => $parentId,
    'target_id' => $childId,
    'union_id' => null,
    'parents_union_id' => $normalizedUnionId,
  ];
}

function vk_character_apps_resolve_clan_from_state_app(array $characterApp, string $entityType, string $entityId): string
{
  $stateApplicationId = trim((string)($characterApp['state_application_id'] ?? ''));
  $apps = vk_bot_load_applications();

  if ($stateApplicationId !== '') {
    foreach ($apps as $row) {
      if (!is_array($row)) continue;
      if (trim((string)($row['id'] ?? '')) !== $stateApplicationId) continue;
      $form = is_array($row['form'] ?? null) ? $row['form'] : [];
      $house = trim((string)($form['ruler_house'] ?? ''));
      if ($house !== '') return $house;
      break;
    }
  }

  foreach ($apps as $row) {
    if (!is_array($row)) continue;
    if (trim((string)($row['approved_entity_type'] ?? '')) !== $entityType) continue;
    if (trim((string)($row['approved_entity_id'] ?? '')) !== $entityId) continue;
    $form = is_array($row['form'] ?? null) ? $row['form'] : [];
    $house = trim((string)($form['ruler_house'] ?? ''));
    if ($house !== '') return $house;
  }

  return '';
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST' && $method !== 'PATCH') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST', 'PATCH']], 405, vk_bot_data_mtime());
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
$warnings = [];

if ($action === 'update') {
  $patch = $payload['patch'] ?? null;
  if (!is_array($patch)) api_json_response(['error' => 'invalid_patch'], 400, vk_bot_data_mtime());
  $app = vk_character_apps_merge_patch($app, $patch);
  $apps[$idx] = $app;
  vk_bot_save_character_applications($apps);
  api_json_response(['ok' => true, 'item' => $app, 'warnings' => $warnings], 200, vk_bot_data_mtime());
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
  if ($photo !== '' && !is_string($storedRulerPhoto)) $warnings[] = ['code' => 'ruler_photo_upload_failed'];
  $clan = vk_character_apps_resolve_clan_from_state_app($app, $entityType, $entityId);
  $profile = is_array($state['people_profiles'][$rulerName] ?? null) ? $state['people_profiles'][$rulerName] : null;
  if ($clan === '' && is_array($profile) && preg_match('/^Род:\s*(.+)$/um', (string)($profile['bio'] ?? ''), $m)) {
    $clan = trim((string)($m[1] ?? ''));
  }
  $resolvedClan = $clan !== '' ? $clan : $rulerName;

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
      'photo_url' => is_string($storedRulerPhoto) ? $storedRulerPhoto : $photo,
      'clan' => $resolvedClan,
      'clan_branch_type' => 'main',
      'is_clan_founder' => false,
      'notes' => '',
    ];
  } else {
    if ($birthYear !== null) $genealogy['characters'][$rulerIdx]['birth_year'] = $birthYear;
    if (is_string($storedRulerPhoto) && $storedRulerPhoto !== '') $genealogy['characters'][$rulerIdx]['photo_url'] = $storedRulerPhoto;
    elseif ($photo !== '') $genealogy['characters'][$rulerIdx]['photo_url'] = $photo;
    if ($resolvedClan !== '') $genealogy['characters'][$rulerIdx]['clan'] = $resolvedClan;
  }

  $parentIds = [];
  $siblingIds = [];

  foreach ($relatives as $rel) {
    if (!is_array($rel)) continue;
    $name = trim((string)($rel['name'] ?? ''));
    if ($name === '') continue;
    $status = trim((string)($rel['status'] ?? ''));
    $relBirthYear = isset($rel['birth_year']) && $rel['birth_year'] !== '' ? (int)$rel['birth_year'] : null;
    $relPhoto = trim((string)($rel['photo_url'] ?? ''));
    $storedRelPhoto = $relPhoto !== '' ? vk_bot_store_remote_photo($relPhoto, $name) : null;
    if ($relPhoto !== '' && !is_string($storedRelPhoto)) $warnings[] = ['code' => 'relative_photo_upload_failed', 'name' => $name];

    $charId = genealogy_new_character_id($genealogy['characters'] ?? []);
    $genealogy['characters'][] = [
      'id' => $charId,
      'name' => $name,
      'title' => '',
      'birth_year' => $relBirthYear,
      'death_year' => null,
      'photo_url' => is_string($storedRelPhoto) ? $storedRelPhoto : $relPhoto,
      'clan' => $resolvedClan,
      'clan_branch_type' => 'main',
      'is_clan_founder' => false,
      'notes' => '',
    ];

    $relationship = null;
    if ($status === 'parent') {
      $relationship = ['type' => 'parent_child', 'source_id' => $charId, 'target_id' => $rulerCharId, 'union_id' => null, 'parents_union_id' => null];
      $parentIds[] = $charId;
    } elseif ($status === 'child') {
      $relationship = ['type' => 'parent_child', 'source_id' => $rulerCharId, 'target_id' => $charId, 'union_id' => null, 'parents_union_id' => null];
    } elseif ($status === 'sibling') {
      $relationship = ['type' => 'siblings', 'source_id' => $rulerCharId, 'target_id' => $charId, 'union_id' => null, 'parents_union_id' => null];
      $siblingIds[] = $charId;
    } elseif ($status === 'spouse') {
      $relationship = ['type' => 'spouses', 'source_id' => $rulerCharId, 'target_id' => $charId, 'union_id' => null, 'parents_union_id' => null];
    }
    if (is_array($relationship) && !genealogy_relationship_exists($genealogy['relationships'] ?? [], $relationship)) {
      $genealogy['relationships'][] = $relationship;
    }
  }

  $parentIds = array_values(array_unique($parentIds));
  $parentsUnionId = null;
  if (count($parentIds) >= 2) {
    $leftParentId = (string)$parentIds[0];
    $rightParentId = (string)$parentIds[1];
    $parentsUnionId = vk_character_apps_union_id_for_pair($genealogy['relationships'] ?? [], $leftParentId, $rightParentId);
    $parentsSpouseRelationship = [
      'type' => 'spouses',
      'source_id' => $leftParentId,
      'target_id' => $rightParentId,
      'union_id' => $parentsUnionId,
      'parents_union_id' => null,
    ];
    if (!genealogy_relationship_exists($genealogy['relationships'] ?? [], $parentsSpouseRelationship)) {
      $genealogy['relationships'][] = $parentsSpouseRelationship;
    }

    vk_character_apps_upsert_parent_child_relationship($genealogy['relationships'], $leftParentId, $rulerCharId, $parentsUnionId);
    vk_character_apps_upsert_parent_child_relationship($genealogy['relationships'], $rightParentId, $rulerCharId, $parentsUnionId);
  }

  foreach ($siblingIds as $siblingId) {
    foreach ($parentIds as $parentId) {
      vk_character_apps_upsert_parent_child_relationship($genealogy['relationships'], (string)$parentId, (string)$siblingId, $parentsUnionId);
    }
  }

  if (!genealogy_save($genealogy)) api_json_response(['error' => 'genealogy_write_failed'], 500, vk_bot_data_mtime());

  $tok = vk_bot_create_genealogy_admin_token($resolvedClan, $entityType, $entityId, (string)($app['genealogy_admin_token'] ?? ''));
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

  api_json_response(['ok' => true, 'item' => $app, 'warnings' => $warnings], 200, vk_bot_data_mtime());
}

if ($action === 'reject') {
  $app['status'] = 'rejected';
  $app['rejected_at'] = time();
  $apps[$idx] = $app;
  vk_bot_save_character_applications($apps);
  api_json_response(['ok' => true, 'item' => $app, 'warnings' => $warnings], 200, vk_bot_data_mtime());
}

api_json_response(['error' => 'unknown_action'], 400, vk_bot_data_mtime());
