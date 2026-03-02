<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/wiki_api.php';

$state = api_load_state();
$mtime = api_state_mtime();
$pages = api_wiki_list_pages($state);

api_json_response([
  'pages' => $pages,
  'total' => count($pages),
], 200, $mtime);
