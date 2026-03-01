<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/genealogy_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405, genealogy_mtime());
}

$raw = file_get_contents('php://input');
$payload = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : null;
$valid = genealogy_validate_relationship_payload($payload);
if (!($valid['ok'] ?? false)) {
  api_json_response([
    'error' => $valid['error'] ?? 'invalid_payload',
    'allowed' => $valid['allowed'] ?? null,
  ], 400, genealogy_mtime());
}

$data = genealogy_load();
$rel = $valid['relationship'];
if (genealogy_find_character_index($data['characters'], $rel['source_id']) < 0 || genealogy_find_character_index($data['characters'], $rel['target_id']) < 0) {
  api_json_response(['error' => 'character_not_found'], 404, genealogy_mtime());
}

if (genealogy_relationship_exists($data['relationships'], $rel)) {
  api_json_response(['error' => 'relationship_exists'], 409, genealogy_mtime());
}

$rel['id'] = 'rel_' . substr(hash('sha1', $rel['type'] . ':' . $rel['source_id'] . ':' . $rel['target_id'] . ':' . microtime(true)), 0, 12);
$rel['created_at'] = gmdate('c');
$data['relationships'][] = $rel;
if (!genealogy_save($data)) {
  api_json_response(['error' => 'write_failed'], 500, genealogy_mtime());
}

api_json_response(['ok' => true, 'relationship' => $rel], 201, genealogy_mtime());
