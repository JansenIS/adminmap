<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/genealogy_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, genealogy_mtime());
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : null;
$valid = genealogy_validate_character_payload($payload);
if (!($valid['ok'] ?? false)) {
  api_json_response(['error' => $valid['error'] ?? 'invalid_payload'], 400, genealogy_mtime());
}

$data = genealogy_load();
$char = $valid['character'];
$char['id'] = genealogy_new_character_id($data['characters']);
$char['created_at'] = gmdate('c');
$data['characters'][] = $char;

if (!genealogy_save($data)) {
  api_json_response(['error' => 'write_failed'], 500, genealogy_mtime());
}

api_json_response(['ok' => true, 'character' => $char], 201, genealogy_mtime());
