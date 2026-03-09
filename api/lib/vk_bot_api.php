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

function vk_bot_upload_message_photo_blob(int $userId, string $raw, string $fileName = 'generated.png'): string {
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


function vk_bot_download_vk_attachment_image(string $vkAttachment): array {
  $att = trim($vkAttachment);
  if ($att === '') return ['ok' => false, 'error' => 'empty_attachment'];
  if (str_starts_with($att, 'photo')) $att = substr($att, 5);
  $parts = explode('_', $att);
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
        return vk_bot_upload_wall_photo_blob((string)$fetched['raw'], (string)($fetched['file_name'] ?? 'vk_attachment.jpg'), (string)($fetched['content_type'] ?? ''));
      }
      vk_bot_log_error('vk_wall_attachment_fetch_failed ' . json_encode($fetched, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      return '';
    }
    $value = (string)($value['url'] ?? $value['src'] ?? $value['href'] ?? '');
  }
  $url = trim((string)$value);
  if ($url === '') return '';

  if (str_starts_with($url, '/')) {
    $path = api_repo_root() . $url;
  } elseif (preg_match('~^https?://~i', $url)) {
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
    if (!is_string($raw) || $raw === '' || $err !== '' || $code < 200 || $code >= 300) return '';
    $ext = '';
    $path = parse_url($url, PHP_URL_PATH);
    if (is_string($path)) $ext = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $name = 'order_remote' . ($ext !== '' ? ('.' . $ext) : '.jpg');
    return vk_bot_upload_wall_photo_blob($raw, $name, $ctype);
  } else {
    return '';
  }

  if (!is_file($path)) return '';
  $raw = @file_get_contents($path);
  if (!is_string($raw) || $raw === '') return '';
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
