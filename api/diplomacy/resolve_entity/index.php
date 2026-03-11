<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

diplomacy_require_feature();
$state = api_load_state();
$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') diplomacy_response(['error' => 'q_required'], 400);
$resolved = diplomacy_resolve_entity($state, $q);
if (!is_array($resolved)) diplomacy_response(['error' => 'not_found'], 404);
diplomacy_response(['ok' => true, 'entity' => $resolved]);
