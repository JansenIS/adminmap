<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/state_api.php';

@ini_set('memory_limit', '768M');

if (!function_exists('imagecreatetruecolor')) {
  api_json_response(['error' => 'gd_not_available'], 500, api_state_mtime());
}

$state = api_load_state();
$mtime = api_state_mtime();

$z = isset($_GET['z']) ? max(0, (int)$_GET['z']) : 0;
$x = isset($_GET['x']) ? max(0, (int)$_GET['x']) : 0;
$y = isset($_GET['y']) ? max(0, (int)$_GET['y']) : 0;
$mode = trim((string)($_GET['mode'] ?? 'provinces'));
$allowedModes = ['provinces', 'kingdoms', 'great_houses', 'minor_houses', 'free_cities'];
if (!in_array($mode, $allowedModes, true)) {
  api_json_response(['error' => 'invalid_mode', 'allowed' => $allowedModes], 400, $mtime);
}

$layer = api_build_layer_payload($state, $mode);
$version = (string)$layer['version'];
$tileSize = 256;

$root = api_repo_root();
$cacheBase = $root . '/data/tile_cache/' . $mode . '/' . $version;
$tilePath = $cacheBase . '/' . $z . '/' . $x . '/' . $y . '.png';
if (!is_file($tilePath)) {
  @mkdir(dirname($tilePath), 0775, true);

  $fullPath = $cacheBase . '/full.png';
  if (!is_file($fullPath)) {
    @mkdir($cacheBase, 0775, true);
    $maskPath = $root . '/provinces_id.png';
    $provMetaPath = $root . '/provinces.json';

    $mask = @imagecreatefrompng($maskPath);
    if (!$mask) api_json_response(['error' => 'mask_load_failed'], 500, $mtime);
    $w = imagesx($mask);
    $h = imagesy($mask);

    $metaRaw = @file_get_contents($provMetaPath);
    $meta = is_string($metaRaw) ? json_decode($metaRaw, true) : null;
    if (!is_array($meta) || !is_array($meta['provinces'] ?? null)) {
      imagedestroy($mask);
      api_json_response(['error' => 'provinces_meta_invalid'], 500, $mtime);
    }

    $pidByKey = [];
    foreach ($meta['provinces'] as $p) {
      if (!is_array($p)) continue;
      $key = (int)($p['key'] ?? 0);
      $pid = (int)($p['pid'] ?? 0);
      if ($key <= 0 || $pid <= 0) continue;
      $pidByKey[$key] = $pid;
    }

    $rgbaByPid = [];
    foreach (($layer['items'] ?? []) as $it) {
      if (!is_array($it)) continue;
      $pid = (int)($it['pid'] ?? 0);
      $rgba = $it['rgba'] ?? null;
      if ($pid <= 0 || !is_array($rgba) || count($rgba) !== 4) continue;
      $rgbaByPid[$pid] = [
        max(0,min(255,(int)$rgba[0])),
        max(0,min(255,(int)$rgba[1])),
        max(0,min(255,(int)$rgba[2])),
        max(0,min(255,(int)$rgba[3])),
      ];
    }

    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $w, $h, $transparent);

    $colorCache = [];
    for ($yy = 0; $yy < $h; $yy++) {
      for ($xx = 0; $xx < $w; $xx++) {
        $idx = imagecolorat($mask, $xx, $yy);
        $r = ($idx >> 16) & 255;
        $g = ($idx >> 8) & 255;
        $b = $idx & 255;
        $key = ($r << 16) | ($g << 8) | $b;
        $pid = $pidByKey[$key] ?? 0;
        if ($pid <= 0) continue;
        $rgba = $rgbaByPid[$pid] ?? null;
        if (!is_array($rgba)) continue;
        $ck = implode(',', $rgba);
        if (!isset($colorCache[$ck])) {
          $gdAlpha = 127 - (int)round(($rgba[3] / 255) * 127);
          $gdAlpha = max(0, min(127, $gdAlpha));
          $colorCache[$ck] = imagecolorallocatealpha($out, $rgba[0], $rgba[1], $rgba[2], $gdAlpha);
        }
        imagesetpixel($out, $xx, $yy, $colorCache[$ck]);
      }
    }

    imagepng($out, $fullPath);
    imagedestroy($out);
    imagedestroy($mask);
  }

  $full = @imagecreatefrompng($fullPath);
  if (!$full) api_json_response(['error' => 'full_tile_load_failed'], 500, $mtime);
  $fullW = imagesx($full);
  $fullH = imagesy($full);

  $scale = 1 << min(8, $z);
  $scaledW = max(1, $fullW * $scale);
  $scaledH = max(1, $fullH * $scale);

  $source = $full;
  if ($scale > 1) {
    $scaled = imagecreatetruecolor($scaledW, $scaledH);
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    $tr = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
    imagefilledrectangle($scaled, 0, 0, $scaledW, $scaledH, $tr);
    imagecopyresampled($scaled, $full, 0, 0, 0, 0, $scaledW, $scaledH, $fullW, $fullH);
    $source = $scaled;
  }

  $tile = imagecreatetruecolor($tileSize, $tileSize);
  imagealphablending($tile, false);
  imagesavealpha($tile, true);
  $tileTr = imagecolorallocatealpha($tile, 0, 0, 0, 127);
  imagefilledrectangle($tile, 0, 0, $tileSize, $tileSize, $tileTr);

  $sx = $x * $tileSize;
  $sy = $y * $tileSize;
  if ($sx < imagesx($source) && $sy < imagesy($source)) {
    imagecopy($tile, $source, 0, 0, $sx, $sy, $tileSize, $tileSize);
  }

  imagepng($tile, $tilePath);
  imagedestroy($tile);
  if ($source !== $full) imagedestroy($source);
  imagedestroy($full);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=60');
readfile($tilePath);
exit;
