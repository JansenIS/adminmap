<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/genealogy_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET']], 405, genealogy_mtime());
}

$remoteUrl = trim((string)($_GET['url'] ?? ''));
$name = trim((string)($_GET['name'] ?? ''));
if ($name === '') $name = '?';

$placeholder = static function () use ($name): void {
  header('Content-Type: image/svg+xml; charset=utf-8');
  header('Cache-Control: public, max-age=300');
  $first = function_exists('mb_substr') ? (mb_substr($name, 0, 1) ?: '?') : (substr($name, 0, 1) ?: '?');
  $letter = htmlspecialchars($first, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  echo '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160">'
    . '<rect width="160" height="160" fill="#1f2937"/>'
    . '<text x="80" y="98" text-anchor="middle" font-size="72" fill="#ffffff" font-family="Arial, sans-serif">' . $letter . '</text>'
    . '</svg>';
  exit;
};

if ($remoteUrl === '' || !preg_match('#^https?://#i', $remoteUrl)) {
  $placeholder();
}

$parts = parse_url($remoteUrl);
$host = strtolower((string)($parts['host'] ?? ''));
if ($host === '' || !in_array($host, ['upload.wikimedia.org', 'commons.wikimedia.org', 'wikipedia.org', 'www.wikipedia.org'], true)) {
  $placeholder();
}

$ctx = stream_context_create([
  'http' => [
    'method' => 'GET',
    'timeout' => 8,
    'follow_location' => 1,
    'max_redirects' => 3,
    'ignore_errors' => true,
    'header' => "User-Agent: adminmap/1.0\r\nAccept: image/*,*/*;q=0.8\r\n",
  ],
]);

$body = @file_get_contents($remoteUrl, false, $ctx);
$status = 0;
$contentType = '';
foreach (($http_response_header ?? []) as $header) {
  if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
    $status = (int)$m[1];
  }
  if (stripos($header, 'Content-Type:') === 0) {
    $contentType = trim((string)substr($header, 13));
  }
}

if ($body === false || $status < 200 || $status >= 300) {
  $placeholder();
}

if ($contentType === '' || stripos($contentType, 'image/') !== 0) {
  $contentType = 'image/jpeg';
}

header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=3600');
header('X-Image-Proxy: genealogy-photo');
echo $body;
