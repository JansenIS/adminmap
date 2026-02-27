<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/state_api.php';

$state = api_load_state();
$mtime = api_state_mtime();
$version = hash('sha256', (string)$mtime . ':' . (string)filesize(api_state_path()));

api_json_response([
  'schema_version' => $state['schema_version'] ?? null,
  'generated_utc' => $state['generated_utc'] ?? null,
  'map_version' => $version,
  'state_size_bytes' => (int)filesize(api_state_path()),
], 200, $mtime);
