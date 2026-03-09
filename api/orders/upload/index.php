<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/orders_api.php';
$state = api_load_state();
orders_api_require_player_session($state);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') orders_api_response(['error'=>'method_not_allowed'],405);
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) orders_api_response(['error'=>'file_required'],400);
$f = $_FILES['file'];
$tmp = (string)($f['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) orders_api_response(['error'=>'upload_invalid'],400);
$mime = mime_content_type($tmp) ?: '';
$allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
if (!isset($allowed[$mime])) orders_api_response(['error'=>'unsupported_file_type','mime'=>$mime],400);
$dir = api_repo_root() . '/data/orders_uploads';
if (!is_dir($dir)) @mkdir($dir,0775,true);
$name = 'ord_up_' . substr(hash('sha256', microtime(true).rand(1,99999)),0,16) . '.' . $allowed[$mime];
$dest = $dir . '/' . $name;
if (!move_uploaded_file($tmp, $dest)) orders_api_response(['error'=>'save_failed'],500);
$visibility = trim((string)($_POST['visibility'] ?? 'private')) === 'public' ? 'public' : 'private';
$raw = @file_get_contents($dest);
$attachment = [
  'id' => orders_api_next_id('att'),
  'url' => '/data/orders_uploads/' . $name,
  'visibility' => $visibility,
  'access_scope' => trim((string)($_POST['access_scope'] ?? $visibility)),
  'mime' => $mime,
  'size_bytes' => (int)@filesize($dest),
  'file_name' => trim((string)($f['name'] ?? $name)),
  'source' => trim((string)($_POST['source'] ?? 'web')),
  'kind' => trim((string)($_POST['kind'] ?? 'file')),
  'title' => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200),
  'description' => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 1000),
  'tags' => array_values(array_filter(array_map('trim', explode(',', (string)($_POST['tags'] ?? ''))))),
  'checksum_sha1' => is_string($raw) ? sha1($raw) : '',
  'meta' => [
    'client_note' => mb_substr(trim((string)($_POST['client_note'] ?? '')), 0, 300),
  ],
  'uploaded_at' => gmdate('c'),
];
if (!in_array((string)$attachment['access_scope'], ['public','private','owner_only','admin_only'], true)) $attachment['access_scope'] = $visibility;
orders_api_response(['ok'=>true,'attachment'=>$attachment]);
