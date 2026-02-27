<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

$state = api_load_state();
$mtime = api_state_mtime();
$profile = (string)($_GET['profile'] ?? 'compact');
$pid = (int)($_GET['pid'] ?? 0);
if ($pid <= 0) api_json_response(['error' => 'invalid_pid'], 400, $mtime);

$pd = ($state['provinces'] ?? [])[ (string)$pid ] ?? null;
if (!is_array($pd)) api_json_response(['error' => 'not_found'], 404, $mtime);
$refs = api_build_refs_by_owner_from_file_or_state($state);
$key = 'province:' . $pid;
if (!isset($pd['emblem_asset_id']) && isset($refs[$key]) && $refs[$key] !== '') {
  $pd['emblem_asset_id'] = $refs[$key];
}

// SVG payload is served via /api/assets/emblems/* only.
unset($pd['emblem_svg']);

if ($profile === 'compact') {
  unset($pd['province_card_image'], $pd['fill_rgba']);
}

api_json_response(['profile' => $profile, 'item' => $pd], 200, $mtime);
