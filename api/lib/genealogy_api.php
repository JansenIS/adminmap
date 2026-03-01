<?php

declare(strict_types=1);

require_once __DIR__ . '/state_api.php';

if (!function_exists('api_read_json_body')) {
  function api_read_json_body(): array {
    $raw = (string)file_get_contents('php://input');
    if (trim($raw) === '') return [];

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      api_json_response(['error' => 'invalid_json'], 400);
    }
    return $decoded;
  }
}



function genealogy_load_people_profiles(): array {
  try {
    $state = api_load_state();
  } catch (Throwable $e) {
    return [];
  }

  $profiles = $state['people_profiles'] ?? null;
  return is_array($profiles) ? $profiles : [];
}

function genealogy_sync_people_profiles_from_characters(array $characters, bool $overwritePhoto = false, bool $overwriteBio = false): void {
  $state = api_load_state();
  if (!is_array($state['people_profiles'] ?? null)) $state['people_profiles'] = [];

  $changed = false;
  foreach ($characters as $char) {
    if (!is_array($char)) continue;
    $name = trim((string)($char['name'] ?? ''));
    if ($name === '') continue;

    if (!is_array($state['people_profiles'][$name] ?? null)) {
      $state['people_profiles'][$name] = ['photo_url' => '', 'bio' => ''];
      $changed = true;
    }

    $photo = trim((string)($char['photo_url'] ?? ''));
    if ($photo !== '') {
      $currentPhoto = trim((string)($state['people_profiles'][$name]['photo_url'] ?? ''));
      if ($overwritePhoto || $currentPhoto === '') {
        if ($currentPhoto !== $photo) {
          $state['people_profiles'][$name]['photo_url'] = $photo;
          $changed = true;
        }
      }
    }

    $bioParts = array_values(array_filter([
      trim((string)($char['title'] ?? '')),
      trim((string)($char['notes'] ?? '')),
    ], static fn($v) => $v !== ''));
    if (!empty($bioParts)) {
      $bio = implode("\n\n", $bioParts);
      $currentBio = trim((string)($state['people_profiles'][$name]['bio'] ?? ''));
      if ($overwriteBio || $currentBio === '') {
        if ($currentBio !== $bio) {
          $state['people_profiles'][$name]['bio'] = $bio;
          $changed = true;
        }
      }
    }
  }

  if ($changed) {
    api_atomic_write_json(api_state_path(), $state);
  }
}


function genealogy_sync_characters_with_state_people(array &$data): bool {
  $existingNames = [];
  foreach (($data['characters'] ?? []) as $char) {
    if (!is_array($char)) continue;
    $name = trim((string)($char['name'] ?? ''));
    if ($name === '') continue;
    $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $existingNames[$key] = true;
  }

  $state = api_load_state();
  $mapNames = api_collect_people_names_from_state($state);
  if (!is_array($data['characters'] ?? null)) $data['characters'] = [];

  $changed = false;
  foreach ($mapNames as $nameRaw) {
    $name = trim((string)$nameRaw);
    if ($name === '') continue;
    $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    if (isset($existingNames[$key])) continue;

    $char = [
      'id' => genealogy_new_character_id($data['characters']),
      'name' => $name,
      'title' => '',
      'birth_year' => null,
      'death_year' => null,
      'photo_url' => '',
      'clan' => '',
      'notes' => '',
    ];
    $data['characters'][] = $char;
    $existingNames[$key] = true;
    $changed = true;
  }

  return $changed;
}

function genealogy_data_path(): string {
  return api_repo_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'genealogy_tree.json';
}

function genealogy_load(): array {
  $path = genealogy_data_path();
  if (!is_file($path)) {
    $decoded = [
      'characters' => [],
      'relationships' => [],
      'updated_at' => gmdate('c'),
    ];
  } else {
    $raw = (string)file_get_contents($path);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      api_json_response(['error' => 'genealogy_decode_failed'], 500);
    }
  }

  if (!is_array($decoded['characters'] ?? null)) $decoded['characters'] = [];
  if (!is_array($decoded['relationships'] ?? null)) $decoded['relationships'] = [];

  if (genealogy_sync_characters_with_state_people($decoded)) {
    genealogy_save($decoded);
  }

  genealogy_sync_people_profiles_from_characters($decoded['characters'], false);
  $profiles = genealogy_load_people_profiles();
  foreach ($decoded['characters'] as &$char) {
    if (!is_array($char)) continue;
    $name = trim((string)($char['name'] ?? ''));
    if ($name === '') continue;
    $profile = $profiles[$name] ?? null;
    if (!is_array($profile)) continue;

    $profilePhoto = trim((string)($profile['photo_url'] ?? ''));
    if ($profilePhoto !== '') {
      $char['photo_url'] = $profilePhoto;
    }
  }
  unset($char);

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
  $clan = trim((string)($payload['clan'] ?? ''));
  $notes = trim((string)($payload['notes'] ?? ''));

  return [
    'ok' => true,
    'character' => [
      'name' => $name,
      'title' => $title,
      'birth_year' => $birthYear,
      'death_year' => $deathYear,
      'photo_url' => $photo,
      'clan' => $clan,
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

function genealogy_delete_character(array &$data, string $id): bool {
  $idx = genealogy_find_character_index($data['characters'] ?? [], $id);
  if ($idx < 0) return false;

  array_splice($data['characters'], $idx, 1);
  $data['relationships'] = array_values(array_filter(
    $data['relationships'] ?? [],
    static function ($rel) use ($id) {
      if (!is_array($rel)) return false;
      return (string)($rel['source_id'] ?? '') !== $id && (string)($rel['target_id'] ?? '') !== $id;
    }
  ));
  return true;
}

function genealogy_delete_clan(array &$data, string $clan): int {
  $clan = trim($clan);
  if ($clan === '') return 0;

  $normalize = static function (string $value): string {
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    if ($value === '') return '';
    return function_exists('mb_strtolower')
      ? mb_strtolower($value, 'UTF-8')
      : strtolower($value);
  };

  $needle = $normalize($clan);
  if ($needle === '') return 0;

  $toDelete = [];
  foreach ($data['characters'] ?? [] as $char) {
    if (!is_array($char)) continue;
    if ($normalize((string)($char['clan'] ?? '')) === $needle) {
      $toDelete[] = (string)($char['id'] ?? '');
    }
  }

  $toDelete = array_values(array_filter($toDelete, static fn($id) => $id !== ''));
  if (!$toDelete) return 0;

  $idSet = array_fill_keys($toDelete, true);
  $data['characters'] = array_values(array_filter(
    $data['characters'] ?? [],
    static function ($char) use ($idSet) {
      if (!is_array($char)) return false;
      $id = (string)($char['id'] ?? '');
      return $id !== '' && !isset($idSet[$id]);
    }
  ));

  $data['relationships'] = array_values(array_filter(
    $data['relationships'] ?? [],
    static function ($rel) use ($idSet) {
      if (!is_array($rel)) return false;
      $source = (string)($rel['source_id'] ?? '');
      $target = (string)($rel['target_id'] ?? '');
      return $source !== '' && $target !== '' && !isset($idSet[$source]) && !isset($idSet[$target]);
    }
  ));

  return count($toDelete);
}




function genealogy_update_character(array &$data, string $id, array $payload): ?array {
  $idx = genealogy_find_character_index($data['characters'] ?? [], $id);
  if ($idx < 0) return null;

  $current = $data['characters'][$idx] ?? null;
  if (!is_array($current)) return null;

  $candidate = [
    'name' => (string)($current['name'] ?? ''),
    'title' => (string)($current['title'] ?? ''),
    'birth_year' => array_key_exists('birth_year', $current) ? $current['birth_year'] : null,
    'death_year' => array_key_exists('death_year', $current) ? $current['death_year'] : null,
    'photo_url' => (string)($current['photo_url'] ?? ''),
    'clan' => (string)($current['clan'] ?? ''),
    'notes' => (string)($current['notes'] ?? ''),
  ];

  $allowed = ['name', 'title', 'birth_year', 'death_year', 'photo_url', 'clan', 'notes'];
  foreach ($allowed as $field) {
    if (!array_key_exists($field, $payload)) continue;
    $value = $payload[$field];
    if (($field === 'birth_year' || $field === 'death_year') && $value === '') {
      $candidate[$field] = null;
      continue;
    }
    $candidate[$field] = $value;
  }

  $valid = genealogy_validate_character_payload($candidate);
  if (!($valid['ok'] ?? false)) return null;

  $updated = $valid['character'];
  $updated['id'] = $id;
  $data['characters'][$idx] = $updated;
  return $updated;
}
function genealogy_update_character_clan(array &$data, string $id, string $clan): ?array {
  $idx = genealogy_find_character_index($data['characters'] ?? [], $id);
  if ($idx < 0) return null;

  $normalizedClan = trim($clan);
  $character = $data['characters'][$idx];
  if (!is_array($character)) return null;
  $character['clan'] = $normalizedClan;
  $data['characters'][$idx] = $character;
  return $character;
}
