<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/genealogy_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, genealogy_mtime());
}

if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
  api_json_response(['error' => 'photo_required'], 400, genealogy_mtime());
}

$file = $_FILES['photo'];
$err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
  api_json_response(['error' => 'upload_failed', 'code' => $err], 400, genealogy_mtime());
}

$tmp = (string)($file['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
  api_json_response(['error' => 'upload_tmp_missing'], 400, genealogy_mtime());
}

$raw = @file_get_contents($tmp);
if (!is_string($raw) || $raw === '') {
  api_json_response(['error' => 'upload_read_failed'], 400, genealogy_mtime());
}

if (!function_exists('imagecreatefromstring')) {
  api_json_response(['error' => 'gd_missing'], 500, genealogy_mtime());
}

$src = @imagecreatefromstring($raw);
if (!$src) {
  api_json_response(['error' => 'image_decode_failed'], 400, genealogy_mtime());
}

$w = imagesx($src);
$h = imagesy($src);
if ($w <= 1 || $h <= 1) {
  imagedestroy($src);
  api_json_response(['error' => 'image_too_small'], 400, genealogy_mtime());
}

$side = min($w, $h);
$x = (int)floor(($w - $side) / 2);
$y = (int)floor(($h - $side) / 2);

$targetSide = 512;
$dst = imagecreatetruecolor($targetSide, $targetSide);
if (!$dst) {
  imagedestroy($src);
  api_json_response(['error' => 'image_alloc_failed'], 500, genealogy_mtime());
}

if (!imagecopyresampled($dst, $src, 0, 0, $x, $y, $targetSide, $targetSide, $side, $side)) {
  imagedestroy($src);
  imagedestroy($dst);
  api_json_response(['error' => 'image_resample_failed'], 500, genealogy_mtime());
}

$personName = trim((string)($_POST['name'] ?? ''));
$slug = preg_replace('/[^a-z0-9_\-]+/i', '_', $personName);
$slug = trim((string)$slug, '_');
if ($slug === '') $slug = 'person';
$fileName = $slug . '_' . substr(sha1($personName . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX)), 0, 10) . '.jpg';

$root = api_repo_root();
$dir = $root . '/people';
if (!is_dir($dir)) {
  @mkdir($dir, 0775, true);
}
if (!is_dir($dir)) {
  imagedestroy($src);
  imagedestroy($dst);
  api_json_response(['error' => 'people_dir_missing'], 500, genealogy_mtime());
}

$path = $dir . '/' . $fileName;
if (!imagejpeg($dst, $path, 88)) {
  imagedestroy($src);
  imagedestroy($dst);
  api_json_response(['error' => 'image_save_failed'], 500, genealogy_mtime());
}

imagedestroy($src);
imagedestroy($dst);

api_json_response([
  'ok' => true,
  'photo_url' => 'people/' . $fileName,
  'crop' => ['x' => $x, 'y' => $y, 'size' => $side],
  'output' => ['width' => $targetSide, 'height' => $targetSide, 'format' => 'jpg'],
], 201, genealogy_mtime());
