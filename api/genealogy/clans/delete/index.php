<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/lib/genealogy_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['DELETE', 'POST']], 405, genealogy_mtime());
}

$payload = api_read_json_body();
$clan = trim((string)($_GET['clan'] ?? $payload['clan'] ?? ''));
if ($clan === '') {
  api_json_response(['error' => 'clan_required'], 400, genealogy_mtime());
}

$data = genealogy_load();
$access = genealogy_resolve_admin_access();
if (is_array($access) && genealogy_normalize_clan($clan) !== (string)($access['clan_normalized'] ?? '')) {
  genealogy_forbidden_for_access($access);
}
$deletedCount = genealogy_delete_clan($data, $clan);
if ($deletedCount === 0) {
  api_json_response(['error' => 'clan_not_found'], 404, genealogy_mtime());
}

if (!genealogy_save($data)) {
  api_json_response(['error' => 'write_failed'], 500, genealogy_mtime());
}

api_json_response([
  'ok' => true,
  'deleted_clan' => $clan,
  'deleted_characters' => $deletedCount,
], 200, genealogy_mtime());
