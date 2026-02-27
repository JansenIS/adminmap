<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/state_api.php';

$mtime = api_state_mtime();
$offset = api_limit_int('offset', 0, 0, 1_000_000);
$limit = api_limit_int('limit', 100, 1, 500);
$profile = (string)($_GET['profile'] ?? 'compact');

$index = api_get_or_build_provinces_index();
$rows = is_array($index['items'] ?? null) ? $index['items'] : [];
$total = (int)($index['total'] ?? count($rows));
$slice = array_slice($rows, $offset, $limit);



$out = [];
foreach ($slice as $pd) {
  if (!is_array($pd)) continue;
  if ($profile === 'compact') {
    unset($pd['province_card_image']);
  }

  if ($profile !== 'full') {
    unset($pd['fill_rgba']);
  }

  $out[] = $pd;
}

api_json_response([
  'offset' => $offset,
  'limit' => $limit,
  'total' => $total,
  'profile' => $profile,
  'items' => $out,
], 200, $mtime);
