<?php

declare(strict_types=1);

function telegraph_data_dir(): string { return api_repo_root() . '/data/telegraph'; }
function telegraph_messages_path(): string { return telegraph_data_dir() . '/messages.json'; }
function telegraph_channels_path(): string { return telegraph_data_dir() . '/channels.json'; }
function telegraph_unread_path(): string { return telegraph_data_dir() . '/unread.json'; }
function telegraph_relay_log_path(): string { return telegraph_data_dir() . '/relay_log.json'; }
function telegraph_settings_path(): string { return telegraph_data_dir() . '/settings.json'; }
function telegraph_threads_path(): string { return telegraph_data_dir() . '/threads.json'; }
function telegraph_idempotency_path(): string { return telegraph_data_dir() . '/idempotency.json'; }

function telegraph_store_mtime(): int {
  $paths = [telegraph_messages_path(), telegraph_channels_path(), telegraph_unread_path(), telegraph_relay_log_path(), telegraph_settings_path(), telegraph_threads_path(), telegraph_idempotency_path()];
  $max = 0;
  foreach ($paths as $p) $max = max($max, (int)@filemtime($p));
  return $max > 0 ? $max : time();
}

function telegraph_ensure_store(): void {
  if (!is_dir(telegraph_data_dir())) @mkdir(telegraph_data_dir(), 0775, true);
  $seed = [
    telegraph_messages_path() => ['schema_version' => 1, 'messages' => []],
    telegraph_channels_path() => ['schema_version' => 1, 'channels' => []],
    telegraph_unread_path() => ['schema_version' => 1, 'rows' => []],
    telegraph_relay_log_path() => ['schema_version' => 1, 'rows' => []],
    telegraph_settings_path() => ['schema_version' => 1, 'auto_approve_web_public' => false, 'auto_approve_vk_public' => false, 'relay_enabled' => true],
    telegraph_threads_path() => ['schema_version' => 1, 'threads' => []],
    telegraph_idempotency_path() => ['schema_version' => 1, 'keys' => []],
  ];
  foreach ($seed as $path => $value) {
    if (!is_file($path)) api_atomic_write_json($path, $value);
  }
}

function telegraph_load_json(string $path, array $fallback): array {
  $raw = @file_get_contents($path);
  $decoded = is_string($raw) ? json_decode($raw, true) : null;
  return is_array($decoded) ? $decoded : $fallback;
}

function telegraph_load_messages_store(): array {
  telegraph_ensure_store();
  $store = telegraph_load_json(telegraph_messages_path(), ['schema_version' => 1, 'messages' => []]);
  if (!is_array($store['messages'] ?? null)) $store['messages'] = [];
  return $store;
}

function telegraph_save_messages_store(array $store): bool {
  $store['updated_at'] = gmdate('c');
  if (!isset($store['schema_version'])) $store['schema_version'] = 1;
  return api_atomic_write_json(telegraph_messages_path(), $store);
}

function telegraph_load_channels_store(): array {
  telegraph_ensure_store();
  $store = telegraph_load_json(telegraph_channels_path(), ['schema_version' => 1, 'channels' => []]);
  if (!is_array($store['channels'] ?? null)) $store['channels'] = [];
  return $store;
}

function telegraph_save_channels_store(array $store): bool {
  if (!isset($store['schema_version'])) $store['schema_version'] = 1;
  return api_atomic_write_json(telegraph_channels_path(), $store);
}

function telegraph_load_relay_log_store(): array {
  telegraph_ensure_store();
  $store = telegraph_load_json(telegraph_relay_log_path(), ['schema_version' => 1, 'rows' => []]);
  if (!is_array($store['rows'] ?? null)) $store['rows'] = [];
  return $store;
}

function telegraph_save_relay_log_store(array $store): bool {
  if (!isset($store['schema_version'])) $store['schema_version'] = 1;
  return api_atomic_write_json(telegraph_relay_log_path(), $store);
}

function telegraph_append_relay_log(array $row): void {
  $store = telegraph_load_relay_log_store();
  $store['rows'][] = [
    'id' => telegraph_next_id('relay'),
    'created_at' => gmdate('c'),
    'message_id' => trim((string)($row['message_id'] ?? '')),
    'chat_id' => (int)($row['chat_id'] ?? 0),
    'status' => trim((string)($row['status'] ?? 'sent')),
    'error' => mb_substr(trim((string)($row['error'] ?? '')), 0, 500),
    'source' => trim((string)($row['source'] ?? 'relay_endpoint')),
  ];
  if (count($store['rows']) > 3000) $store['rows'] = array_slice($store['rows'], -2000);
  telegraph_save_relay_log_store($store);
}

function telegraph_relay_already_done(string $messageId, int $chatId): bool {
  if ($messageId === '' || $chatId <= 0) return false;
  $store = telegraph_load_relay_log_store();
  foreach (array_reverse($store['rows']) as $row) {
    if (!is_array($row)) continue;
    if ((string)($row['message_id'] ?? '') !== $messageId) continue;
    if ((int)($row['chat_id'] ?? 0) !== $chatId) continue;
    if ((string)($row['status'] ?? '') === 'sent') return true;
  }
  return false;
}

function telegraph_load_settings_store(): array {
  telegraph_ensure_store();
  return telegraph_load_json(telegraph_settings_path(), ['schema_version' => 1, 'auto_approve_web_public' => false, 'auto_approve_vk_public' => false, 'relay_enabled' => true]);
}

function telegraph_load_threads_store(): array {
  telegraph_ensure_store();
  $store = telegraph_load_json(telegraph_threads_path(), ['schema_version' => 1, 'threads' => []]);
  if (!is_array($store['threads'] ?? null)) $store['threads'] = [];
  return $store;
}

function telegraph_save_threads_store(array $store): bool {
  if (!isset($store['schema_version'])) $store['schema_version'] = 1;
  return api_atomic_write_json(telegraph_threads_path(), $store);
}

function telegraph_claim_idempotency(string $key, string $messageId = ''): bool {
  $key = trim($key);
  if ($key === '') return true;
  telegraph_ensure_store();
  $fp = fopen(telegraph_idempotency_path(), 'c+');
  if (!$fp) return false;
  try {
    if (!flock($fp, LOCK_EX)) return false;
    $raw = stream_get_contents($fp);
    $store = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($store)) $store = ['schema_version' => 1, 'keys' => []];
    if (!is_array($store['keys'] ?? null)) $store['keys'] = [];
    if (isset($store['keys'][$key])) return false;
    $store['keys'][$key] = ['claimed_at' => gmdate('c'), 'message_id' => $messageId];
    if (count($store['keys']) > 4000) {
      $store['keys'] = array_slice($store['keys'], -2500, null, true);
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($fp);
  } finally {
    flock($fp, LOCK_UN);
    fclose($fp);
  }
  return true;
}
