<?php

declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/lib/state_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, api_state_mtime());
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : null;
if (!is_array($payload)) api_json_response(['error' => 'invalid_json'], 400, api_state_mtime());

$pid = (int)($payload['pid'] ?? 0);
if ($pid <= 0) api_json_response(['error' => 'invalid_pid'], 400, api_state_mtime());

$src = isset($payload['image_data_url']) ? (string)$payload['image_data_url'] : '';
if ($src === '') api_json_response(['error' => 'image_data_url_required'], 400, api_state_mtime());

if (!preg_match('#^data:image/(png|webp|jpeg|jpg);base64,(.+)$#i', $src, $m)) {
  api_json_response(['error' => 'invalid_image_data_url'], 400, api_state_mtime());
}

$fmt = strtolower((string)$m[1]);
$ext = ($fmt === 'jpeg' || $fmt === 'jpg') ? 'jpg' : $fmt;
$bytes = base64_decode((string)$m[2], true);
if ($bytes === false || $bytes === '') api_json_response(['error' => 'invalid_image_data_url'], 400, api_state_mtime());

$provincesDir = dirname(__DIR__, 4) . '/provinces';
if (!is_dir($provincesDir) && !mkdir($provincesDir, 0775, true) && !is_dir($provincesDir)) {
  api_json_response(['error' => 'mkdir_failed'], 500, api_state_mtime());
}

$name = sprintf('province_%04d.%s', $pid, $ext);
$path = $provincesDir . '/' . $name;
$tmp = $path . '.tmp';

if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $path)) {
  @unlink($tmp);
  api_json_response(['error' => 'write_failed'], 500, api_state_mtime());
}

api_json_response(['ok' => true, 'pid' => $pid, 'path' => 'provinces/' . $name], 200, api_state_mtime());
