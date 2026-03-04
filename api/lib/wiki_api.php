<?php

declare(strict_types=1);

require_once __DIR__ . '/state_api.php';
require_once __DIR__ . '/genealogy_api.php';

function api_wiki_allowed_entity_types(): array {
  return ['kingdoms', 'great_houses', 'minor_houses', 'vassals', 'free_cities'];
}

function api_wiki_canonical_entity_type(string $entityType): string {
  $type = trim($entityType);
  if ($type === 'vassals') return 'minor_houses';
  return $type;
}

function api_wiki_entity_type_for_link(string $entityType): string {
  $canonical = api_wiki_canonical_entity_type($entityType);
  if ($canonical === 'minor_houses') return 'vassals';
  return $canonical;
}

function api_wiki_page_link(string $kind, array $params): string {
  $query = ['kind' => $kind] + $params;
  return '/api/wiki/show/index.php?' . http_build_query($query);
}

function api_wiki_load_emblem_assets_map(): array {
  static $cache = null;
  if (is_array($cache)) return $cache;

  $cache = [];
  $path = api_repo_root() . '/data/emblem_assets.json';
  if (!is_file($path)) return $cache;

  $raw = (string)@file_get_contents($path);
  $decoded = json_decode($raw, true);
  $items = is_array($decoded['assets'] ?? null) ? $decoded['assets'] : [];
  foreach ($items as $row) {
    if (!is_array($row)) continue;
    $id = trim((string)($row['id'] ?? ''));
    if ($id === '') continue;
    $cache[$id] = trim((string)($row['svg'] ?? ''));
  }

  return $cache;
}

function api_wiki_resolve_emblem_svg(string $assetId, string $inlineSvg): string {
  $svg = trim($inlineSvg);
  if ($svg !== '') return $svg;

  $id = trim($assetId);
  if ($id === '') return '';
  $assets = api_wiki_load_emblem_assets_map();
  return trim((string)($assets[$id] ?? ''));
}


function api_wiki_load_genealogy_snapshot(): array {
  $path = genealogy_data_path();
  if (!is_file($path)) {
    return ['characters' => [], 'relationships' => [], 'clans' => []];
  }
  $raw = (string)@file_get_contents($path);
  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) return ['characters' => [], 'relationships' => [], 'clans' => []];
  if (!is_array($decoded['characters'] ?? null)) $decoded['characters'] = [];
  if (!is_array($decoded['relationships'] ?? null)) $decoded['relationships'] = [];
  if (!is_array($decoded['clans'] ?? null)) $decoded['clans'] = [];
  return $decoded;
}

function api_wiki_extract_clans(array $genealogy): array {
  $result = [];
  foreach (($genealogy['characters'] ?? []) as $char) {
    if (!is_array($char)) continue;
    $clanName = trim((string)($char['clan'] ?? ''));
    if ($clanName === '') continue;
    if (!isset($result[$clanName])) {
      $result[$clanName] = [
        'id' => $clanName,
        'name' => $clanName,
        'description' => '',
        'emblem_asset_id' => '',
        'emblem_svg' => '',
        'members' => [],
      ];
    }
    $result[$clanName]['members'][] = [
      'id' => (string)($char['id'] ?? ''),
      'name' => trim((string)($char['name'] ?? '')),
      'title' => trim((string)($char['title'] ?? '')),
      'is_founder' => (bool)($char['is_clan_founder'] ?? false),
      'wiki_link' => api_wiki_page_link('character', ['id' => (string)($char['id'] ?? '')]),
    ];
  }

  $meta = $genealogy['clans'] ?? [];
  if (is_array($meta)) {
    foreach ($meta as $clanId => $clanMeta) {
      if (!is_array($clanMeta)) continue;
      $name = trim((string)($clanMeta['name'] ?? $clanId));
      if ($name === '') continue;
      if (!isset($result[$name])) {
        $result[$name] = [
          'id' => $name,
          'name' => $name,
          'description' => '',
          'emblem_asset_id' => '',
          'emblem_svg' => '',
          'members' => [],
        ];
      }
      if (isset($clanMeta['description'])) $result[$name]['description'] = trim((string)$clanMeta['description']);
      if (isset($clanMeta['emblem_asset_id'])) $result[$name]['emblem_asset_id'] = trim((string)$clanMeta['emblem_asset_id']);
      if (isset($clanMeta['emblem_svg'])) $result[$name]['emblem_svg'] = trim((string)$clanMeta['emblem_svg']);
    }
  }

  ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
  return $result;
}

function api_wiki_character_relatives(array $genealogy, string $charId): array {
  $rels = [];
  foreach (($genealogy['relationships'] ?? []) as $rel) {
    if (!is_array($rel)) continue;
    $type = (string)($rel['type'] ?? '');
    $source = (string)($rel['source_id'] ?? '');
    $target = (string)($rel['target_id'] ?? '');

    if ($type === 'parent_child') {
      if ($source === $charId) {
        $rels[] = ['id' => $target, 'relation' => 'child'];
      } elseif ($target === $charId) {
        $rels[] = ['id' => $source, 'relation' => 'parent'];
      }
      continue;
    }

    if ($type === 'spouses' || $type === 'siblings') {
      if ($source === $charId) $rels[] = ['id' => $target, 'relation' => $type === 'spouses' ? 'spouse' : 'sibling'];
      if ($target === $charId) $rels[] = ['id' => $source, 'relation' => $type === 'spouses' ? 'spouse' : 'sibling'];
    }
  }

  $chars = [];
  foreach (($genealogy['characters'] ?? []) as $char) {
    if (!is_array($char)) continue;
    $id = (string)($char['id'] ?? '');
    if ($id === '') continue;
    $chars[$id] = $char;
  }

  $out = [];
  $seen = [];
  foreach ($rels as $row) {
    $id = (string)($row['id'] ?? '');
    if ($id === '' || isset($seen[$id . '|' . $row['relation']])) continue;
    if (!isset($chars[$id])) continue;
    $seen[$id . '|' . $row['relation']] = true;
    $out[] = [
      'id' => $id,
      'name' => trim((string)($chars[$id]['name'] ?? '')),
      'relation' => (string)$row['relation'],
      'wiki_link' => api_wiki_page_link('character', ['id' => $id]),
    ];
  }
  return $out;
}

function api_wiki_build_province_page(array $state, int $pid): ?array {
  $province = ($state['provinces'] ?? [])[(string)$pid] ?? null;
  if (!is_array($province)) return null;

  $refs = api_build_refs_by_owner_from_file_or_state($state);
  $ownerKey = 'province:' . $pid;
  $resolvedEmblemAssetId = trim((string)($province['emblem_asset_id'] ?? ''));
  if ($resolvedEmblemAssetId === '') {
    $resolvedEmblemAssetId = trim((string)($refs[$ownerKey] ?? ''));
  }
  $resolvedEmblemSvg = api_wiki_resolve_emblem_svg($resolvedEmblemAssetId, trim((string)($province['emblem_svg'] ?? '')));

  $kingdomId = trim((string)($province['kingdom_id'] ?? ''));
  $greatHouseId = trim((string)($province['great_house_id'] ?? ''));

  $kingdom = ($state['kingdoms'] ?? [])[$kingdomId] ?? null;
  $kingdomOwnerKey = 'kingdom:' . $kingdomId;
  $kingdomAssetId = is_array($kingdom) ? trim((string)($kingdom['emblem_asset_id'] ?? '')) : '';
  if ($kingdomAssetId === '') $kingdomAssetId = trim((string)($refs[$kingdomOwnerKey] ?? ''));

  $greatHouse = ($state['great_houses'] ?? [])[$greatHouseId] ?? null;
  $greatHouseOwnerKey = 'great_house:' . $greatHouseId;
  $greatHouseAssetId = is_array($greatHouse) ? trim((string)($greatHouse['emblem_asset_id'] ?? '')) : '';
  if ($greatHouseAssetId === '') $greatHouseAssetId = trim((string)($refs[$greatHouseOwnerKey] ?? ''));

  return [
    'view' => 'project_card',
    'page_key' => 'province:' . $pid,
    'kind' => 'province',
    'title' => trim((string)($province['name'] ?? '')),
    'description' => trim((string)($province['wiki_description'] ?? '')),
    'province' => [
      'pid' => $pid,
      'owner' => trim((string)($province['owner'] ?? '')),
      'suzerain' => trim((string)($province['suzerain'] ?? '')),
      'senior' => trim((string)($province['senior'] ?? '')),
      'vassals' => array_values(array_filter(array_map(static fn($v) => trim((string)$v), (array)($province['vassals'] ?? [])), static fn($v) => $v !== '')),
      'terrain' => trim((string)($province['terrain'] ?? '')),
      'background_image' => trim((string)($province['province_card_image'] ?? '')),
      'fill_rgba' => (is_array($province['fill_rgba'] ?? null) && count($province['fill_rgba']) === 4) ? array_values($province['fill_rgba']) : null,
      'emblem_asset_id' => $resolvedEmblemAssetId,
      'emblem_svg' => $resolvedEmblemSvg,
      'emblem_box' => (is_array($province['emblem_box'] ?? null) && count($province['emblem_box']) === 2) ? array_values($province['emblem_box']) : null,
      'kingdom_id' => $kingdomId,
      'great_house_id' => $greatHouseId,
      'minor_house_id' => trim((string)($province['minor_house_id'] ?? '')),
      'free_city_id' => trim((string)($province['free_city_id'] ?? '')),
      'kingdom' => is_array($kingdom) ? [
        'id' => $kingdomId,
        'name' => trim((string)($kingdom['name'] ?? $kingdomId)),
        'wiki_link' => api_wiki_page_link('entity', ['entity_type' => 'kingdoms', 'id' => $kingdomId]),
        'emblem_asset_id' => $kingdomAssetId,
        'emblem_svg' => api_wiki_resolve_emblem_svg($kingdomAssetId, trim((string)($kingdom['emblem_svg'] ?? ''))),
      ] : null,
      'great_house' => is_array($greatHouse) ? [
        'id' => $greatHouseId,
        'name' => trim((string)($greatHouse['name'] ?? $greatHouseId)),
        'wiki_link' => api_wiki_page_link('entity', ['entity_type' => 'great_houses', 'id' => $greatHouseId]),
        'emblem_asset_id' => $greatHouseAssetId,
        'emblem_svg' => api_wiki_resolve_emblem_svg($greatHouseAssetId, trim((string)($greatHouse['emblem_svg'] ?? ''))),
      ] : null,
    ],
  ];
}

function api_wiki_entity_children(array $state, string $entityType, string $id): array {
  $entityType = api_wiki_canonical_entity_type($entityType);
  $children = ['entities' => [], 'provinces' => []];

  $childEntityTypes = [
    'kingdoms' => ['great_houses', 'minor_houses', 'free_cities'],
    'great_houses' => ['minor_houses'],
    'minor_houses' => [],
    'free_cities' => [],
  ];

  $provinceFieldByType = [
    'kingdoms' => 'kingdom_id',
    'great_houses' => 'great_house_id',
    'minor_houses' => 'minor_house_id',
    'free_cities' => 'free_city_id',
  ];

  foreach (($childEntityTypes[$entityType] ?? []) as $childType) {
    foreach (($state[$childType] ?? []) as $childId => $child) {
      if (!is_array($child)) continue;
      $provinces = [];
      foreach (($state['provinces'] ?? []) as $pid => $province) {
        if (!is_array($province)) continue;
        $match = trim((string)($province[$provinceFieldByType[$childType]] ?? '')) === (string)$childId;
        if ($match) {
          $provinces[] = (int)$pid;
        }
      }

      $parentMatch = false;
      if ($entityType === 'kingdoms') {
        foreach ($provinces as $pid) {
          $province = ($state['provinces'] ?? [])[(string)$pid] ?? [];
          if (trim((string)($province['kingdom_id'] ?? '')) === $id) {
            $parentMatch = true;
            break;
          }
        }
      } elseif ($entityType === 'great_houses' && $childType === 'minor_houses') {
        foreach ($provinces as $pid) {
          $province = ($state['provinces'] ?? [])[(string)$pid] ?? [];
          if (trim((string)($province['great_house_id'] ?? '')) === $id) {
            $parentMatch = true;
            break;
          }
        }
      }

      if (!$parentMatch) continue;

      $children['entities'][] = [
        'entity_type' => $childType,
        'id' => (string)$childId,
        'name' => trim((string)($child['name'] ?? '')),
        'wiki_link' => api_wiki_page_link('entity', ['entity_type' => api_wiki_entity_type_for_link($childType), 'id' => (string)$childId]),
      ];
    }
  }

  foreach (($state['provinces'] ?? []) as $pid => $province) {
    if (!is_array($province)) continue;
    $field = $provinceFieldByType[$entityType] ?? null;
    if ($field === null) continue;
    if (trim((string)($province[$field] ?? '')) !== $id) continue;
    $children['provinces'][] = [
      'pid' => (int)$pid,
      'name' => trim((string)($province['name'] ?? '')),
      'wiki_link' => api_wiki_page_link('province', ['pid' => (int)$pid]),
    ];
  }

  return $children;
}

function api_wiki_build_entity_page(array $state, string $entityType, string $id): ?array {
  if (!in_array($entityType, api_wiki_allowed_entity_types(), true)) return null;
  $entityType = api_wiki_canonical_entity_type($entityType);
  $realm = ($state[$entityType] ?? [])[$id] ?? null;
  if (!is_array($realm)) return null;

  $ownerTypeByRealmType = [
    'kingdoms' => 'kingdom',
    'great_houses' => 'great_house',
    'minor_houses' => 'minor_house',
    'free_cities' => 'free_city',
  ];

  $refs = api_build_refs_by_owner_from_file_or_state($state);
  $ownerKey = ($ownerTypeByRealmType[$entityType] ?? $entityType) . ':' . $id;
  $resolvedEmblemAssetId = trim((string)($realm['emblem_asset_id'] ?? ''));
  if ($resolvedEmblemAssetId === '') {
    $resolvedEmblemAssetId = trim((string)($refs[$ownerKey] ?? ''));
  }
  $resolvedEmblemSvg = api_wiki_resolve_emblem_svg($resolvedEmblemAssetId, trim((string)($realm['emblem_svg'] ?? '')));

  $children = api_wiki_entity_children($state, $entityType, $id);

  $parentEntity = null;
  if ($entityType === 'great_houses') {
    foreach (($state['provinces'] ?? []) as $province) {
      if (!is_array($province)) continue;
      if (trim((string)($province['great_house_id'] ?? '')) !== $id) continue;
      $kingdomId = trim((string)($province['kingdom_id'] ?? ''));
      if ($kingdomId === '') continue;
      $kingdom = ($state['kingdoms'] ?? [])[$kingdomId] ?? null;
      if (!is_array($kingdom)) continue;
      $parentEntity = [
        'entity_type' => 'kingdoms',
        'id' => $kingdomId,
        'name' => trim((string)($kingdom['name'] ?? $kingdomId)),
        'wiki_link' => api_wiki_page_link('entity', ['entity_type' => api_wiki_entity_type_for_link('kingdoms'), 'id' => $kingdomId]),
      ];
      break;
    }
  }

  return [
    'view' => 'project_card',
    'page_key' => 'entity:' . $entityType . ':' . $id,
    'kind' => 'entity',
    'title' => trim((string)($realm['name'] ?? '')),
    'description' => trim((string)($realm['wiki_description'] ?? '')),
    'entity' => [
      'entity_type' => $entityType,
      'id' => $id,
      'ruler' => trim((string)($realm['ruler'] ?? '')),
      'color' => trim((string)($realm['color'] ?? '')),
      'capital_pid' => (int)($realm['capital_pid'] ?? $realm['capital_key'] ?? 0),
      'province_pids' => array_values(array_map(static fn($v) => (int)$v, (array)($realm['province_pids'] ?? $realm['province_keys'] ?? []))),
      'emblem_asset_id' => $resolvedEmblemAssetId,
      'emblem_svg' => $resolvedEmblemSvg,
      'emblem_box' => (is_array($realm['emblem_box'] ?? null) && count($realm['emblem_box']) === 2) ? array_values($realm['emblem_box']) : null,
      'emblem_scale' => (float)($realm['emblem_scale'] ?? 1.0),
      'parent_entity' => $parentEntity,
      'children' => $children,
    ],
  ];
}

function api_wiki_build_clan_page(array $genealogy, string $id): ?array {
  $clans = api_wiki_extract_clans($genealogy);
  $clan = $clans[$id] ?? null;
  if (!is_array($clan)) return null;

  return [
    'view' => 'project_card',
    'page_key' => 'clan:' . $id,
    'kind' => 'clan',
    'title' => (string)$clan['name'],
    'description' => (string)$clan['description'],
    'clan' => [
      'id' => (string)$clan['id'],
      'name' => (string)$clan['name'],
      'emblem_asset_id' => (string)$clan['emblem_asset_id'],
      'emblem_svg' => (string)$clan['emblem_svg'],
      'members' => array_values($clan['members'] ?? []),
    ],
  ];
}

function api_wiki_build_character_page(array $genealogy, string $id): ?array {
  $character = null;
  foreach (($genealogy['characters'] ?? []) as $char) {
    if (!is_array($char)) continue;
    if ((string)($char['id'] ?? '') !== $id) continue;
    $character = $char;
    break;
  }
  if (!is_array($character)) return null;

  return [
    'view' => 'project_card',
    'page_key' => 'character:' . $id,
    'kind' => 'character',
    'title' => trim((string)($character['name'] ?? '')),
    'description' => trim((string)($character['notes'] ?? '')),
    'character' => [
      'id' => (string)$id,
      'name' => trim((string)($character['name'] ?? '')),
      'title' => trim((string)($character['title'] ?? '')),
      'birth_year' => $character['birth_year'] ?? null,
      'death_year' => $character['death_year'] ?? null,
      'photo_url' => trim((string)($character['photo_url'] ?? '')),
      'clan' => trim((string)($character['clan'] ?? '')),
      'clan_wiki_link' => trim((string)($character['clan'] ?? '')) !== '' ? api_wiki_page_link('clan', ['id' => trim((string)$character['clan'])]) : '',
      'biography' => trim((string)($character['notes'] ?? '')),
      'closest_relatives' => api_wiki_character_relatives($genealogy, $id),
    ],
  ];
}

function api_wiki_list_pages(array $state): array {
  $pages = [];
  foreach (($state['provinces'] ?? []) as $pid => $province) {
    $pidNum = (int)$pid;
    if ($pidNum <= 0 || !is_array($province)) continue;
    $pages[] = [
      'page_key' => 'province:' . $pidNum,
      'kind' => 'province',
      'title' => trim((string)($province['name'] ?? '')),
      'pid' => $pidNum,
      'wiki_link' => api_wiki_page_link('province', ['pid' => $pidNum]),
    ];
  }

  foreach (api_wiki_allowed_entity_types() as $entityType) {
    $canonicalType = api_wiki_canonical_entity_type($entityType);
    if ($canonicalType !== $entityType) continue;
    foreach (($state[$entityType] ?? []) as $id => $realm) {
      if (!is_array($realm)) continue;
      $pages[] = [
        'page_key' => 'entity:' . $entityType . ':' . $id,
        'kind' => 'entity',
        'entity_type' => api_wiki_entity_type_for_link($entityType),
        'id' => (string)$id,
        'title' => trim((string)($realm['name'] ?? '')),
        'wiki_link' => api_wiki_page_link('entity', ['entity_type' => api_wiki_entity_type_for_link($entityType), 'id' => (string)$id]),
      ];
    }
  }

  $genealogy = api_wiki_load_genealogy_snapshot();
  $clans = api_wiki_extract_clans($genealogy);
  foreach ($clans as $clanName => $clan) {
    $pages[] = [
      'page_key' => 'clan:' . $clanName,
      'kind' => 'clan',
      'id' => $clanName,
      'title' => (string)($clan['name'] ?? $clanName),
      'wiki_link' => api_wiki_page_link('clan', ['id' => $clanName]),
    ];
  }

  foreach (($genealogy['characters'] ?? []) as $char) {
    if (!is_array($char)) continue;
    $id = trim((string)($char['id'] ?? ''));
    if ($id === '') continue;
    $pages[] = [
      'page_key' => 'character:' . $id,
      'kind' => 'character',
      'id' => $id,
      'title' => trim((string)($char['name'] ?? '')),
      'wiki_link' => api_wiki_page_link('character', ['id' => $id]),
    ];
  }

  usort($pages, static function (array $a, array $b): int {
    $ak = (string)($a['page_key'] ?? '');
    $bk = (string)($b['page_key'] ?? '');
    return strcasecmp($ak, $bk);
  });
  return $pages;
}

function api_wiki_validate_patch_payload(array $payload): array {
  $allowedTop = ['kind', 'pid', 'entity_type', 'id', 'changes', 'if_match'];
  foreach ($payload as $k => $_v) {
    if (!in_array((string)$k, $allowedTop, true)) return ['ok' => false, 'error' => 'invalid_payload_field', 'field' => (string)$k];
  }

  $kind = trim((string)($payload['kind'] ?? ''));
  $changes = $payload['changes'] ?? null;
  if (!is_array($changes) || $kind === '') return ['ok' => false, 'error' => 'invalid_payload'];

  if ($kind === 'province') {
    $pid = (int)($payload['pid'] ?? 0);
    if ($pid <= 0) return ['ok' => false, 'error' => 'invalid_pid'];

    $mapped = [];
    $fieldMap = ['title' => 'name', 'description' => 'wiki_description', 'background_image' => 'province_card_image'];
    foreach ($changes as $field => $value) {
      $target = $fieldMap[(string)$field] ?? (string)$field;
      $mapped[$target] = $value;
    }

    $schema = api_validate_province_changes_schema($mapped, 'changes');
    if (!$schema['ok']) return $schema;

    return ['ok' => true, 'kind' => 'province', 'pid' => $pid, 'changes' => $mapped];
  }

  if ($kind === 'entity') {
    $entityType = trim((string)($payload['entity_type'] ?? ''));
    $id = trim((string)($payload['id'] ?? ''));
    if (!in_array($entityType, api_wiki_allowed_entity_types(), true)) return ['ok' => false, 'error' => 'invalid_entity_type'];
    $entityType = api_wiki_canonical_entity_type($entityType);
    if ($id === '') return ['ok' => false, 'error' => 'invalid_id'];

    $mapped = [];
    $fieldMap = ['title' => 'name', 'description' => 'wiki_description'];
    foreach ($changes as $field => $value) {
      $target = $fieldMap[(string)$field] ?? (string)$field;
      $mapped[$target] = $value;
    }

    $schema = api_validate_realm_changes_schema($mapped, 'changes');
    if (!$schema['ok']) return $schema;

    return ['ok' => true, 'kind' => 'entity', 'entity_type' => $entityType, 'id' => $id, 'changes' => $mapped];
  }

  if ($kind === 'character') {
    $id = trim((string)($payload['id'] ?? ''));
    if ($id === '') return ['ok' => false, 'error' => 'invalid_id'];
    return ['ok' => true, 'kind' => 'character', 'id' => $id, 'changes' => $changes];
  }

  if ($kind === 'clan') {
    $id = trim((string)($payload['id'] ?? ''));
    if ($id === '') return ['ok' => false, 'error' => 'invalid_id'];
    return ['ok' => true, 'kind' => 'clan', 'id' => $id, 'changes' => $changes];
  }

  return ['ok' => false, 'error' => 'invalid_kind'];
}
