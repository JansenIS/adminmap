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
orders_api_response(['ok'=>true,'url'=>'/data/orders_uploads/' . $name,'mime'=>$mime]);
