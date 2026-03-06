<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/genealogy_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET']], 405, genealogy_mtime());
}

$data = genealogy_load();
$access = genealogy_resolve_admin_access();
if (is_array($access)) {
  $allowedIds = [];
  $characters = array_values(array_filter($data['characters'], static function ($char) use ($access, &$allowedIds) {
    if (!is_array($char) || !genealogy_character_in_access_clan($char, $access)) return false;
    $id = trim((string)($char['id'] ?? ''));
    if ($id !== '') $allowedIds[$id] = true;
    return true;
  }));

  $relationships = array_values(array_filter($data['relationships'], static function ($rel) use ($allowedIds) {
    if (!is_array($rel)) return false;
    $source = trim((string)($rel['source_id'] ?? ''));
    $target = trim((string)($rel['target_id'] ?? ''));
    return $source !== '' && $target !== '' && isset($allowedIds[$source]) && isset($allowedIds[$target]);
  }));

  api_json_response([
    'characters' => $characters,
    'relationships' => $relationships,
    'updated_at' => (string)($data['updated_at'] ?? ''),
    'scope' => ['clan' => (string)($access['clan'] ?? '')],
  ], 200, genealogy_mtime());
}

api_json_response([
  'characters' => array_values($data['characters']),
  'relationships' => array_values($data['relationships']),
  'updated_at' => (string)($data['updated_at'] ?? ''),
], 200, genealogy_mtime());
