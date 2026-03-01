<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/lib/genealogy_api.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'PATCH' && $method !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['PATCH', 'POST']], 405, genealogy_mtime());
}

$payload = api_read_json_body();
$id = trim((string)($payload['id'] ?? $_GET['id'] ?? ''));
$clan = trim((string)($payload['clan'] ?? $_GET['clan'] ?? ''));

if ($id === '') {
  api_json_response(['error' => 'id_required'], 400, genealogy_mtime());
}

$data = genealogy_load();
$character = genealogy_update_character_clan($data, $id, $clan);
if ($character === null) {
  api_json_response(['error' => 'character_not_found'], 404, genealogy_mtime());
}

if (!genealogy_save($data)) {
  api_json_response(['error' => 'write_failed'], 500, genealogy_mtime());
}

api_json_response([
  'ok' => true,
  'character' => $character,
], 200, genealogy_mtime());
