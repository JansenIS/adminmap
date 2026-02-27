<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

$state = api_load_state();
$mtime = api_state_mtime();

api_json_response([
  'schema_version' => $state['schema_version'] ?? null,
  'generated_utc' => $state['generated_utc'] ?? null,
  'people' => $state['people'] ?? [],
  'terrain_types' => $state['terrain_types'] ?? [],
], 200, $mtime);
