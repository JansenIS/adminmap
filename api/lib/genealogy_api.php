<?php

declare(strict_types=1);

require_once __DIR__ . '/state_api.php';

function genealogy_data_path(): string {
  return api_repo_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'genealogy_tree.json';
}

function genealogy_load(): array {
  $path = genealogy_data_path();
  if (!is_file($path)) {
    return [
      'characters' => [],
      'relationships' => [],
      'updated_at' => gmdate('c'),
    ];
  }

  $raw = (string)file_get_contents($path);
  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    api_json_response(['error' => 'genealogy_decode_failed'], 500);
  }

  if (!is_array($decoded['characters'] ?? null)) $decoded['characters'] = [];
  if (!is_array($decoded['relationships'] ?? null)) $decoded['relationships'] = [];
  return $decoded;
}

function genealogy_mtime(): int {
  return (int)@filemtime(genealogy_data_path()) ?: time();
}

function genealogy_save(array $data): bool {
  $data['updated_at'] = gmdate('c');
  return api_atomic_write_json(genealogy_data_path(), $data);
}

function genealogy_new_character_id(array $characters): string {
  $max = 0;
  foreach ($characters as $char) {
    if (!is_array($char)) continue;
    $id = (string)($char['id'] ?? '');
    if (preg_match('/^char_(\d+)$/', $id, $m)) {
      $max = max($max, (int)$m[1]);
    }
  }
  return 'char_' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

function genealogy_find_character_index(array $characters, string $id): int {
  foreach ($characters as $i => $char) {
    if (is_array($char) && (string)($char['id'] ?? '') === $id) return (int)$i;
  }
  return -1;
}

function genealogy_validate_character_payload(?array $payload): array {
  if (!is_array($payload)) return ['ok' => false, 'error' => 'invalid_json'];
  $name = trim((string)($payload['name'] ?? ''));
  if ($name === '') return ['ok' => false, 'error' => 'name_required'];

  $title = trim((string)($payload['title'] ?? ''));
  $birthYear = isset($payload['birth_year']) && $payload['birth_year'] !== '' ? (int)$payload['birth_year'] : null;
  $deathYear = isset($payload['death_year']) && $payload['death_year'] !== '' ? (int)$payload['death_year'] : null;
  if ($birthYear !== null && ($birthYear < -5000 || $birthYear > 3000)) return ['ok' => false, 'error' => 'birth_year_invalid'];
  if ($deathYear !== null && ($deathYear < -5000 || $deathYear > 3000)) return ['ok' => false, 'error' => 'death_year_invalid'];
  if ($birthYear !== null && $deathYear !== null && $deathYear < $birthYear) return ['ok' => false, 'error' => 'death_before_birth'];

  $photo = trim((string)($payload['photo_url'] ?? ''));
  $notes = trim((string)($payload['notes'] ?? ''));

  return [
    'ok' => true,
    'character' => [
      'name' => $name,
      'title' => $title,
      'birth_year' => $birthYear,
      'death_year' => $deathYear,
      'photo_url' => $photo,
      'notes' => $notes,
    ],
  ];
}

function genealogy_validate_relationship_payload(?array $payload): array {
  if (!is_array($payload)) return ['ok' => false, 'error' => 'invalid_json'];
  $type = trim((string)($payload['type'] ?? ''));
  $source = trim((string)($payload['source_id'] ?? ''));
  $target = trim((string)($payload['target_id'] ?? ''));
  $allowed = ['parent_child', 'siblings', 'spouses'];
  if (!in_array($type, $allowed, true)) return ['ok' => false, 'error' => 'type_invalid', 'allowed' => $allowed];
  if ($source === '' || $target === '') return ['ok' => false, 'error' => 'source_target_required'];
  if ($source === $target) return ['ok' => false, 'error' => 'self_relation_forbidden'];
  return [
    'ok' => true,
    'relationship' => [
      'type' => $type,
      'source_id' => $source,
      'target_id' => $target,
    ],
  ];
}

function genealogy_relationship_exists(array $relationships, array $candidate): bool {
  foreach ($relationships as $row) {
    if (!is_array($row)) continue;
    if (($row['type'] ?? '') !== $candidate['type']) continue;

    $a1 = (string)($row['source_id'] ?? '');
    $b1 = (string)($row['target_id'] ?? '');
    $a2 = (string)($candidate['source_id'] ?? '');
    $b2 = (string)($candidate['target_id'] ?? '');

    if ($candidate['type'] === 'parent_child') {
      if ($a1 === $a2 && $b1 === $b2) return true;
      continue;
    }

    if (($a1 === $a2 && $b1 === $b2) || ($a1 === $b2 && $b1 === $a2)) return true;
  }
  return false;
}
