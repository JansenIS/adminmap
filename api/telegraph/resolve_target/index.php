<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

telegraph_require_feature();
$state = api_load_state();
$payload = telegraph_request_payload();
$needle = trim((string)($payload['query'] ?? ($_GET['query'] ?? '')));
if ($needle === '') telegraph_response(['error' => 'query_required'], 400);
$target = telegraph_resolve_target_entity($state, $needle);
if (!is_array($target)) telegraph_response(['ok' => false, 'found' => false]);
telegraph_response(['ok' => true, 'found' => true, 'target' => $target]);
