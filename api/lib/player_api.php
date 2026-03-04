<?php

declare(strict_types=1);

require_once __DIR__ . '/state_api.php';

function player_tokens_path(): string {
  return api_repo_root() . '/data/player_tokens.json';
}

function player_token_ttl_seconds(): int {
  return 86400;
}

function player_load_tokens(): array {
  $path = player_tokens_path();
  if (!is_file($path)) return [];
  $raw = @file_get_contents($path);
  if ($raw === false || trim($raw) === '') return [];
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function player_save_tokens(array $tokens): bool {
  return api_atomic_write_json(player_tokens_path(), $tokens);
}

function player_prune_tokens(array $tokens, ?int $now = null): array {
  $ts = $now ?? time();
  $out = [];
  foreach ($tokens as $token => $row) {
    if (!is_array($row)) continue;
    $expires = (int)($row['expires_at'] ?? 0);
    if ($expires <= $ts) continue;
    $out[(string)$token] = $row;
  }
  return $out;
}

function player_generate_token(): string {
  return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

function player_allowed_entity_types(): array {
  return ['kingdoms', 'great_houses', 'minor_houses', 'free_cities', 'special_territories'];
}

function player_validate_entity_ref(array $state, string $entityType, string $entityId): bool {
  if (!in_array($entityType, player_allowed_entity_types(), true)) return false;
  return isset($state[$entityType][$entityId]) && is_array($state[$entityType][$entityId]);
}

function player_owned_pids(array $state, string $entityType, string $entityId): array {
  $owned = [];
  foreach (($state['provinces'] ?? []) as $pid => $pd) {
    if (!is_array($pd)) continue;
    $candidate = trim((string)($pd[rtrim($entityType, 's') . '_id'] ?? ''));
    if ($candidate !== $entityId) continue;
    $owned[(int)$pid] = true;
  }
  $list = array_keys($owned);
  sort($list, SORT_NUMERIC);
  return $list;
}

function player_entity_level(string $entityType): int {
  return match ($entityType) {
    'kingdoms' => 4,
    'great_houses' => 3,
    'minor_houses' => 2,
    default => 1,
  };
}

function player_cap_by_level(int $level): int {
  return max(200, $level * 1200);
}

function player_population_stats(array $state, array $pids): array {
  $total = 0;
  $rows = [];
  foreach ($pids as $pid) {
    $pd = $state['provinces'][(string)$pid] ?? $state['provinces'][$pid] ?? null;
    if (!is_array($pd)) continue;
    $pop = (int)($pd['population'] ?? 0);
    $rows[] = ['pid' => (int)$pid, 'name' => (string)($pd['name'] ?? ('PID ' . $pid)), 'population' => $pop];
    $total += $pop;
  }
  return ['total' => $total, 'by_province' => $rows];
}

function player_entity_treasury(array $state, string $entityType, string $entityId, array $pids): float {
  $realm = $state[$entityType][$entityId] ?? [];
  $entityTreasury = (float)($realm['treasury'] ?? 0);
  $provinceTreasury = 0.0;
  foreach ($pids as $pid) {
    $pd = $state['provinces'][(string)$pid] ?? $state['provinces'][$pid] ?? null;
    if (!is_array($pd)) continue;
    $provinceTreasury += (float)($pd['treasury'] ?? 0);
  }
  return round($entityTreasury + $provinceTreasury, 2);
}

function player_resolve_session(array $state, string $token): ?array {
  $tokens = player_prune_tokens(player_load_tokens());
  if (!isset($tokens[$token]) || !is_array($tokens[$token])) return null;
  $row = $tokens[$token];
  $entityType = (string)($row['entity_type'] ?? '');
  $entityId = (string)($row['entity_id'] ?? '');
  if (!player_validate_entity_ref($state, $entityType, $entityId)) return null;

  $pids = player_owned_pids($state, $entityType, $entityId);
  $pop = player_population_stats($state, $pids);
  $realm = $state[$entityType][$entityId];
  $armies = is_array($realm['player_armies'] ?? null) ? array_values($realm['player_armies']) : [];

  return [
    'token_meta' => [
      'entity_type' => $entityType,
      'entity_id' => $entityId,
      'expires_at' => (int)($row['expires_at'] ?? 0),
      'created_at' => (int)($row['created_at'] ?? 0),
    ],
    'entity' => [
      'type' => $entityType,
      'id' => $entityId,
      'name' => (string)($realm['name'] ?? $entityId),
      'level' => player_entity_level($entityType),
      'emblem_svg' => (string)($realm['emblem_svg'] ?? ''),
      'wiki_description' => (string)($realm['wiki_description'] ?? ''),
      'image_url' => (string)($realm['image_url'] ?? ''),
      'treasury_total' => player_entity_treasury($state, $entityType, $entityId, $pids),
      'population_total' => $pop['total'],
      'population_by_province' => $pop['by_province'],
      'muster_cap' => player_cap_by_level(player_entity_level($entityType)),
      'player_armies' => $armies,
    ],
    'owned_pids' => $pids,
  ];
}
