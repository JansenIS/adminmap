<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/genealogy_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['DELETE', 'POST']], 405, genealogy_mtime());
}

$payload = api_read_json_body();
$id = trim((string)($_GET['id'] ?? $payload['id'] ?? ''));
if ($id === '') {
  api_json_response(['error' => 'id_required'], 400, genealogy_mtime());
}

$data = genealogy_load();
if (!genealogy_delete_character($data, $id)) {
  api_json_response(['error' => 'character_not_found'], 404, genealogy_mtime());
}

if (!genealogy_save($data)) {
  api_json_response(['error' => 'write_failed'], 500, genealogy_mtime());
}

api_json_response(['ok' => true, 'deleted_id' => $id], 200, genealogy_mtime());
