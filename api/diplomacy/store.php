<?php

declare(strict_types=1);

function diplomacy_data_dir(): string { return api_repo_root() . '/data/diplomacy'; }
function diplomacy_threads_path(): string { return diplomacy_data_dir() . '/threads.json'; }
function diplomacy_messages_path(): string { return diplomacy_data_dir() . '/messages.json'; }
function diplomacy_proposals_path(): string { return diplomacy_data_dir() . '/proposals.json'; }
function diplomacy_treaties_path(): string { return diplomacy_data_dir() . '/treaties.json'; }
function diplomacy_relations_path(): string { return diplomacy_data_dir() . '/relations.json'; }
function diplomacy_unread_path(): string { return diplomacy_data_dir() . '/unread.json'; }
function diplomacy_idempotency_path(): string { return diplomacy_data_dir() . '/idempotency.json'; }
function diplomacy_settings_path(): string { return diplomacy_data_dir() . '/settings.json'; }
function diplomacy_logs_path(): string { return diplomacy_data_dir() . '/logs.json'; }

function diplomacy_store_mtime(): int {
  $max = 0;
  foreach ([diplomacy_threads_path(), diplomacy_messages_path(), diplomacy_proposals_path(), diplomacy_treaties_path(), diplomacy_relations_path(), diplomacy_unread_path(), diplomacy_idempotency_path(), diplomacy_settings_path(), diplomacy_logs_path()] as $path) {
    $max = max($max, (int)@filemtime($path));
  }
  return $max > 0 ? $max : time();
}

function diplomacy_ensure_store(): void {
  if (!is_dir(diplomacy_data_dir())) @mkdir(diplomacy_data_dir(), 0775, true);
  $seed = [
    diplomacy_threads_path() => ['schema_version' => 1, 'threads' => []],
    diplomacy_messages_path() => ['schema_version' => 1, 'messages' => []],
    diplomacy_proposals_path() => ['schema_version' => 1, 'proposals' => []],
    diplomacy_treaties_path() => ['schema_version' => 1, 'treaties' => []],
    diplomacy_relations_path() => ['schema_version' => 1, 'relations' => []],
    diplomacy_unread_path() => ['schema_version' => 1, 'rows' => []],
    diplomacy_idempotency_path() => ['schema_version' => 1, 'keys' => []],
    diplomacy_settings_path() => ['schema_version' => 1, 'feature_enabled' => true, 'allow_vk_chat_public_announcements' => false],
    diplomacy_logs_path() => ['schema_version' => 1, 'rows' => []],
  ];
  foreach ($seed as $path => $value) if (!is_file($path)) api_atomic_write_json($path, $value);
}

function diplomacy_load_json(string $path, array $fallback): array {
  $raw = @file_get_contents($path);
  $decoded = is_string($raw) ? json_decode($raw, true) : null;
  return is_array($decoded) ? $decoded : $fallback;
}

function diplomacy_load_threads_store(): array {
  diplomacy_ensure_store();
  $store = diplomacy_load_json(diplomacy_threads_path(), ['schema_version' => 1, 'threads' => []]);
  if (!is_array($store['threads'] ?? null)) $store['threads'] = [];
  return $store;
}
function diplomacy_save_threads_store(array $store): bool { $store['updated_at'] = gmdate('c'); return api_atomic_write_json(diplomacy_threads_path(), $store); }

function diplomacy_load_messages_store(): array {
  diplomacy_ensure_store();
  $store = diplomacy_load_json(diplomacy_messages_path(), ['schema_version' => 1, 'messages' => []]);
  if (!is_array($store['messages'] ?? null)) $store['messages'] = [];
  return $store;
}
function diplomacy_save_messages_store(array $store): bool { $store['updated_at'] = gmdate('c'); return api_atomic_write_json(diplomacy_messages_path(), $store); }

function diplomacy_load_proposals_store(): array {
  diplomacy_ensure_store();
  $store = diplomacy_load_json(diplomacy_proposals_path(), ['schema_version' => 1, 'proposals' => []]);
  if (!is_array($store['proposals'] ?? null)) $store['proposals'] = [];
  return $store;
}
function diplomacy_save_proposals_store(array $store): bool { $store['updated_at'] = gmdate('c'); return api_atomic_write_json(diplomacy_proposals_path(), $store); }

function diplomacy_load_treaties_store(): array {
  diplomacy_ensure_store();
  $store = diplomacy_load_json(diplomacy_treaties_path(), ['schema_version' => 1, 'treaties' => []]);
  if (!is_array($store['treaties'] ?? null)) $store['treaties'] = [];
  return $store;
}
function diplomacy_save_treaties_store(array $store): bool { $store['updated_at'] = gmdate('c'); return api_atomic_write_json(diplomacy_treaties_path(), $store); }

function diplomacy_load_relations_store(): array { diplomacy_ensure_store(); return diplomacy_load_json(diplomacy_relations_path(), ['schema_version' => 1, 'relations' => []]); }
function diplomacy_save_relations_store(array $store): bool { $store['updated_at'] = gmdate('c'); return api_atomic_write_json(diplomacy_relations_path(), $store); }
function diplomacy_load_unread_store(): array { diplomacy_ensure_store(); return diplomacy_load_json(diplomacy_unread_path(), ['schema_version' => 1, 'rows' => []]); }
function diplomacy_save_unread_store(array $store): bool { $store['updated_at'] = gmdate('c'); return api_atomic_write_json(diplomacy_unread_path(), $store); }

function diplomacy_claim_idempotency(string $key): bool {
  $key = trim($key);
  if ($key === '') return true;
  diplomacy_ensure_store();
  $fp = fopen(diplomacy_idempotency_path(), 'c+');
  if (!$fp) return false;
  try {
    if (!flock($fp, LOCK_EX)) return false;
    $raw = stream_get_contents($fp);
    $store = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($store)) $store = ['schema_version' => 1, 'keys' => []];
    if (isset($store['keys'][$key])) return false;
    $store['keys'][$key] = ['claimed_at' => gmdate('c')];
    if (count($store['keys']) > 4000) $store['keys'] = array_slice($store['keys'], -2500, null, true);
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($fp);
  } finally { flock($fp, LOCK_UN); fclose($fp); }
  return true;
}

function diplomacy_append_log(string $action, array $payload = []): void {
  $store = diplomacy_load_json(diplomacy_logs_path(), ['schema_version' => 1, 'rows' => []]);
  if (!is_array($store['rows'] ?? null)) $store['rows'] = [];
  $store['rows'][] = ['id' => 'dl_' . substr(hash('sha1', microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12), 'created_at' => gmdate('c'), 'action' => $action, 'payload' => $payload];
  if (count($store['rows']) > 5000) $store['rows'] = array_slice($store['rows'], -3000);
  api_atomic_write_json(diplomacy_logs_path(), $store);
}
