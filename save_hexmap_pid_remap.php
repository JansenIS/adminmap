<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
  http_response_code(400);
  echo 'Empty body';
  exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo 'Invalid JSON';
  exit;
}

$TOKEN = ''; // optional shared token
$token = isset($data['token']) ? (string)$data['token'] : '';
if ($TOKEN !== '' && $token !== $TOKEN) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

$map = $data['pid_remap'] ?? null;
if (!is_array($map)) {
  http_response_code(400);
  echo 'Missing pid_remap';
  exit;
}

$clean = [];
foreach ($map as $src => $dst) {
  $s = (int)$src;
  $d = (int)$dst;
  if ($s > 0 && $d > 0 && $s !== $d) {
    $clean[(string)$s] = $d;
  }
}

$dataPath = __DIR__ . DIRECTORY_SEPARATOR . 'hexmap' . DIRECTORY_SEPARATOR . 'data.js';
$source = file_get_contents($dataPath);
if ($source === false || $source === '') {
  http_response_code(500);
  echo 'Cannot read hexmap/data.js';
  exit;
}

if (!preg_match('/^\s*window\.HEXMAP\s*=\s*(\{.*\})\s*;\s*$/s', $source, $matches)) {
  http_response_code(500);
  echo 'Unexpected data.js format';
  exit;
}

$hexmap = json_decode($matches[1], true);
if (!is_array($hexmap)) {
  http_response_code(500);
  echo 'Cannot parse HEXMAP JSON';
  exit;
}

$hexmap['pidRemap'] = $clean;
$hexmap['pidRemapUpdatedUtc'] = isset($data['updated_utc']) ? (string)$data['updated_utc'] : gmdate('c');

$encoded = json_encode($hexmap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($encoded === false) {
  http_response_code(500);
  echo 'Encode failed';
  exit;
}

$tmpPath = $dataPath . '.tmp';
$payload = 'window.HEXMAP=' . $encoded . ';';
if (file_put_contents($tmpPath, $payload) === false) {
  http_response_code(500);
  echo 'Write failed';
  exit;
}

if (!rename($tmpPath, $dataPath)) {
  @unlink($tmpPath);
  http_response_code(500);
  echo 'Rename failed';
  exit;
}

echo 'OK';
