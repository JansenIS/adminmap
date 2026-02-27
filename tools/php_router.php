<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath($root . $path);
if ($file && is_file($file) && str_starts_with($file, $root)) {
  return false;
}

if (preg_match('#^/api/provinces/(\d+)/?$#', $path, $m)) {
  $_GET['pid'] = $m[1];
  require $root . '/api/provinces/show/index.php';
  return true;
}
if (preg_match('#^/api/realms/(kingdoms|great_houses|minor_houses|free_cities)/([^/]+)/?$#', $path, $m)) {
  $_GET['type'] = $m[1];
  $_GET['id'] = urldecode($m[2]);
  require $root . '/api/realms/show/index.php';
  return true;
}
if (preg_match('#^/api/tiles/(\d+)/(\d+)/(\d+)/?$#', $path, $m)) {
  $_GET['z'] = $m[1];
  $_GET['x'] = $m[2];
  $_GET['y'] = $m[3];
  require $root . '/api/tiles/index.php';
  return true;
}

$target = $root . $path;
if (is_dir($target) && is_file($target . '/index.php')) {
  require $target . '/index.php';
  return true;
}
if (is_file($target . '.php')) {
  require $target . '.php';
  return true;
}

http_response_code(404);
echo 'Not Found';
