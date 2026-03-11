<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib.php';

$modules = sidecar_build_registry();
sidecar_json_response([
    'ok' => true,
    'modules' => $modules,
    'count' => count($modules),
]);
