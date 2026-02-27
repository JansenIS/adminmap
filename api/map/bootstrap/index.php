<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

$state = api_load_state();
$mtime = api_state_mtime();
$profile = (string)($_GET['profile'] ?? 'full');

$out = [
  'schema_version' => $state['schema_version'] ?? null,
  'generated_utc' => $state['generated_utc'] ?? null,
  'terrain_types' => $state['terrain_types'] ?? [],
  'profile' => $profile,
];
if ($profile === 'compact') {
  $out['people_total'] = is_array($state['people'] ?? null) ? count($state['people']) : 0;
} else {
  $out['people'] = $state['people'] ?? [];
}

api_json_response($out, 200, $mtime);
