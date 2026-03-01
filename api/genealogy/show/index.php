<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/genealogy_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET']], 405, genealogy_mtime());
}

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') {
  api_json_response(['error' => 'id_required'], 400, genealogy_mtime());
}

$data = genealogy_load();
$idx = genealogy_find_character_index($data['characters'], $id);
if ($idx < 0) {
  api_json_response(['error' => 'character_not_found'], 404, genealogy_mtime());
}

$character = $data['characters'][$idx];
$related = [];
foreach ($data['relationships'] as $rel) {
  if (!is_array($rel)) continue;
  if (($rel['source_id'] ?? '') === $id || ($rel['target_id'] ?? '') === $id) {
    $related[] = $rel;
  }
}

api_json_response([
  'character' => $character,
  'relationships' => $related,
], 200, genealogy_mtime());
