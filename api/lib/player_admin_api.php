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
  return ['kingdoms','great_houses','minor_houses','free_cities','special_territories'];
}

function player_admin_validate_entity_ref(array $state, string $type, string $id): bool {
  if (!in_array($type, player_admin_allowed_entity_types(), true)) return false;
  return is_array($state[$type] ?? null) && is_array(($state[$type][$id] ?? null));
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
  if ($field === '') return [];
  $out = [];
  foreach (($state['provinces'] ?? []) as $pid => $prov) {
    if (!is_array($prov)) continue;
    if ((string)($prov[$field] ?? '') !== $id) continue;
    $out[] = (int)$pid;
  }
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
  $realm = $state[$type][$id] ?? [];
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
