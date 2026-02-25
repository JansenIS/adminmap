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

$payload = [
  'updated_utc' => isset($data['updated_utc']) ? (string)$data['updated_utc'] : gmdate('c'),
  'pid_remap' => $clean,
];

$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($encoded === false) {
  http_response_code(500);
  echo 'Encode failed';
  exit;
}

$outPath = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'hexmap_pid_remap.json';
$tmpPath = $outPath . '.tmp';

if (file_put_contents($tmpPath, $encoded) === false) {
  http_response_code(500);
  echo 'Write failed';
  exit;
}

if (!rename($tmpPath, $outPath)) {
  @unlink($tmpPath);
  http_response_code(500);
  echo 'Rename failed';
  exit;
}

echo 'OK';
