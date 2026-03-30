<?php

declare(strict_types=1);

require_once __DIR__ . '/state_api.php';
require_once __DIR__ . '/player_admin_api.php';

function vk_bot_config_path(): string { return api_repo_root() . '/data/vk_bot_config.json'; }
function vk_bot_sessions_path(): string { return api_repo_root() . '/data/vk_bot_sessions.json'; }
function vk_bot_applications_path(): string { return api_repo_root() . '/data/vk_bot_applications.json'; }
function vk_bot_character_applications_path(): string { return api_repo_root() . '/data/vk_bot_character_applications.json'; }
function vk_bot_image_usage_path(): string { return api_repo_root() . '/data/vk_bot_image_usage.json'; }
function vk_bot_image_generations_log_path(): string { return api_repo_root() . '/data/vk_bot_image_generations_log.json'; }
function vk_bot_admin_mode_path(): string { return api_repo_root() . '/data/vk_bot_admin_mode.json'; }
function vk_bot_idempotency_path(): string { return api_repo_root() . '/data/vk_bot_idempotency.json'; }

function vk_bot_files_mtime(array $paths): int {
  $mt = 0;
  foreach ($paths as $path) {
    if (!is_string($path) || $path === '') continue;
    $fm = (int)@filemtime($path);
    if ($fm > $mt) $mt = $fm;
  }
  return $mt > 0 ? $mt : time();
}

function vk_bot_data_mtime(): int {
  return vk_bot_files_mtime([
    vk_bot_config_path(),
    vk_bot_sessions_path(),
    vk_bot_applications_path(),
    vk_bot_character_applications_path(),
    vk_bot_image_usage_path(),
    vk_bot_image_generations_log_path(),
    vk_bot_admin_mode_path(),
    vk_bot_idempotency_path(),
  ]);
}

function vk_bot_load_json_file(string $path, array $fallback = []): array {
  if (!is_file($path)) return $fallback;
  $raw = @file_get_contents($path);
  if (!is_string($raw) || trim($raw) === '') return $fallback;
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : $fallback;
}

function vk_bot_load_config(): array {
  $cfg = vk_bot_load_json_file(vk_bot_config_path(), []);
  return [
    'enabled' => (bool)($cfg['enabled'] ?? false),
    'group_id' => trim((string)($cfg['group_id'] ?? '')),
    'confirmation_token' => trim((string)($cfg['confirmation_token'] ?? '')),
    'secret' => trim((string)($cfg['secret'] ?? '')),
    'access_token' => trim((string)($cfg['access_token'] ?? '')),
    'api_version' => trim((string)($cfg['api_version'] ?? '5.199')),
    'public_base_url' => rtrim(trim((string)($cfg['public_base_url'] ?? '')), '/'),
    'routerai_api_key' => trim((string)($cfg['routerai_api_key'] ?? '')),
    'mini_app_url' => trim((string)($cfg['mini_app_url'] ?? '')),
  ];
}

function vk_bot_save_config(array $cfg): bool {
  return api_atomic_write_json(vk_bot_config_path(), [
    'enabled' => (bool)($cfg['enabled'] ?? false),
    'group_id' => trim((string)($cfg['group_id'] ?? '')),
    'confirmation_token' => trim((string)($cfg['confirmation_token'] ?? '')),
    'secret' => trim((string)($cfg['secret'] ?? '')),
    'access_token' => trim((string)($cfg['access_token'] ?? '')),
    'api_version' => trim((string)($cfg['api_version'] ?? '5.199')),
    'public_base_url' => rtrim(trim((string)($cfg['public_base_url'] ?? '')), '/'),
    'routerai_api_key' => trim((string)($cfg['routerai_api_key'] ?? '')),
    'mini_app_url' => trim((string)($cfg['mini_app_url'] ?? '')),
  ]);
}

function vk_bot_load_sessions(): array { return vk_bot_load_json_file(vk_bot_sessions_path(), []); }
function vk_bot_save_sessions(array $rows): bool { return api_atomic_write_json(vk_bot_sessions_path(), $rows); }
function vk_bot_load_applications(): array { return vk_bot_load_json_file(vk_bot_applications_path(), []); }
function vk_bot_save_applications(array $rows): bool { return api_atomic_write_json(vk_bot_applications_path(), $rows); }
function vk_bot_load_character_applications(): array { return vk_bot_load_json_file(vk_bot_character_applications_path(), []); }
function vk_bot_save_character_applications(array $rows): bool { return api_atomic_write_json(vk_bot_character_applications_path(), $rows); }
function vk_bot_load_image_usage(): array { return vk_bot_load_json_file(vk_bot_image_usage_path(), []); }
function vk_bot_save_image_usage(array $rows): bool { return api_atomic_write_json(vk_bot_image_usage_path(), $rows); }
function vk_bot_load_image_generations_log(): array { return vk_bot_load_json_file(vk_bot_image_generations_log_path(), []); }
function vk_bot_save_image_generations_log(array $rows): bool { return api_atomic_write_json(vk_bot_image_generations_log_path(), $rows); }
function vk_bot_load_idempotency_store(): array {
  $store = vk_bot_load_json_file(vk_bot_idempotency_path(), []);
  if (!is_array($store['events'] ?? null)) $store['events'] = [];
  return $store;
}
function vk_bot_save_idempotency_store(array $store): bool {
  return api_atomic_write_json(vk_bot_idempotency_path(), [
    'events' => is_array($store['events'] ?? null) ? $store['events'] : [],
  ]);
}
function vk_bot_claim_event(string $eventKey, int $ttlSec = 86400, int $maxItems = 5000): bool {
  $eventKey = trim($eventKey);
  if ($eventKey === '') return false;
  $now = time();
  $store = vk_bot_load_idempotency_store();
  $events = is_array($store['events'] ?? null) ? $store['events'] : [];
  $next = [];
  foreach ($events as $row) {
    if (!is_array($row)) continue;
    $key = trim((string)($row['key'] ?? ''));
    if ($key === '') continue;
    $ts = (int)($row['ts'] ?? 0);
    if ($ttlSec > 0 && ($now - $ts) > $ttlSec) continue;
    if ($key === $eventKey) return false;
    $next[] = ['key' => $key, 'ts' => $ts];
  }
  $next[] = ['key' => $eventKey, 'ts' => $now];
  if ($maxItems > 0 && count($next) > $maxItems) {
    $next = array_slice($next, -$maxItems);
  }
  $store['events'] = $next;
  vk_bot_save_idempotency_store($store);
  return true;
}
function vk_bot_load_admin_mode_store(): array {
  $row = vk_bot_load_json_file(vk_bot_admin_mode_path(), []);
  if (!is_array($row['confirmations'] ?? null)) $row['confirmations'] = [];
  if (!is_array($row['pending_codes'] ?? null)) $row['pending_codes'] = [];
  if (!is_array($row['requests'] ?? null)) $row['requests'] = [];
  return $row;
}
function vk_bot_save_admin_mode_store(array $store): bool {
  return api_atomic_write_json(vk_bot_admin_mode_path(), [
    'confirmations' => is_array($store['confirmations'] ?? null) ? $store['confirmations'] : [],
    'pending_codes' => is_array($store['pending_codes'] ?? null) ? $store['pending_codes'] : [],
    'requests' => is_array($store['requests'] ?? null) ? array_values($store['requests']) : [],
  ]);
}

function vk_bot_append_image_generation_log(array $row): void {
  $rows = vk_bot_load_image_generations_log();
  $rows[] = [
    'ts' => time(),
    'vk_user_id' => (int)($row['vk_user_id'] ?? 0),
    'prompt' => mb_substr(trim((string)($row['prompt'] ?? '')), 0, 500),
    'ok' => (bool)($row['ok'] ?? false),
    'error' => trim((string)($row['error'] ?? '')),
    'http_code' => (int)($row['http_code'] ?? 0),
    'router_response' => mb_substr(trim((string)($row['router_response'] ?? '')), 0, 4000),
  ];
  if (count($rows) > 200) {
    $rows = array_slice($rows, -200);
  }
  vk_bot_save_image_generations_log($rows);
}

function vk_bot_image_master_prompt(): string {
  return 'Масляный портрет в стиле 17 века с элементами постапокалипсиса на заднем плане (респиратор, ржавый лом и т.д.)';
}

function vk_bot_image_user_limit(): int { return 10; }

function vk_bot_log_error(string $message): void {
  @file_put_contents(api_repo_root() . '/data/vk_bot_last_error.log', date('c') . ' ' . $message . "\n", FILE_APPEND);
}

function vk_bot_set_last_api_error(string $code, array $details = []): void {
  $GLOBALS['vk_bot_last_api_error'] = [
    'code' => $code,
    'details' => $details,
    'ts' => time(),
  ];
}

function vk_bot_get_last_api_error(): array {
  $row = $GLOBALS['vk_bot_last_api_error'] ?? null;
  return is_array($row) ? $row : [];
}

function vk_bot_set_last_render_error(?string $reason): void {
  $GLOBALS['vk_bot_last_render_error'] = $reason;
}

function vk_bot_get_last_render_error(): ?string {
  $reason = $GLOBALS['vk_bot_last_render_error'] ?? null;
  return is_string($reason) && $reason !== '' ? $reason : null;
}

function vk_bot_slug(string $value): string {
  $v = preg_replace('/[^\pL\pN]+/u', '_', trim($value));
  $v = trim((string)$v, '_');
  if ($v === '') $v = 'entity_' . substr(hash('sha1', $value . ':' . microtime(true)), 0, 8);
  return mb_strtolower($v, 'UTF-8');
}

function vk_bot_user_session(array $sessions, int $userId): array {
  $row = $sessions[(string)$userId] ?? null;
  return is_array($row) ? $row : ['stage' => 'start', 'data' => []];
}

function vk_bot_set_user_session(array &$sessions, int $userId, array $session): void { $sessions[(string)$userId] = $session; }

function vk_bot_keyboard(array $buttons): string {
  return json_encode(['one_time' => false, 'buttons' => $buttons], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"buttons":[]}';
}

function vk_bot_btn_item(string $label, string $cmd, string $color = 'primary'): array {
  return [
    'action' => [
      'type' => 'text',
      'label' => $label,
      'payload' => json_encode(['cmd' => $cmd], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ],
    'color' => $color,
  ];
}

function vk_bot_btn(string $label, string $cmd, string $color = 'primary'): array {
  return [vk_bot_btn_item($label, $cmd, $color)];
}

function vk_bot_vk_api_call(string $method, array $params): ?array {
  $cfg = vk_bot_load_config();
  vk_bot_set_last_api_error('none', []);
  if ($cfg['access_token'] === '') {
    vk_bot_set_last_api_error('missing_access_token', ['method' => $method]);
    return null;
  }
  $params['v'] = $cfg['api_version'] !== '' ? $cfg['api_version'] : '5.199';
  $params['access_token'] = $cfg['access_token'];

  $ch = curl_init('https://api.vk.com/method/' . $method);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);
  if (!is_string($resp) || $resp === '' || $err !== '') {
    vk_bot_set_last_api_error('curl_error', ['method' => $method, 'curl_error' => $err, 'response_excerpt' => substr((string)$resp, 0, 300)]);
    vk_bot_log_error('vk_api_call_error method=' . $method . ' err=' . $err);
    return null;
  }
  $decoded = json_decode($resp, true);
  if (!is_array($decoded)) {
    vk_bot_set_last_api_error('invalid_json', ['method' => $method, 'response_excerpt' => substr($resp, 0, 300)]);
    vk_bot_log_error('vk_api_invalid_json method=' . $method);
    return null;
  }
  if (isset($decoded['error'])) {
    vk_bot_set_last_api_error('vk_api_error', [
      'method' => $method,
      'vk_error' => is_array($decoded['error']) ? $decoded['error'] : ['raw' => $decoded['error']],
    ]);
    vk_bot_log_error('vk_api_error method=' . $method . ' details=' . json_encode($decoded['error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return null;
  }
  return $decoded;
}

function vk_bot_send_message(int $userId, string $message, ?string $keyboardJson = null, string $attachment = ''): void {
  $cfg = vk_bot_load_config();
  if ($cfg['access_token'] === '') return;
  $params = [
    'user_id' => $userId,
    'random_id' => random_int(1, PHP_INT_MAX),
    'message' => $message,
  ];
  if ($keyboardJson !== null && $keyboardJson !== '') $params['keyboard'] = $keyboardJson;
  if ($attachment !== '') $params['attachment'] = $attachment;
  vk_bot_vk_api_call('messages.send', $params);
}

function vk_bot_send_peer_message(int $peerId, string $message, string $attachment = ''): void {
  $cfg = vk_bot_load_config();
  if ($cfg['access_token'] === '') return;
  $params = [
    'peer_id' => $peerId,
    'random_id' => random_int(1, PHP_INT_MAX),
    'message' => $message,
  ];
  if ($attachment !== '') $params['attachment'] = $attachment;
  vk_bot_vk_api_call('messages.send', $params);
}


function vk_bot_upload_message_photo_blob(int $userId, string $raw, string $fileName = 'generated.png', string $mimeHint = ''): string {
  if ($raw === '') return '';
  $serverResp = vk_bot_vk_api_call('photos.getMessagesUploadServer', ['peer_id' => $userId]);
  $uploadUrl = trim((string)($serverResp['response']['upload_url'] ?? ''));
  if ($uploadUrl === '') return '';

  $tmpFile = tempnam(sys_get_temp_dir(), 'vkimg_');
  if (!is_string($tmpFile) || $tmpFile === '') return '';
  if (@file_put_contents($tmpFile, $raw) === false) { @unlink($tmpFile); return ''; }

  $mime = trim($mimeHint);
  if ($mime === '') {
    if (function_exists('finfo_open')) {
      $fi = finfo_open(FILEINFO_MIME_TYPE);
      $det = is_resource($fi) ? finfo_buffer($fi, $raw) : false;
      if (is_resource($fi)) finfo_close($fi);
      if (is_string($det) && $det !== '') $mime = $det;
    }
  }
  if ($mime === '') $mime = 'application/octet-stream';
  $cfile = curl_file_create($tmpFile, $mime, $fileName);
  $ch = curl_init($uploadUrl);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, ['photo' => $cfile]);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 40);
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);
  @unlink($tmpFile);

  if (!is_string($resp) || $resp === '' || $err !== '') {
    vk_bot_log_error('vk_upload_photo_error err=' . $err);
    return '';
  }
  $uploadDecoded = json_decode($resp, true);
  if (!is_array($uploadDecoded)) return '';

  $saveResp = vk_bot_vk_api_call('photos.saveMessagesPhoto', [
    'photo' => (string)($uploadDecoded['photo'] ?? ''),
    'server' => (string)($uploadDecoded['server'] ?? ''),
    'hash' => (string)($uploadDecoded['hash'] ?? ''),
  ]);
  $saved = $saveResp['response'][0] ?? null;
  if (!is_array($saved)) return '';
  $ownerId = (int)($saved['owner_id'] ?? 0);
  $photoId = (int)($saved['id'] ?? 0);
  if ($ownerId === 0 || $photoId === 0) return '';
  return 'photo' . $ownerId . '_' . $photoId;
}


function vk_bot_upload_wall_photo_blob(string $raw, string $fileName = 'order.png', string $mimeHint = ''): string {
  if ($raw === '') return '';
  $cfg = vk_bot_load_config();
  $groupId = preg_replace('/[^0-9]/', '', (string)($cfg['group_id'] ?? ''));
  if ($groupId === '') return '';

  $serverResp = vk_bot_vk_api_call('photos.getWallUploadServer', ['group_id' => $groupId]);
  $uploadUrl = trim((string)($serverResp['response']['upload_url'] ?? ''));
  if ($uploadUrl === '') return '';

  $tmpFile = tempnam(sys_get_temp_dir(), 'vkwall_');
  if (!is_string($tmpFile) || $tmpFile === '') return '';
  if (@file_put_contents($tmpFile, $raw) === false) { @unlink($tmpFile); return ''; }

  $mime = trim($mimeHint);
  if ($mime === '') {
    if (function_exists('finfo_open')) {
      $fi = finfo_open(FILEINFO_MIME_TYPE);
      $det = is_resource($fi) ? finfo_buffer($fi, $raw) : false;
      if (is_resource($fi)) finfo_close($fi);
      if (is_string($det) && $det !== '') $mime = $det;
    }
  }
  if ($mime === '') $mime = 'application/octet-stream';
  $cfile = curl_file_create($tmpFile, $mime, $fileName);
  $ch = curl_init($uploadUrl);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, ['photo' => $cfile]);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 40);
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);
  @unlink($tmpFile);

  if (!is_string($resp) || $resp === '' || $err !== '') {
    vk_bot_log_error('vk_upload_wall_photo_error err=' . $err);
    return '';
  }
  $decoded = json_decode($resp, true);
  if (!is_array($decoded)) return '';

  $saveResp = vk_bot_vk_api_call('photos.saveWallPhoto', [
    'group_id' => $groupId,
    'photo' => (string)($decoded['photo'] ?? ''),
    'server' => (string)($decoded['server'] ?? ''),
    'hash' => (string)($decoded['hash'] ?? ''),
  ]);
  $saved = is_array($saveResp['response'] ?? null) ? $saveResp['response'][0] ?? null : null;
  if (!is_array($saved)) return '';
  $owner = (string)($saved['owner_id'] ?? '');
  $pid = (string)($saved['id'] ?? '');
  if ($owner === '' || $pid === '') return '';
  return 'photo' . $owner . '_' . $pid;
}



function vk_bot_str_starts_with(string $haystack, string $needle): bool {
  if ($needle === '') return true;
  return strpos($haystack, $needle) === 0;
}

function vk_bot_cache_remote_image_for_orders(string $url): array {
  $u = trim($url);
  if ($u === '' || !preg_match('#^https?://#i', $u)) return ['ok' => false, 'error' => 'invalid_url'];
  $ch = curl_init($u);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
  curl_setopt($ch, CURLOPT_USERAGENT, 'adminmap-vk-bot/1.0');
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $ctype = trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
  curl_close($ch);
  if (!is_string($raw) || $raw === '' || $err !== '' || $code < 200 || $code >= 300) {
    return ['ok' => false, 'error' => 'download_failed', 'http_code' => $code, 'curl_error' => $err];
  }

  $mime = '';
  if ($ctype !== '') $mime = trim(explode(';', $ctype)[0]);
  if ($mime === '' && function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $det = is_resource($fi) ? finfo_buffer($fi, $raw) : false;
    if (is_resource($fi)) finfo_close($fi);
    if (is_string($det) && $det !== '') $mime = $det;
  }
  $allowed = [
    'image/jpeg'=>'jpg',
    'image/jpg'=>'jpg',
    'image/pjpeg'=>'jpg',
    'image/png'=>'png',
    'image/x-png'=>'png',
    'image/webp'=>'webp',
    'image/gif'=>'gif',
  ];
  if (!isset($allowed[$mime])) {
    $ext = mb_strtolower(pathinfo(parse_url($u, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    $byExt = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif'];
    if (isset($byExt[$ext])) {
      $mime = array_search($byExt[$ext], $allowed, true) ?: $mime;
    } else {
      return ['ok' => false, 'error' => 'unsupported_mime', 'mime' => $mime];
    }
  }

  $dir = api_repo_root() . '/data/orders_uploads';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  if (!is_dir($dir)) return ['ok' => false, 'error' => 'upload_dir_missing'];
  $name = 'ord_vk_' . substr(hash('sha256', $u . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX)), 0, 16) . '.' . $allowed[$mime];
  $dest = $dir . '/' . $name;
  if (@file_put_contents($dest, $raw) === false) return ['ok' => false, 'error' => 'write_failed'];
  return [
    'ok' => true,
    'url' => '/data/orders_uploads/' . $name,
    'mime' => $mime,
    'size_bytes' => (int)strlen($raw),
    'checksum_sha1' => sha1($raw),
    'file_name' => $name,
    'source_url' => $u,
  ];
}

function vk_bot_download_vk_attachment_image(string $vkAttachment): array {
  $att = trim($vkAttachment);
  if ($att === '') return ['ok' => false, 'error' => 'empty_attachment'];
  if (vk_bot_str_starts_with($att, 'photo')) $att = substr($att, 5);
  $parts = explode('_', $att, 3);
  if (count($parts) < 2) return ['ok' => false, 'error' => 'invalid_attachment_format', 'attachment' => $vkAttachment];
  $ownerId = trim((string)$parts[0]);
  $photoId = trim((string)$parts[1]);
  $accessKey = trim((string)($parts[2] ?? ''));
  if ($ownerId === '' || $photoId === '') return ['ok' => false, 'error' => 'invalid_attachment_parts', 'attachment' => $vkAttachment];

  $photos = $ownerId . '_' . $photoId;
  if ($accessKey !== '') $photos .= '_' . $accessKey;
  $resp = vk_bot_vk_api_call('photos.getById', ['photos' => $photos, 'extended' => 0]);
  $row = is_array($resp['response'] ?? null) ? ($resp['response'][0] ?? null) : null;
  if (!is_array($row)) return ['ok' => false, 'error' => 'photo_not_found', 'attachment' => $vkAttachment, 'api_error' => vk_bot_get_last_api_error()];

  $sizes = is_array($row['sizes'] ?? null) ? $row['sizes'] : [];
  $bestUrl = '';
  $bestArea = -1;
  foreach ($sizes as $sz) {
    if (!is_array($sz)) continue;
    $url = trim((string)($sz['url'] ?? ''));
    if ($url === '') continue;
    $w = (int)($sz['width'] ?? 0);
    $h = (int)($sz['height'] ?? 0);
    $area = $w * $h;
    if ($area > $bestArea) { $bestArea = $area; $bestUrl = $url; }
  }
  if ($bestUrl === '') return ['ok' => false, 'error' => 'photo_url_missing', 'attachment' => $vkAttachment];

  $ch = curl_init($bestUrl);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
  curl_setopt($ch, CURLOPT_USERAGENT, 'adminmap-vk-bot/1.0');
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $ctype = trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
  curl_close($ch);
  if (!is_string($raw) || $raw === '' || $err !== '' || $code < 200 || $code >= 300) {
    return ['ok' => false, 'error' => 'photo_download_failed', 'attachment' => $vkAttachment, 'http_code' => $code, 'curl_error' => $err];
  }
  $path = parse_url($bestUrl, PHP_URL_PATH);
  $ext = is_string($path) ? mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
  $name = 'vk_att_' . preg_replace('/[^0-9\-]/', '', $ownerId) . '_' . preg_replace('/[^0-9]/', '', $photoId) . ($ext !== '' ? ('.' . $ext) : '.jpg');
  return ['ok' => true, 'raw' => $raw, 'content_type' => $ctype, 'file_name' => $name];
}

function vk_bot_try_build_wall_photo_attachment($value): string {
  if (is_array($value)) {
    $vkAtt = trim((string)($value['vk_attachment'] ?? ''));
    $vkKey = trim((string)($value['vk_access_key'] ?? ''));
    if ($vkAtt !== '') {
      if ($vkKey !== '' && strpos($vkAtt, '_') !== false && substr_count($vkAtt, '_') < 2) $vkAtt .= '_' . $vkKey;
      $fetched = vk_bot_download_vk_attachment_image($vkAtt);
      if (($fetched['ok'] ?? false) === true) {
        $uploaded = vk_bot_upload_wall_photo_blob((string)$fetched['raw'], (string)($fetched['file_name'] ?? 'vk_attachment.jpg'), (string)($fetched['content_type'] ?? ''));
        if ($uploaded !== '') return $uploaded;
        vk_bot_set_last_api_error('wall_upload_failed_after_vk_fetch', ['vk_attachment' => $vkAtt]);
      } else {
        vk_bot_set_last_api_error('vk_wall_attachment_fetch_failed', is_array($fetched) ? $fetched : ['raw' => $fetched]);
      }
      vk_bot_log_error('vk_wall_attachment_fetch_failed ' . json_encode($fetched, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      // Fallback to URL-based upload if URL exists in payload.
    }
    $meta = is_array($value['meta'] ?? null) ? $value['meta'] : [];
    $value = (string)($value['url'] ?? $value['src'] ?? $value['href'] ?? $value['image_url'] ?? $meta['vk_source_url'] ?? $meta['source_url'] ?? '');
  }
  $url = trim((string)$value);
  if ($url !== '' && !preg_match('~^https?://~i', $url) && !vk_bot_str_starts_with($url, '/')) {
    if (vk_bot_str_starts_with($url, 'data/orders_uploads/')) $url = '/' . $url;
  }
  if ($url === '') return '';

  $tryResolveLocalPathFromUrl = static function(string $candidateUrl): string {
    $parts = parse_url($candidateUrl);
    if (!is_array($parts)) return '';
    $path = rawurldecode(trim((string)($parts['path'] ?? '')));
    if ($path === '' || !vk_bot_str_starts_with($path, '/data/orders_uploads/')) return '';

    // For our own uploads we trust path prefix and map directly to disk.
    // This avoids failures when public_base_url scheme/host differs (e.g. https in config, http in runtime).
    return api_repo_root() . $path;
  };

  if (vk_bot_str_starts_with($url, '/')) {
    $path = api_repo_root() . $url;
  } elseif (preg_match('~^https?://~i', $url)) {
    $resolvedLocalPath = $tryResolveLocalPathFromUrl($url);
    if ($resolvedLocalPath !== '') {
      if (!is_file($resolvedLocalPath)) {
        vk_bot_set_last_api_error('wall_attachment_local_file_missing', ['url' => $url, 'path' => $resolvedLocalPath]);
        return '';
      }
      $raw = @file_get_contents($resolvedLocalPath);
      if (!is_string($raw) || $raw === '') {
        vk_bot_set_last_api_error('wall_attachment_local_file_read_failed', ['url' => $url, 'path' => $resolvedLocalPath]);
        return '';
      }
      $mime = mime_content_type($resolvedLocalPath) ?: '';
      return vk_bot_upload_wall_photo_blob($raw, basename($resolvedLocalPath), is_string($mime) ? $mime : '');
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_USERAGENT, 'adminmap-vk-bot/1.0');
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    curl_close($ch);
    if (!is_string($raw) || $raw === '' || $err !== '' || $code < 200 || $code >= 300) {
      vk_bot_set_last_api_error('wall_attachment_remote_download_failed', ['url' => $url, 'http_code' => $code, 'curl_error' => $err]);
      return '';
    }
    $ext = '';
    $path = parse_url($url, PHP_URL_PATH);
    if (is_string($path)) $ext = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $name = 'order_remote' . ($ext !== '' ? ('.' . $ext) : '.jpg');
    return vk_bot_upload_wall_photo_blob($raw, $name, $ctype);
  } else {
    return '';
  }

  if (!is_file($path)) {
    vk_bot_set_last_api_error('wall_attachment_local_file_missing', ['url' => $url, 'path' => $path]);
    return '';
  }
  $raw = @file_get_contents($path);
  if (!is_string($raw) || $raw === '') {
    vk_bot_set_last_api_error('wall_attachment_local_file_read_failed', ['url' => $url, 'path' => $path]);
    return '';
  }
  $mime = mime_content_type($path) ?: '';
  return vk_bot_upload_wall_photo_blob($raw, basename($path), is_string($mime) ? $mime : '');
}

function vk_bot_collect_image_candidates_from_value($value, string &$imageUrl, string &$b64): void {
  if ($imageUrl !== '' && $b64 !== '') return;

  if (is_string($value)) {
    $trimmed = trim($value);
    if ($trimmed === '') return;
    if ($imageUrl === '' && preg_match('#!\[[^\]]*\]\((https?://[^)]+)\)#u', $trimmed, $m)) $imageUrl = $m[1];
    if ($b64 === '' && preg_match('/^[A-Za-z0-9+\/\n\r=]{500,}$/', $trimmed)) $b64 = preg_replace('/\s+/', '', $trimmed) ?? '';
    return;
  }

  if (!is_array($value)) return;

  $stack = [$value];
  while (!empty($stack) && ($imageUrl === '' || $b64 === '')) {
    $current = array_pop($stack);
    if (!is_array($current)) continue;
    foreach ($current as $k => $v) {
      if (is_array($v)) {
        $stack[] = $v;
        if ((string)$k === 'image_url') {
          $nestedUrl = trim((string)($v['url'] ?? $v['href'] ?? ''));
          if ($nestedUrl !== '' && $imageUrl === '') $imageUrl = $nestedUrl;
        }
        continue;
      }
      if (!is_string($v)) continue;
      $key = (string)$k;
      $str = trim($v);
      if ($str === '') continue;
      if ($imageUrl === '' && $key === 'image_url' && preg_match('#^https?://#iu', $str)) {
        $imageUrl = $str;
      }
      if ($b64 === '' && in_array($key, ['image_base64', 'b64_json'], true)) {
        $b64 = preg_replace('/\s+/', '', $str) ?? '';
      }
      if ($b64 === '' && preg_match('/^[A-Za-z0-9+\/\n\r=]{500,}$/', $str)) {
        $b64 = preg_replace('/\s+/', '', $str) ?? '';
      }
      if ($imageUrl !== '' && $b64 !== '') break;
    }
  }
}

function vk_bot_prepare_router_response_for_log(array $decoded, string $raw): string {
  $copy = $decoded;
  if (isset($copy['choices']) && is_array($copy['choices'])) {
    foreach ($copy['choices'] as &$choice) {
      if (!is_array($choice)) continue;
      $msg = $choice['message'] ?? null;
      if (!is_array($msg)) continue;
      if (isset($msg['reasoning_details'])) unset($msg['reasoning_details']);
      if (isset($msg['reasoning']) && is_string($msg['reasoning']) && mb_strlen($msg['reasoning']) > 600) {
        $msg['reasoning'] = mb_substr($msg['reasoning'], 0, 600) . '…';
      }
      if (isset($msg['images']) && is_array($msg['images'])) {
        foreach ($msg['images'] as &$img) {
          if (!is_array($img)) continue;
          $iu = $img['image_url'] ?? null;
          if (!is_array($iu)) continue;
          $url = trim((string)($iu['url'] ?? ''));
          if ($url !== '' && preg_match('#^data:image/[^;]+;base64,#i', $url)) {
            $img['image_url'] = ['url' => '[data-image-base64 omitted]'];
          }
        }
        unset($img);
      }
      $choice['message'] = $msg;
    }
    unset($choice);
  }
  $encoded = json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (is_string($encoded) && $encoded !== '') return mb_substr($encoded, 0, 4000);
  return mb_substr($raw, 0, 4000);
}

function vk_bot_generate_character_image(string $userPrompt): array {
  $cfg = vk_bot_load_config();
  $apiKey = trim((string)($cfg['routerai_api_key'] ?? ''));
  if ($apiKey === '') return ['ok' => false, 'error' => 'missing_api_key', 'http_code' => 0, 'router_response' => ''];

  $payload = [
    'model' => 'openai/gpt-5-image-mini',
    'messages' => [
      ['role' => 'system', 'content' => vk_bot_image_master_prompt()],
      ['role' => 'user', 'content' => $userPrompt],
    ],
    'extra_body' => ['quality' => 'low', 'size' => '1024x1024'],
  ];

  $ch = curl_init('https://routerai.ru/api/v1/chat/completions');
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json',
  ]);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 300);
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  $routerCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if (!is_string($resp) || $resp === '' || $err !== '' || $routerCode >= 400) {
    vk_bot_log_error('routerai_error code=' . $routerCode . ' err=' . $err . ' body=' . substr((string)$resp, 0, 600));
    return ['ok' => false, 'error' => 'api_failed', 'http_code' => $routerCode, 'router_response' => substr((string)$resp, 0, 4000)];
  }
  $decoded = json_decode($resp, true);
  if (!is_array($decoded)) return ['ok' => false, 'error' => 'invalid_api_json', 'http_code' => $routerCode, 'router_response' => substr((string)$resp, 0, 4000)];
  $routerResponseForLog = vk_bot_prepare_router_response_for_log($decoded, (string)$resp);

  $imageUrl = '';
  $b64 = '';
  vk_bot_collect_image_candidates_from_value($decoded, $imageUrl, $b64);

  if ($b64 !== '') {
    $raw = base64_decode($b64, true);
    if (is_string($raw) && $raw !== '') return ['ok' => true, 'raw' => $raw, 'http_code' => $routerCode, 'router_response' => $routerResponseForLog];
  }
  if ($imageUrl !== '') {
    if (preg_match('#^data:image/[^;]+;base64,(.+)$#is', $imageUrl, $m)) {
      $raw = base64_decode(preg_replace('/\s+/', '', (string)$m[1]) ?? '', true);
      if (is_string($raw) && $raw !== '') {
        return ['ok' => true, 'raw' => $raw, 'http_code' => $routerCode, 'router_response' => $routerResponseForLog];
      }
      return ['ok' => false, 'error' => 'image_data_url_decode_failed', 'http_code' => $routerCode, 'router_response' => $routerResponseForLog];
    }

    $ch = curl_init($imageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $raw = curl_exec($ch);
    $downloadCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $downloadErr = curl_error($ch);
    curl_close($ch);
    if (is_string($raw) && $raw !== '' && $downloadCode < 400 && $downloadErr === '') {
      return ['ok' => true, 'raw' => $raw, 'http_code' => $routerCode, 'router_response' => $routerResponseForLog];
    }
    return ['ok' => false, 'error' => 'image_download_failed', 'http_code' => $routerCode, 'router_response' => $routerResponseForLog];
  }

  vk_bot_log_error('routerai_image_not_found body=' . substr($resp, 0, 1200));
  return ['ok' => false, 'error' => 'image_not_found', 'http_code' => $routerCode, 'router_response' => $routerResponseForLog];
}



function vk_bot_save_square_photo_blob(string $raw, string $personName = ''): ?string {
  if ($raw === '') return null;
  $slug = preg_replace('/[^a-z0-9_\-]+/i', '_', trim($personName));
  $slug = trim((string)$slug, '_');
  if ($slug === '') $slug = 'person';
  $hash = substr(sha1($personName . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX)), 0, 10);

  $dir = api_repo_root() . '/people';
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return null;
  if (!is_dir($dir)) return null;

  if (!function_exists('imagecreatefromstring')) {
    if (class_exists('Imagick')) {
      try {
        $img = new Imagick();
        $img->readImageBlob($raw);
        $w = $img->getImageWidth();
        $h = $img->getImageHeight();
        if ($w <= 1 || $h <= 1) return null;
        $side = min($w, $h);
        $x = (int)floor(($w - $side) / 2);
        $y = (int)floor(($h - $side) / 2);
        $targetSide = 512;
        $img->cropImage($side, $side, $x, $y);
        $img->setImagePage(0, 0, 0, 0);
        $img->resizeImage($targetSide, $targetSide, Imagick::FILTER_LANCZOS, 1);
        $img->setImageFormat('jpeg');
        $img->setImageCompressionQuality(88);
        $fileName = $slug . '_' . $hash . '.jpg';
        $path = $dir . '/' . $fileName;
        if (!$img->writeImage($path)) return null;
        $img->clear();
        $img->destroy();
        return 'people/' . $fileName;
      } catch (Throwable $e) {
        return null;
      }
    }

    $mime = '';
    if (function_exists('finfo_open')) {
      $finfo = @finfo_open(FILEINFO_MIME_TYPE);
      if ($finfo) {
        $detected = @finfo_buffer($finfo, $raw);
        if (is_string($detected)) $mime = strtolower(trim($detected));
        @finfo_close($finfo);
      }
    }
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extMap[$mime])) return null;
    $fileName = $slug . '_' . $hash . '.' . $extMap[$mime];
    if (@file_put_contents($dir . '/' . $fileName, $raw) === false) return null;
    return 'people/' . $fileName;
  }

  $src = @imagecreatefromstring($raw);
  if (!$src) return null;
  $w = imagesx($src);
  $h = imagesy($src);
  if ($w <= 1 || $h <= 1) { imagedestroy($src); return null; }

  $side = min($w, $h);
  $x = (int)floor(($w - $side) / 2);
  $y = (int)floor(($h - $side) / 2);
  $targetSide = 512;
  $dst = imagecreatetruecolor($targetSide, $targetSide);
  if (!$dst) { imagedestroy($src); return null; }
  if (!imagecopyresampled($dst, $src, 0, 0, $x, $y, $targetSide, $targetSide, $side, $side)) {
    imagedestroy($src); imagedestroy($dst); return null;
  }

  $fileName = $slug . '_' . $hash . '.jpg';
  $path = $dir . '/' . $fileName;
  if (!imagejpeg($dst, $path, 88)) { imagedestroy($src); imagedestroy($dst); return null; }
  imagedestroy($src);
  imagedestroy($dst);
  return 'people/' . $fileName;
}

function vk_bot_store_remote_photo(string $url, string $personName = ''): ?string {
  $url = trim($url);
  if ($url === '') return null;
  if (!preg_match('#^https?://#i', $url)) return null;

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
  curl_setopt($ch, CURLOPT_TIMEOUT, 15);
  curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
  curl_setopt($ch, CURLOPT_USERAGENT, 'adminmap-vk-bot/1.0');
  $raw = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if (!is_string($raw) || $raw === '' || $code >= 400 || $err !== '') return null;
  if (strlen($raw) > 12 * 1024 * 1024) return null;

  return vk_bot_save_square_photo_blob($raw, $personName);
}

function vk_bot_payload_cmd(array $object): string {
  $payloadRaw = $object['payload'] ?? null;
  if (is_array($payloadRaw)) {
    return trim((string)($payloadRaw['cmd'] ?? ''));
  }
  if (!is_string($payloadRaw) || trim($payloadRaw) === '') return '';
  $decoded = json_decode($payloadRaw, true);
  if (!is_array($decoded)) return '';
  return trim((string)($decoded['cmd'] ?? ''));
}

function vk_bot_selectable_territories(array $state): array {
  $rows = [];
  foreach (($state['kingdoms'] ?? []) as $id => $row) {
    if (!is_array($row)) continue;
    $rows[] = ['type' => 'kingdoms', 'id' => (string)$id, 'name' => trim((string)($row['name'] ?? $id))];
  }
  foreach (($state['special_territories'] ?? []) as $id => $row) {
    if (!is_array($row)) continue;
    $rows[] = ['type' => 'special_territories', 'id' => (string)$id, 'name' => trim((string)($row['name'] ?? $id))];
  }
  usort($rows, static fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
  return $rows;
}

function vk_bot_minor_house_layer_occupied_pid_map(array $state): array {
  $occupied = [];
  foreach (($state['great_houses'] ?? []) as $row) {
    if (!is_array($row)) continue;
    $layer = $row['minor_house_layer'] ?? null;
    if (!is_array($layer)) continue;
    foreach ((array)($layer['domain_pids'] ?? []) as $pid) {
      $p = (int)$pid;
      if ($p > 0) $occupied[$p] = true;
    }
    foreach ((array)($layer['vassals'] ?? []) as $vassal) {
      if (!is_array($vassal)) continue;
      foreach ((array)($vassal['province_pids'] ?? []) as $pid) {
        $p = (int)$pid;
        if ($p > 0) $occupied[$p] = true;
      }
    }
  }
  return $occupied;
}

function vk_bot_is_free_province(array $prov, string $territoryType, array $occupiedByMinorLayer): bool {
  $pid = (int)($prov['pid'] ?? 0);
  if ($pid > 0 && isset($occupiedByMinorLayer[$pid])) return false;

  $hasNestedController = trim((string)($prov['minor_house_id'] ?? '')) !== ''
    || trim((string)($prov['free_city_id'] ?? '')) !== ''
    || count((array)($prov['vassals'] ?? [])) > 0
    || trim((string)($prov['domain_of'] ?? '')) !== '';
  if ($hasNestedController) return false;

  // Для выбора в королевстве исключаем провинции спецтерриторий.
  if ($territoryType === 'kingdoms' && trim((string)($prov['special_territory_id'] ?? '')) !== '') return false;

  return true;
}

function vk_bot_free_provinces_for_territory(array $state, string $type, string $id): array {
  $field = $type === 'special_territories' ? 'special_territory_id' : 'kingdom_id';
  $occupiedByMinorLayer = vk_bot_minor_house_layer_occupied_pid_map($state);
  $rows = [];
  foreach (($state['provinces'] ?? []) as $pid => $prov) {
    if (!is_array($prov)) continue;
    if ((string)($prov[$field] ?? '') !== $id) continue;
    if (!vk_bot_is_free_province($prov, $type, $occupiedByMinorLayer)) continue;
    $rows[] = ['pid' => (int)$pid, 'name' => trim((string)($prov['name'] ?? ('Провинция ' . $pid)))];
  }
  usort($rows, static fn($a, $b) => ($a['pid'] <=> $b['pid']));
  return $rows;
}

function vk_bot_render_territory_free_map(array $state, string $territoryType, string $territoryId, array $freeProvinces): ?string {
  vk_bot_set_last_render_error(null);
  if (!function_exists('imagecreatetruecolor')) {
    vk_bot_set_last_render_error('gd_extension_missing');
    vk_bot_log_error('render_map_error: gd_extension_missing');
    return null;
  }
  $root = api_repo_root();
  $meta = vk_bot_load_json_file($root . '/provinces.json', []);
  $mask = @imagecreatefrompng($root . '/provinces_id.png');
  $baseMap = @imagecreatefrompng($root . '/map.png');
  if (!is_array($meta['provinces'] ?? null)) {
    vk_bot_set_last_render_error('provinces_meta_missing_or_invalid');
    vk_bot_log_error('render_map_error: provinces_meta_missing_or_invalid');
    return null;
  }
  if (!$mask) {
    vk_bot_set_last_render_error('provinces_id_png_unreadable');
    vk_bot_log_error('render_map_error: provinces_id_png_unreadable');
    return null;
  }
  if (!$baseMap) {
    vk_bot_set_last_render_error('map_png_unreadable');
    vk_bot_log_error('render_map_error: map_png_unreadable');
    imagedestroy($mask);
    return null;
  }

  $keyByPid = []; $centroidByPid = [];
  foreach ($meta['provinces'] as $row) {
    if (!is_array($row)) continue;
    $pid = (int)($row['pid'] ?? 0); $key = (int)($row['key'] ?? 0);
    if ($pid <= 0 || $key <= 0) continue;
    $keyByPid[$pid] = $key;
    $centroidByPid[$pid] = array_values((array)($row['centroid'] ?? [0,0]));
  }

  $allPids = [];
  $field = $territoryType === 'special_territories' ? 'special_territory_id' : 'kingdom_id';
  foreach (($state['provinces'] ?? []) as $pid => $prov) {
    if (!is_array($prov)) continue;
    if ((string)($prov[$field] ?? '') !== $territoryId) continue;
    $allPids[] = (int)$pid;
  }
  if (empty($allPids)) {
    vk_bot_set_last_render_error('no_territory_provinces_found');
    vk_bot_log_error('render_map_error: no_territory_provinces_found type=' . $territoryType . ' id=' . $territoryId);
    return null;
  }

  $maskW = imagesx($mask);
  $maskH = imagesy($mask);

  $freeMap = [];
  foreach (array_values($freeProvinces) as $idx => $row) $freeMap[(int)$row['pid']] = $idx + 1;

  $maskTrueColor = imageistruecolor($mask);
  $effectivePidByKey = [];
  $freeEffectivePids = [];
  foreach ($freeMap as $effectivePid => $_num) $freeEffectivePids[(int)$effectivePid] = true;

  foreach ($allPids as $pid) {
    $pid = (int)$pid;
    $key = (int)($keyByPid[$pid] ?? 0);
    if ($key <= 0) continue;
    if (!isset($effectivePidByKey[$key])) $effectivePidByKey[$key] = $pid;
  }

  // 1) Определяем точные границы территории по пикселям provinces_id.png
  // и одновременно считаем центры свободных провинций по пиксельным моментам.
  $minX = $maskW; $minY = $maskH; $maxX = -1; $maxY = -1;
  $freeEffectiveMomentsAbs = [];
  for ($y = 0; $y < $maskH; $y++) {
    for ($x = 0; $x < $maskW; $x++) {
      $idx = imagecolorat($mask, $x, $y);
      if ($maskTrueColor) {
        $r = ($idx >> 16) & 255; $g = ($idx >> 8) & 255; $b = $idx & 255;
      } else {
        $rgb = imagecolorsforindex($mask, $idx);
        $r = (int)($rgb['red'] ?? 0); $g = (int)($rgb['green'] ?? 0); $b = (int)($rgb['blue'] ?? 0);
      }
      $key = ($r << 16) | ($g << 8) | $b;
      $effectivePid = (int)($effectivePidByKey[$key] ?? 0);
      if ($effectivePid <= 0) continue;

      if ($x < $minX) $minX = $x;
      if ($y < $minY) $minY = $y;
      if ($x > $maxX) $maxX = $x;
      if ($y > $maxY) $maxY = $y;

      if (isset($freeEffectivePids[$effectivePid])) {
        if (!isset($freeEffectiveMomentsAbs[$effectivePid])) $freeEffectiveMomentsAbs[$effectivePid] = ['sx' => 0.0, 'sy' => 0.0, 'n' => 0];
        $freeEffectiveMomentsAbs[$effectivePid]['sx'] += $x;
        $freeEffectiveMomentsAbs[$effectivePid]['sy'] += $y;
        $freeEffectiveMomentsAbs[$effectivePid]['n'] += 1;
      }
    }
  }

  if ($maxX < $minX || $maxY < $minY) {
    vk_bot_set_last_render_error('invalid_mask_bounds_for_territory');
    vk_bot_log_error('render_map_error: invalid_mask_bounds_for_territory type=' . $territoryType . ' id=' . $territoryId);
    imagedestroy($mask);
    imagedestroy($baseMap);
    return null;
  }

  $pad = 20;
  $cropX = max(0, $minX - $pad); $cropY = max(0, $minY - $pad);
  $cropW = min($maskW - $cropX, ($maxX - $minX + 1) + 2 * $pad);
  $cropH = min($maskH - $cropY, ($maxY - $minY + 1) + 2 * $pad);

  $img = imagecreatetruecolor($cropW, $cropH);
  imagealphablending($img, true);
  imagesavealpha($img, true);
  imagecopy($img, $baseMap, 0, 0, $cropX, $cropY, $cropW, $cropH);

  // Свободные провинции — зелёные, занятые — красные (полупрозрачная заливка поверх map.png).
  $freeColor = imagecolorallocatealpha($img, 20, 176, 78, 52);
  $otherColor = imagecolorallocatealpha($img, 196, 34, 34, 52);
  $textColor = imagecolorallocate($img, 255, 255, 255);

  // 2) Рендерим заливку в пределах кропа по пикселям masks.
  for ($y = 0; $y < $cropH; $y++) {
    for ($x = 0; $x < $cropW; $x++) {
      $idx = imagecolorat($mask, $cropX + $x, $cropY + $y);
      if ($maskTrueColor) {
        $r = ($idx >> 16) & 255; $g = ($idx >> 8) & 255; $b = $idx & 255;
      } else {
        $rgb = imagecolorsforindex($mask, $idx);
        $r = (int)($rgb['red'] ?? 0); $g = (int)($rgb['green'] ?? 0); $b = (int)($rgb['blue'] ?? 0);
      }
      $key = ($r << 16) | ($g << 8) | $b;
      $effectivePid = (int)($effectivePidByKey[$key] ?? 0);
      if ($effectivePid <= 0) continue;
      imagesetpixel($img, $x, $y, isset($freeEffectivePids[$effectivePid]) ? $freeColor : $otherColor);
    }
  }

  // 3) Нумерация свободных провинций — центры по пикселям маски, не по bbox/hex.
  foreach ($freeMap as $effectivePid => $num) {
    $effectivePid = (int)$effectivePid;
    $m = $freeEffectiveMomentsAbs[$effectivePid] ?? null;
    if (is_array($m) && (int)($m['n'] ?? 0) > 0) {
      $cx = (int)round(((float)$m['sx']) / ((int)$m['n'])) - $cropX;
      $cy = (int)round(((float)$m['sy']) / ((int)$m['n'])) - $cropY;
    } else {
      // Fallback: centroid from provinces.json for the same PID.
      $c = $centroidByPid[$effectivePid] ?? null;
      if (!is_array($c) || count($c) < 2) continue;
      $cx = (int)round((float)$c[0]) - $cropX;
      $cy = (int)round((float)$c[1]) - $cropY;
    }
    imagestring($img, 5, max(0, $cx - 6), max(0, $cy - 8), (string)$num, $textColor);
  }

  $dir = $root . '/data/vk_bot/territory_images';
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    vk_bot_set_last_render_error('cannot_create_output_dir');
    vk_bot_log_error('render_map_error: cannot_create_output_dir path=' . $dir);
    return null;
  }
  $name = $territoryType . '_' . vk_bot_slug($territoryId) . '_' . date('Ymd_His') . '_' . random_int(1000, 9999) . '.png';
  $full = $dir . '/' . $name;
  if (!imagepng($img, $full)) {
    vk_bot_set_last_render_error('imagepng_failed');
    vk_bot_log_error('render_map_error: imagepng_failed path=' . $full);
    imagedestroy($img);
    imagedestroy($mask);
    imagedestroy($baseMap);
    return null;
  }
  imagedestroy($img);
  imagedestroy($mask);
  imagedestroy($baseMap);
  return '/data/vk_bot/territory_images/' . $name;
}

function vk_bot_render_single_province_map(int $pid): ?string {
  vk_bot_set_last_render_error(null);
  if (!function_exists('imagecreatetruecolor')) {
    vk_bot_set_last_render_error('gd_extension_missing');
    return null;
  }
  $root = api_repo_root();
  $meta = vk_bot_load_json_file($root . '/provinces.json', []);
  $mask = @imagecreatefrompng($root . '/provinces_id.png');
  $baseMap = @imagecreatefrompng($root . '/map.png');
  if (!$mask || !$baseMap || !is_array($meta['provinces'] ?? null)) {
    vk_bot_set_last_render_error('map_assets_missing');
    if ($mask) imagedestroy($mask);
    if ($baseMap) imagedestroy($baseMap);
    return null;
  }
  $targetKey = 0;
  foreach ($meta['provinces'] as $row) {
    if (!is_array($row)) continue;
    if ((int)($row['pid'] ?? 0) !== $pid) continue;
    $targetKey = (int)($row['key'] ?? 0);
    break;
  }
  if ($targetKey <= 0) {
    vk_bot_set_last_render_error('province_key_not_found');
    imagedestroy($mask);
    imagedestroy($baseMap);
    return null;
  }
  $maskW = imagesx($mask);
  $maskH = imagesy($mask);
  $maskTrueColor = imageistruecolor($mask);
  $minX = $maskW; $minY = $maskH; $maxX = -1; $maxY = -1;
  $sumX = 0.0; $sumY = 0.0; $count = 0;
  for ($y = 0; $y < $maskH; $y++) {
    for ($x = 0; $x < $maskW; $x++) {
      $idx = imagecolorat($mask, $x, $y);
      if ($maskTrueColor) {
        $r = ($idx >> 16) & 255; $g = ($idx >> 8) & 255; $b = $idx & 255;
      } else {
        $rgb = imagecolorsforindex($mask, $idx);
        $r = (int)($rgb['red'] ?? 0); $g = (int)($rgb['green'] ?? 0); $b = (int)($rgb['blue'] ?? 0);
      }
      $key = ($r << 16) | ($g << 8) | $b;
      if ($key !== $targetKey) continue;
      if ($x < $minX) $minX = $x;
      if ($y < $minY) $minY = $y;
      if ($x > $maxX) $maxX = $x;
      if ($y > $maxY) $maxY = $y;
      $sumX += $x;
      $sumY += $y;
      $count++;
    }
  }
  if ($maxX < $minX || $maxY < $minY) {
    vk_bot_set_last_render_error('province_pixels_not_found');
    imagedestroy($mask);
    imagedestroy($baseMap);
    return null;
  }
  $cx = ($count > 0) ? (int)round($sumX / $count) : (int)round(($minX + $maxX) / 2);
  $cy = ($count > 0) ? (int)round($sumY / $count) : (int)round(($minY + $maxY) / 2);
  // ~1/16 карты по площади: четверть по ширине и высоте.
  $cropW = max(320, (int)round($maskW / 4));
  $cropH = max(180, (int)round($maskH / 4));
  $cropX = max(0, min($maskW - $cropW, $cx - (int)floor($cropW / 2)));
  $cropY = max(0, min($maskH - $cropH, $cy - (int)floor($cropH / 2)));
  $img = imagecreatetruecolor($cropW, $cropH);
  imagealphablending($img, true);
  imagesavealpha($img, true);
  imagecopy($img, $baseMap, 0, 0, $cropX, $cropY, $cropW, $cropH);
  $overlayColor = imagecolorallocatealpha($img, 210, 36, 36, 36);
  for ($y = 0; $y < $cropH; $y++) {
    for ($x = 0; $x < $cropW; $x++) {
      $idx = imagecolorat($mask, $cropX + $x, $cropY + $y);
      if ($maskTrueColor) {
        $r = ($idx >> 16) & 255; $g = ($idx >> 8) & 255; $b = $idx & 255;
      } else {
        $rgb = imagecolorsforindex($mask, $idx);
        $r = (int)($rgb['red'] ?? 0); $g = (int)($rgb['green'] ?? 0); $b = (int)($rgb['blue'] ?? 0);
      }
      $key = ($r << 16) | ($g << 8) | $b;
      if ($key !== $targetKey) continue;
      imagesetpixel($img, $x, $y, $overlayColor);
    }
  }
  $dir = $root . '/data/vk_bot/province_images';
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    vk_bot_set_last_render_error('cannot_create_output_dir');
    imagedestroy($img); imagedestroy($mask); imagedestroy($baseMap);
    return null;
  }
  $name = 'province_' . sprintf('%04d', $pid) . '_' . date('Ymd_His') . '_' . random_int(1000, 9999) . '.png';
  $full = $dir . '/' . $name;
  $ok = imagepng($img, $full);
  imagedestroy($img); imagedestroy($mask); imagedestroy($baseMap);
  if (!$ok) {
    vk_bot_set_last_render_error('imagepng_failed');
    return null;
  }
  return '/data/vk_bot/province_images/' . $name;
}

function vk_bot_render_pid_reference_map(): ?string {
  vk_bot_set_last_render_error(null);
  if (!function_exists('imagecreatetruecolor')) {
    vk_bot_set_last_render_error('gd_extension_missing');
    return null;
  }
  $root = api_repo_root();
  $meta = vk_bot_load_json_file($root . '/provinces.json', []);
  $baseMap = @imagecreatefrompng($root . '/map.png');
  if (!$baseMap || !is_array($meta['provinces'] ?? null)) {
    vk_bot_set_last_render_error('map_assets_missing');
    if ($baseMap) imagedestroy($baseMap);
    return null;
  }
  imagealphablending($baseMap, true);
  imagesavealpha($baseMap, true);

  $mapW = imagesx($baseMap);
  $mapH = imagesy($baseMap);
  $metaW = 0.0;
  $metaH = 0.0;
  foreach ($meta['provinces'] as $row) {
    if (!is_array($row)) continue;
    $bbox = is_array($row['bbox'] ?? null) ? $row['bbox'] : null;
    if (is_array($bbox) && count($bbox) >= 4) {
      $metaW = max($metaW, (float)$bbox[2] + 1.0);
      $metaH = max($metaH, (float)$bbox[3] + 1.0);
    }
    $centroid = is_array($row['centroid'] ?? null) ? $row['centroid'] : null;
    if (is_array($centroid) && count($centroid) >= 2) {
      $metaW = max($metaW, (float)$centroid[0] + 1.0);
      $metaH = max($metaH, (float)$centroid[1] + 1.0);
    }
  }
  $scaleX = ($metaW > 0.0) ? ((float)$mapW / $metaW) : 1.0;
  $scaleY = ($metaH > 0.0) ? ((float)$mapH / $metaH) : 1.0;
  if (!is_finite($scaleX) || $scaleX <= 0.0) $scaleX = 1.0;
  if (!is_finite($scaleY) || $scaleY <= 0.0) $scaleY = 1.0;

  $textColor = imagecolorallocate($baseMap, 255, 255, 255);
  $shadowColor = imagecolorallocatealpha($baseMap, 0, 0, 0, 45);
  foreach ($meta['provinces'] as $row) {
    if (!is_array($row)) continue;
    $pid = (int)($row['pid'] ?? 0);
    $centroid = is_array($row['centroid'] ?? null) ? $row['centroid'] : null;
    if ($pid <= 0 || !is_array($centroid) || count($centroid) < 2) continue;
    $x = (int)round((float)$centroid[0] * $scaleX);
    $y = (int)round((float)$centroid[1] * $scaleY);
    if ($x < 0 || $y < 0 || $x >= $mapW || $y >= $mapH) continue;
    $label = (string)$pid;
    $labelWidth = imagefontwidth(2) * strlen($label);
    $tx = $x - (int)floor($labelWidth / 2);
    $ty = $y - 7;
    imagefilledrectangle($baseMap, $tx - 2, $ty - 1, $tx + $labelWidth + 2, $ty + 13, $shadowColor);
    imagestring($baseMap, 2, $tx, $ty, $label, $textColor);
  }

  $dir = $root . '/data/vk_bot';
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    vk_bot_set_last_render_error('cannot_create_output_dir');
    imagedestroy($baseMap);
    return null;
  }
  $full = $dir . '/pid_map.png';
  $ok = imagepng($baseMap, $full);
  imagedestroy($baseMap);
  if (!$ok) {
    vk_bot_set_last_render_error('imagepng_failed');
    return null;
  }
  return '/data/vk_bot/pid_map.png';
}

function vk_bot_render_single_province_layer_map(array $state, int $pid, string $mode): ?string {
  if (!in_array($mode, ['kingdoms', 'minor_houses'], true)) return null;
  if (!function_exists('imagecreatetruecolor')) return null;
  $root = api_repo_root();
  $meta = vk_bot_load_json_file($root . '/provinces.json', []);
  $mask = @imagecreatefrompng($root . '/provinces_id.png');
  $baseMap = @imagecreatefrompng($root . '/map.png');
  if (!$mask || !$baseMap || !is_array($meta['provinces'] ?? null)) {
    if ($mask) imagedestroy($mask);
    if ($baseMap) imagedestroy($baseMap);
    return null;
  }
  $targetKey = 0;
  $keyByPid = [];
  $centroidByPid = [];
  foreach ($meta['provinces'] as $row) {
    if (!is_array($row)) continue;
    $p = (int)($row['pid'] ?? 0);
    $k = (int)($row['key'] ?? 0);
    if ($p > 0 && $k > 0) $keyByPid[$p] = $k;
    if ($p > 0 && is_array($row['centroid'] ?? null)) $centroidByPid[$p] = [(float)$row['centroid'][0], (float)$row['centroid'][1]];
    if ($p === $pid) $targetKey = $k;
  }
  if ($targetKey <= 0) { imagedestroy($mask); imagedestroy($baseMap); return null; }

  $maskW = imagesx($mask); $maskH = imagesy($mask); $maskTrueColor = imageistruecolor($mask);
  $minX = $maskW; $minY = $maskH; $maxX = -1; $maxY = -1; $sumX = 0.0; $sumY = 0.0; $count = 0;
  for ($y = 0; $y < $maskH; $y++) {
    for ($x = 0; $x < $maskW; $x++) {
      $idx = imagecolorat($mask, $x, $y);
      if ($maskTrueColor) { $r = ($idx >> 16) & 255; $g = ($idx >> 8) & 255; $b = $idx & 255; }
      else { $rgb = imagecolorsforindex($mask, $idx); $r = (int)($rgb['red'] ?? 0); $g = (int)($rgb['green'] ?? 0); $b = (int)($rgb['blue'] ?? 0); }
      $key = ($r << 16) | ($g << 8) | $b;
      if ($key !== $targetKey) continue;
      if ($x < $minX) $minX = $x; if ($y < $minY) $minY = $y; if ($x > $maxX) $maxX = $x; if ($y > $maxY) $maxY = $y;
      $sumX += $x; $sumY += $y; $count++;
    }
  }
  if ($count <= 0) { imagedestroy($mask); imagedestroy($baseMap); return null; }
  $cx = (int)round($sumX / $count); $cy = (int)round($sumY / $count);
  $cropW = max(320, (int)round($maskW / 4)); $cropH = max(180, (int)round($maskH / 4));
  $cropX = max(0, min($maskW - $cropW, $cx - (int)floor($cropW / 2)));
  $cropY = max(0, min($maskH - $cropH, $cy - (int)floor($cropH / 2)));

  $img = imagecreatetruecolor($cropW, $cropH);
  imagealphablending($img, true); imagesavealpha($img, true);
  imagecopy($img, $baseMap, 0, 0, $cropX, $cropY, $cropW, $cropH);

  $realmColorByKey = [];
  foreach (($state['provinces'] ?? []) as $provPid => $prov) {
    if (!is_array($prov)) continue;
    $provPidNum = (int)($prov['pid'] ?? $provPid);
    $provKey = (int)($keyByPid[$provPidNum] ?? 0);
    if ($provKey <= 0) continue;
    if ($mode === 'kingdoms') {
      $rid = trim((string)($prov['kingdom_id'] ?? ''));
      $realm = $rid !== '' ? (($state['kingdoms'][$rid] ?? null)) : null;
      $hex = is_array($realm) ? trim((string)($realm['color'] ?? '')) : '';
      if ($hex !== '' && preg_match('/^#?([0-9a-fA-F]{6})$/', $hex, $m)) {
        $h = $m[1];
        $realmColorByKey[$provKey] = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
      }
    } else {
      $rid = trim((string)($prov['minor_house_id'] ?? ''));
      if ($rid === '') continue;
      $hash = substr(hash('sha1', $rid), 0, 6);
      $realmColorByKey[$provKey] = [hexdec(substr($hash, 0, 2)), hexdec(substr($hash, 2, 2)), hexdec(substr($hash, 4, 2))];
    }
  }
  $fallback = imagecolorallocatealpha($img, 80, 92, 110, 70);
  $cache = [];
  for ($y = 0; $y < $cropH; $y++) {
    for ($x = 0; $x < $cropW; $x++) {
      $idx = imagecolorat($mask, $cropX + $x, $cropY + $y);
      if ($maskTrueColor) { $r = ($idx >> 16) & 255; $g = ($idx >> 8) & 255; $b = $idx & 255; }
      else { $rgb = imagecolorsforindex($mask, $idx); $r = (int)($rgb['red'] ?? 0); $g = (int)($rgb['green'] ?? 0); $b = (int)($rgb['blue'] ?? 0); }
      $key = ($r << 16) | ($g << 8) | $b;
      if ($key <= 0) continue;
      if ($key === $targetKey) continue;
      if (!isset($cache[$key])) {
        $rgb = $realmColorByKey[$key] ?? null;
        $cache[$key] = is_array($rgb) ? imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], 55) : $fallback;
      }
      imagesetpixel($img, $x, $y, $cache[$key]);
    }
  }
  $targetOverlay = imagecolorallocatealpha($img, 210, 36, 36, 34);
  for ($y = 0; $y < $cropH; $y++) for ($x = 0; $x < $cropW; $x++) {
    $idx = imagecolorat($mask, $cropX + $x, $cropY + $y);
    if ($maskTrueColor) { $r = ($idx >> 16) & 255; $g = ($idx >> 8) & 255; $b = $idx & 255; }
    else { $rgb = imagecolorsforindex($mask, $idx); $r = (int)($rgb['red'] ?? 0); $g = (int)($rgb['green'] ?? 0); $b = (int)($rgb['blue'] ?? 0); }
    if ((($r << 16) | ($g << 8) | $b) === $targetKey) imagesetpixel($img, $x, $y, $targetOverlay);
  }

  // Маркеры гербов провинций.
  $provMarker = imagecolorallocate($img, 255, 225, 120);
  foreach (($state['provinces'] ?? []) as $provPid => $prov) {
    if (!is_array($prov)) continue;
    if (trim((string)($prov['emblem_svg'] ?? '')) === '' && trim((string)($prov['emblem_asset_id'] ?? '')) === '') continue;
    $p = (int)($prov['pid'] ?? $provPid);
    $c = $centroidByPid[$p] ?? null;
    if (!is_array($c) || count($c) < 2) continue;
    $x = (int)round((float)$c[0]) - $cropX; $y = (int)round((float)$c[1]) - $cropY;
    if ($x < 0 || $y < 0 || $x >= $cropW || $y >= $cropH) continue;
    imagefilledellipse($img, $x, $y, 6, 6, $provMarker);
  }
  // Маркеры гербов королевств / малых домов.
  $realmMarker = imagecolorallocate($img, 240, 245, 255);
  if ($mode === 'kingdoms') {
    foreach (($state['kingdoms'] ?? []) as $realm) {
      if (!is_array($realm)) continue;
      if (trim((string)($realm['emblem_svg'] ?? '')) === '' && trim((string)($realm['emblem_asset_id'] ?? '')) === '') continue;
      $cap = (int)($realm['capital_pid'] ?? 0);
      $c = $centroidByPid[$cap] ?? null;
      if (!is_array($c) || count($c) < 2) continue;
      $x = (int)round((float)$c[0]) - $cropX; $y = (int)round((float)$c[1]) - $cropY;
      if ($x < 0 || $y < 0 || $x >= $cropW || $y >= $cropH) continue;
      imagefilledellipse($img, $x, $y, 10, 10, $realmMarker);
      imagestring($img, 2, $x - 3, $y - 4, 'K', imagecolorallocate($img, 20, 30, 40));
    }
  } else {
    foreach (($state['minor_houses'] ?? []) as $realm) {
      if (!is_array($realm)) continue;
      $cap = (int)($realm['capital_pid'] ?? 0);
      $c = $centroidByPid[$cap] ?? null;
      if (!is_array($c) || count($c) < 2) continue;
      $x = (int)round((float)$c[0]) - $cropX; $y = (int)round((float)$c[1]) - $cropY;
      if ($x < 0 || $y < 0 || $x >= $cropW || $y >= $cropH) continue;
      imagefilledellipse($img, $x, $y, 10, 10, $realmMarker);
      imagestring($img, 2, $x - 3, $y - 4, 'M', imagecolorallocate($img, 20, 30, 40));
    }
  }

  $dir = $root . '/data/vk_bot/province_images';
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { imagedestroy($img); imagedestroy($mask); imagedestroy($baseMap); return null; }
  $name = $mode . '_province_' . sprintf('%04d', $pid) . '_' . date('Ymd_His') . '_' . random_int(1000, 9999) . '.png';
  $full = $dir . '/' . $name;
  $ok = imagepng($img, $full);
  imagedestroy($img); imagedestroy($mask); imagedestroy($baseMap);
  if (!$ok) return null;
  return '/data/vk_bot/province_images/' . $name;
}

function vk_bot_create_admin_confirm_code(int $vkUserId): string {
  $store = vk_bot_load_admin_mode_store();
  $code = '';
  for ($i = 0; $i < 8; $i++) {
    $candidate = (string)random_int(100000, 999999);
    if (!isset($store['pending_codes'][$candidate])) { $code = $candidate; break; }
  }
  if ($code === '') $code = (string)random_int(100000, 999999);
  $store['pending_codes'][$code] = [
    'vk_user_id' => $vkUserId,
    'created_at' => time(),
    'expires_at' => time() + 1800,
  ];
  vk_bot_save_admin_mode_store($store);
  return $code;
}

function vk_bot_mark_admin_confirmed_by_code(string $code): ?int {
  $store = vk_bot_load_admin_mode_store();
  $row = $store['pending_codes'][$code] ?? null;
  if (!is_array($row)) return null;
  $exp = (int)($row['expires_at'] ?? 0);
  if ($exp > 0 && $exp < time()) {
    unset($store['pending_codes'][$code]);
    vk_bot_save_admin_mode_store($store);
    return null;
  }
  $vkUserId = (int)($row['vk_user_id'] ?? 0);
  if ($vkUserId <= 0) return null;
  $store['confirmations'][(string)$vkUserId] = ['confirmed' => true, 'confirmed_at' => time()];
  unset($store['pending_codes'][$code]);
  vk_bot_save_admin_mode_store($store);
  return $vkUserId;
}

function vk_bot_is_admin_confirmed(int $vkUserId): bool {
  $store = vk_bot_load_admin_mode_store();
  return (bool)($store['confirmations'][(string)$vkUserId]['confirmed'] ?? false);
}

function vk_bot_admin_mode_submit_request(int $vkUserId): array {
  $store = vk_bot_load_admin_mode_store();
  if ((bool)($store['confirmations'][(string)$vkUserId]['confirmed'] ?? false)) {
    return ['ok' => true, 'already_confirmed' => true];
  }
  foreach ($store['requests'] as $row) {
    if (!is_array($row)) continue;
    if ((int)($row['vk_user_id'] ?? 0) !== $vkUserId) continue;
    if ((string)($row['status'] ?? '') !== 'pending') continue;
    return ['ok' => true, 'already_pending' => true, 'request_id' => (string)($row['id'] ?? '')];
  }
  $id = 'adm_req_' . date('Ymd_His') . '_' . $vkUserId . '_' . random_int(100, 999);
  $store['requests'][] = [
    'id' => $id,
    'vk_user_id' => $vkUserId,
    'status' => 'pending',
    'created_at' => time(),
    'updated_at' => time(),
    'approved_by' => '',
  ];
  vk_bot_save_admin_mode_store($store);
  return ['ok' => true, 'request_id' => $id];
}

function vk_bot_admin_mode_set_request_status(string $id, string $status, string $approvedBy = ''): bool {
  $store = vk_bot_load_admin_mode_store();
  $changed = false;
  foreach ($store['requests'] as $i => $row) {
    if (!is_array($row)) continue;
    if ((string)($row['id'] ?? '') !== $id) continue;
    $vkUserId = (int)($row['vk_user_id'] ?? 0);
    $store['requests'][$i]['status'] = $status;
    $store['requests'][$i]['updated_at'] = time();
    $store['requests'][$i]['approved_by'] = $approvedBy;
    if ($status === 'approved' && $vkUserId > 0) {
      $store['confirmations'][(string)$vkUserId] = ['confirmed' => true, 'confirmed_at' => time(), 'approved_by' => $approvedBy];
    }
    if ($status === 'rejected' && $vkUserId > 0) {
      unset($store['confirmations'][(string)$vkUserId]);
    }
    $changed = true;
    break;
  }
  if (!$changed) return false;
  return vk_bot_save_admin_mode_store($store);
}

function vk_bot_download_image_raw_by_message(array $message): ?array {
  $vkAtt = '';
  $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
  foreach ($attachments as $a) {
    if (!is_array($a) || ($a['type'] ?? '') !== 'photo' || !is_array($a['photo'] ?? null)) continue;
    $owner = (string)($a['photo']['owner_id'] ?? '');
    $pid = (string)($a['photo']['id'] ?? '');
    $accessKey = trim((string)($a['photo']['access_key'] ?? ''));
    if ($owner !== '' && $pid !== '') {
      $vkAtt = 'photo' . $owner . '_' . $pid . ($accessKey !== '' ? ('_' . $accessKey) : '');
      break;
    }
  }
  if ($vkAtt !== '') {
    $fetched = vk_bot_download_vk_attachment_image($vkAtt);
    if ((bool)($fetched['ok'] ?? false)) {
      return [
        'raw' => (string)($fetched['raw'] ?? ''),
        'content_type' => (string)($fetched['content_type'] ?? 'image/jpeg'),
      ];
    }
  }
  $url = '';
  foreach ($attachments as $a) {
    if (!is_array($a)) continue;
    if (($a['type'] ?? '') === 'photo' && is_array($a['photo'] ?? null)) {
      $sizes = is_array($a['photo']['sizes'] ?? null) ? $a['photo']['sizes'] : [];
      $bestUrl = '';
      $bestArea = -1;
      foreach ($sizes as $size) {
        if (!is_array($size)) continue;
        $candidate = trim((string)($size['url'] ?? ''));
        if ($candidate === '') continue;
        $area = ((int)($size['width'] ?? 0)) * ((int)($size['height'] ?? 0));
        if ($area > $bestArea) { $bestArea = $area; $bestUrl = $candidate; }
      }
      if ($bestUrl !== '') { $url = $bestUrl; break; }
    }
    if (($a['type'] ?? '') === 'doc' && is_array($a['doc'] ?? null)) {
      $ext = mb_strtolower(trim((string)($a['doc']['ext'] ?? '')));
      if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        $candidate = trim((string)($a['doc']['url'] ?? ''));
        if ($candidate !== '') { $url = $candidate; break; }
      }
    }
  }
  if ($url === '' || !preg_match('#^https?://#i', $url)) return null;
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);
  if (!is_string($raw) || $raw === '' || $err !== '' || $code >= 400) return null;
  return ['raw' => $raw, 'content_type' => $ctype];
}

function vk_bot_build_and_save_province_card_image(array $state, int $pid, string $baseRaw): ?string {
  if (!function_exists('imagecreatetruecolor')) return null;
  $srcImg = @imagecreatefromstring($baseRaw);
  if (!$srcImg) return null;
  $w = 1280; $h = 720;
  $canvas = imagecreatetruecolor($w, $h);
  imagecopyresampled($canvas, $srcImg, 0, 0, 0, 0, $w, $h, imagesx($srcImg), imagesy($srcImg));
  imagedestroy($srcImg);

  $mask = @imagecreatefrompng(api_repo_root() . '/provinces_id.png');
  $meta = vk_bot_load_json_file(api_repo_root() . '/provinces.json', []);
  if ($mask && is_array($meta['provinces'] ?? null)) {
    $targetKey = 0;
    foreach ($meta['provinces'] as $row) {
      if (!is_array($row)) continue;
      if ((int)($row['pid'] ?? 0) !== $pid) continue;
      $targetKey = (int)($row['key'] ?? 0);
      break;
    }
    if ($targetKey > 0) {
      $mw = imagesx($mask); $mh = imagesy($mask); $maskTrueColor = imageistruecolor($mask);
      $minX = $mw; $minY = $mh; $maxX = -1; $maxY = -1;
      for ($y = 0; $y < $mh; $y++) {
        for ($x = 0; $x < $mw; $x++) {
          $idx = imagecolorat($mask, $x, $y);
          if ($maskTrueColor) { $r = ($idx >> 16) & 255; $g = ($idx >> 8) & 255; $b = $idx & 255; }
          else { $rgb = imagecolorsforindex($mask, $idx); $r = (int)($rgb['red'] ?? 0); $g = (int)($rgb['green'] ?? 0); $b = (int)($rgb['blue'] ?? 0); }
          if ((($r << 16) | ($g << 8) | $b) !== $targetKey) continue;
          if ($x < $minX) $minX = $x; if ($y < $minY) $minY = $y; if ($x > $maxX) $maxX = $x; if ($y > $maxY) $maxY = $y;
        }
      }
      if ($maxX >= $minX && $maxY >= $minY) {
        $boxSize = (int)round(min($w, $h) * 0.34);
        $margin = (int)round(min($w, $h) * 0.04);
        $boxX = $w - $boxSize - $margin;
        $boxY = $h - $boxSize - $margin;
        $bg = imagecolorallocatealpha($canvas, 8, 12, 17, 26);
        imagefilledrectangle($canvas, $boxX, $boxY, $boxX + $boxSize, $boxY + $boxSize, $bg);
        $fill = imagecolorallocatealpha($canvas, 175, 36, 36, 30);
        $scaleX = ($boxSize * 0.84) / max(1, ($maxX - $minX + 1));
        $scaleY = ($boxSize * 0.84) / max(1, ($maxY - $minY + 1));
        $scale = min($scaleX, $scaleY);
        $ox = $boxX + ($boxSize - ($maxX - $minX + 1) * $scale) * 0.5;
        $oy = $boxY + ($boxSize - ($maxY - $minY + 1) * $scale) * 0.5;
        for ($y = $minY; $y <= $maxY; $y++) {
          for ($x = $minX; $x <= $maxX; $x++) {
            $idx = imagecolorat($mask, $x, $y);
            if ($maskTrueColor) { $r = ($idx >> 16) & 255; $g = ($idx >> 8) & 255; $b = $idx & 255; }
            else { $rgb = imagecolorsforindex($mask, $idx); $r = (int)($rgb['red'] ?? 0); $g = (int)($rgb['green'] ?? 0); $b = (int)($rgb['blue'] ?? 0); }
            if ((($r << 16) | ($g << 8) | $b) !== $targetKey) continue;
            $dx = (int)floor($ox + ($x - $minX) * $scale);
            $dy = (int)floor($oy + ($y - $minY) * $scale);
            imagefilledrectangle($canvas, $dx, $dy, (int)ceil($dx + $scale), (int)ceil($dy + $scale), $fill);
          }
        }
      }
    }
    imagedestroy($mask);
  }
  $name = sprintf('province_%04d.jpg', $pid);
  $path = api_repo_root() . '/provinces/' . $name;
  $ok = imagejpeg($canvas, $path, 82);
  imagedestroy($canvas);
  if (!$ok) return null;
  return 'provinces/' . $name;
}


function vk_bot_genealogy_admin_tokens_path(): string { return api_repo_root() . '/data/genealogy_admin_tokens.json'; }

function vk_bot_load_genealogy_admin_tokens(): array {
  $rows = vk_bot_load_json_file(vk_bot_genealogy_admin_tokens_path(), []);
  return is_array($rows) ? $rows : [];
}

function vk_bot_save_genealogy_admin_tokens(array $rows): bool {
  return api_atomic_write_json(vk_bot_genealogy_admin_tokens_path(), $rows);
}

function vk_bot_create_genealogy_admin_token(string $clan, string $entityType, string $entityId, ?string $previousToken = null): ?array {
  $tokens = vk_bot_load_genealogy_admin_tokens();
  if ($previousToken !== null && $previousToken !== '') unset($tokens[$previousToken]);
  $token = player_admin_generate_token();
  $now = time();
  $tokens[$token] = [
    'clan' => trim($clan),
    'entity_type' => trim($entityType),
    'entity_id' => trim($entityId),
    'created_at' => $now,
    'expires_at' => $now + player_admin_token_ttl_seconds(),
  ];
  if (!vk_bot_save_genealogy_admin_tokens($tokens)) return null;
  return ['token' => $token, 'path' => '/genealogy_admin.html?token=' . rawurlencode($token)];
}

function vk_bot_resolve_genealogy_admin_token(string $token): ?array {
  $token = trim($token);
  if ($token === '') return null;
  $tokens = vk_bot_load_genealogy_admin_tokens();
  $row = $tokens[$token] ?? null;
  if (!is_array($row)) return null;
  $exp = (int)($row['expires_at'] ?? 0);
  if ($exp > 0 && $exp < time()) {
    unset($tokens[$token]);
    vk_bot_save_genealogy_admin_tokens($tokens);
    return null;
  }
  return $row;
}

function vk_bot_genealogy_admin_token_from_request(): string {
  $headerToken = trim((string)($_SERVER['HTTP_X_GENEALOGY_ADMIN_TOKEN'] ?? ''));
  if ($headerToken !== '') return $headerToken;
  return trim((string)($_GET['token'] ?? ''));
}

function vk_bot_create_player_admin_token(string $entityType, string $entityId, ?string $previousToken = null): ?array {
  $tokens = player_admin_prune_tokens(player_admin_load_tokens());
  if ($previousToken !== null && $previousToken !== '') unset($tokens[$previousToken]);
  $token = player_admin_generate_token();
  $now = time();
  $tokens[$token] = [
    'entity_type' => $entityType,
    'entity_id' => $entityId,
    'created_at' => $now,
    'expires_at' => $now + player_admin_token_ttl_seconds(),
  ];
  if (!player_admin_save_tokens($tokens)) return null;
  return ['token' => $token, 'path' => '/player_admin.html?token=' . rawurlencode($token)];
}

function vk_bot_resolve_application_entity(array $app): ?array {
  $pairs = [
    ['approved_entity_type', 'approved_entity_id'],
    ['entity_type', 'entity_id'],
    ['selected_entity_type', 'selected_entity_id'],
  ];
  foreach ($pairs as $pair) {
    $type = trim((string)($app[$pair[0]] ?? ''));
    $id = trim((string)($app[$pair[1]] ?? ''));
    if ($type !== '' && $id !== '') return ['entity_type' => $type, 'entity_id' => $id];
  }
  return null;
}

function vk_bot_resolve_user_entity_for_orders(array $apps, int $vkUserId): ?array {
  foreach ($apps as $app) {
    if (!is_array($app)) continue;
    if ((int)($app['vk_user_id'] ?? 0) !== $vkUserId) continue;
    if ((string)($app['status'] ?? '') !== 'approved') continue;
    $resolved = vk_bot_resolve_application_entity($app);
    if (is_array($resolved)) return $resolved;
  }
  return null;
}
