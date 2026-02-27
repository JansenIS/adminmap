<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/state_api.php';

$state = api_load_state();
$mtime = api_state_mtime();
$pid = (int)($_GET['pid'] ?? 0);
if ($pid <= 0) api_json_response(['error' => 'invalid_pid'], 400, $mtime);

$pd = ($state['provinces'] ?? [])[ (string)$pid ] ?? null;
if (!is_array($pd)) api_json_response(['error' => 'not_found'], 404, $mtime);

api_json_response(['item' => $pd], 200, $mtime);
