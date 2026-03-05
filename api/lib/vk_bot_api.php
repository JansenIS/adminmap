<?php

declare(strict_types=1);

require_once __DIR__ . '/state_api.php';
require_once __DIR__ . '/player_admin_api.php';

function vk_bot_config_path(): string { return api_repo_root() . '/data/vk_bot_config.json'; }
function vk_bot_sessions_path(): string { return api_repo_root() . '/data/vk_bot_sessions.json'; }
function vk_bot_applications_path(): string { return api_repo_root() . '/data/vk_bot_applications.json'; }

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
  ]);
}

function vk_bot_load_sessions(): array { return vk_bot_load_json_file(vk_bot_sessions_path(), []); }
function vk_bot_save_sessions(array $rows): bool { return api_atomic_write_json(vk_bot_sessions_path(), $rows); }
function vk_bot_load_applications(): array { return vk_bot_load_json_file(vk_bot_applications_path(), []); }
function vk_bot_save_applications(array $rows): bool { return api_atomic_write_json(vk_bot_applications_path(), $rows); }

function vk_bot_log_error(string $message): void {
  @file_put_contents(api_repo_root() . '/data/vk_bot_last_error.log', date('c') . ' ' . $message . "\n", FILE_APPEND);
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
  return [[vk_bot_btn_item($label, $cmd, $color)]];
}

function vk_bot_send_message(int $userId, string $message, ?string $keyboardJson = null): void {
  $cfg = vk_bot_load_config();
  if ($cfg['access_token'] === '') return;
  $params = [
    'user_id' => $userId,
    'random_id' => random_int(1, PHP_INT_MAX),
    'message' => $message,
    'v' => $cfg['api_version'] !== '' ? $cfg['api_version'] : '5.199',
    'access_token' => $cfg['access_token'],
  ];
  if ($keyboardJson !== null && $keyboardJson !== '') $params['keyboard'] = $keyboardJson;

  $ch = curl_init('https://api.vk.com/method/messages.send');
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 8);
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);

  if ($resp === false || $err !== '') {
    @file_put_contents(api_repo_root() . '/data/vk_bot_last_error.log', date('c') . " send_error: " . $err . "\n", FILE_APPEND);
    return;
  }

  $decoded = json_decode((string)$resp, true);
  if (is_array($decoded) && isset($decoded['error'])) {
    @file_put_contents(api_repo_root() . '/data/vk_bot_last_error.log', date('c') . " api_error: " . json_encode($decoded['error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
  }
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
