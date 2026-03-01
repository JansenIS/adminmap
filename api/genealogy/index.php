<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/genealogy_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
  api_json_response(['error' => 'method_not_allowed', 'allowed' => ['GET']], 405, genealogy_mtime());
}

$data = genealogy_load();
api_json_response([
  'characters' => array_values($data['characters']),
  'relationships' => array_values($data['relationships']),
  'updated_at' => (string)($data['updated_at'] ?? ''),
], 200, genealogy_mtime());
