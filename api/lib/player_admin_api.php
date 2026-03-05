<?php

declare(strict_types=1);

require_once __DIR__ . '/state_api.php';

function player_admin_tokens_path(): string {
  return api_repo_root() . '/data/player_admin_tokens.json';
}

function player_admin_token_ttl_seconds(): int {
  return 24 * 60 * 60;
}

function player_admin_load_tokens(): array {
  $path = player_admin_tokens_path();
  if (!is_file($path)) return [];
  $raw = @file_get_contents($path);
  if ($raw === false || trim($raw) === '') return [];
  $json = json_decode($raw, true);
  return is_array($json) ? $json : [];
}

function player_admin_save_tokens(array $tokens): bool {
  return api_atomic_write_json(player_admin_tokens_path(), $tokens);
}

function player_admin_generate_token(): string {
  return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

function player_admin_allowed_entity_types(): array {
  return ['great_houses','minor_houses','free_cities'];
}

function player_admin_minor_houses_from_layer(array $state): array {
  $out = [];
  foreach (['great_houses', 'special_territories'] as $parentType) {
    foreach (($state[$parentType] ?? []) as $parentId => $realm) {
      if (!is_array($realm)) continue;
      $layer = $realm['minor_house_layer'] ?? null;
      if (!is_array($layer)) continue;
      foreach (($layer['vassals'] ?? []) as $idx => $vassal) {
        if (!is_array($vassal)) continue;
        $rawId = trim((string)($vassal['id'] ?? ''));
        if ($rawId === '') continue;
        $name = trim((string)($vassal['name'] ?? $rawId));
        if ($name === '') $name = $rawId;
        $provincePids = [];
        foreach (($vassal['province_pids'] ?? []) as $pid) {
          $n = (int)$pid;
          if ($n > 0) $provincePids[] = $n;
        }

        if (!isset($out[$rawId])) {
          $out[$rawId] = [
            'name' => $name,
            'province_pids' => $provincePids,
            '_layer_parent_type' => $parentType,
            '_layer_parent_id' => (string)$parentId,
            '_layer_index' => (int)$idx,
          ];
          continue;
        }

        if (($out[$rawId]['name'] ?? '') === '' && $name !== '') {
          $out[$rawId]['name'] = $name;
        }
        $existing = is_array($out[$rawId]['province_pids'] ?? null) ? $out[$rawId]['province_pids'] : [];
        $out[$rawId]['province_pids'] = array_values(array_unique(array_merge($existing, $provincePids)));
      }
    }
  }
  return $out;
}


function player_admin_resolve_minor_house_vassal_ref(array $state, string $id): ?array {
  $raw = trim($id);
  if ($raw === '' || strpos($raw, 'vassal:') !== 0) return null;
  $parts = explode(':', $raw);
  if (count($parts) < 4) return null;
  $parentType = ($parts[1] ?? '') === 'special_territories' ? 'special_territories' : 'great_houses';
  $parentId = trim((string)($parts[2] ?? ''));
  $vassalId = trim(implode(':', array_slice($parts, 3)));
  if ($parentId === '' || $vassalId === '') return null;

  $parentRealm = $state[$parentType][$parentId] ?? null;
  if (!is_array($parentRealm)) return null;
  $layer = $parentRealm['minor_house_layer'] ?? null;
  if (!is_array($layer)) return null;
  $vassals = $layer['vassals'] ?? null;
  if (!is_array($vassals)) return null;
  foreach ($vassals as $vassal) {
    if (!is_array($vassal)) continue;
    if (trim((string)($vassal['id'] ?? '')) !== $vassalId) continue;
    return $vassal;
  }
  return null;
}

function player_admin_resolve_entity_ref(array $state, string $type, string $id): ?array {
  if (!in_array($type, player_admin_allowed_entity_types(), true)) return null;
  $bucket = $state[$type] ?? null;
  if (is_array($bucket) && is_array($bucket[$id] ?? null)) return $bucket[$id];

  if ($type === 'minor_houses') {
    $vassalRef = player_admin_resolve_minor_house_vassal_ref($state, $id);
    if (is_array($vassalRef)) return $vassalRef;
    $derived = player_admin_minor_houses_from_layer($state);
    if (is_array($derived[$id] ?? null)) return $derived[$id];
  }
  return null;
}

function player_admin_validate_entity_ref(array $state, string $type, string $id): bool {
  return is_array(player_admin_resolve_entity_ref($state, $type, $id));
}

function player_admin_owned_pids(array $state, string $type, string $id): array {
  $fieldMap = [
    'kingdoms' => 'kingdom_id',
    'great_houses' => 'great_house_id',
    'minor_houses' => 'minor_house_id',
    'free_cities' => 'free_city_id',
    'special_territories' => 'special_territory_id',
  ];
  $field = $fieldMap[$type] ?? '';
  $out = [];

  if ($field !== '') {
    foreach (($state['provinces'] ?? []) as $pid => $prov) {
      if (!is_array($prov)) continue;
      if ((string)($prov[$field] ?? '') !== $id) continue;
      $out[] = (int)$pid;
    }
  }

  if ($type === 'minor_houses') {
    $realm = player_admin_resolve_entity_ref($state, $type, $id);
    if (is_array($realm)) {
      foreach (($realm['province_pids'] ?? []) as $pid) {
        $n = (int)$pid;
        if ($n > 0) $out[] = $n;
      }
    }
  }
  $out = array_values(array_unique($out));
  sort($out);
  return $out;
}

function player_admin_prune_tokens(array $tokens, ?int $now = null): array {
  $ts = $now ?? time();
  $out = [];
  foreach ($tokens as $token => $row) {
    if (!is_array($row)) continue;
    $expires = (int)($row['expires_at'] ?? 0);
    if ($expires <= $ts) continue;
    $out[$token] = $row;
  }
  return $out;
}

function player_admin_resolve_session(array $state, string $token): ?array {
  $tokens = player_admin_prune_tokens(player_admin_load_tokens());
  $row = $tokens[$token] ?? null;
  if (!is_array($row)) return null;
  $type = (string)($row['entity_type'] ?? '');
  $id = (string)($row['entity_id'] ?? '');
  if (!player_admin_validate_entity_ref($state, $type, $id)) return null;
  $realm = player_admin_resolve_entity_ref($state, $type, $id) ?? [];
  $pids = player_admin_owned_pids($state, $type, $id);
  return [
    'entity_type' => $type,
    'entity_id' => $id,
    'entity_name' => (string)($realm['name'] ?? $id),
    'owned_pids' => $pids,
    'expires_at' => (int)($row['expires_at'] ?? 0),
  ];
}

function player_admin_token_from_request(): string {
  $h = (string)($_SERVER['HTTP_X_PLAYER_ADMIN_TOKEN'] ?? '');
  if ($h !== '') return trim($h);
  $q = (string)($_GET['token'] ?? '');
  return trim($q);
}
