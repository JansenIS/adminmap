<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/MarineProvincesModule.php';

$root = realpath(__DIR__ . '/../../../');
$opts = getopt('', ['dry-run::', 'downsample::', 'territorial-radius-cells::', 'target-neutral-area-cells::']);
$module = new MarineProvincesModule($root ?: dirname(__DIR__, 3));
$result = $module->run([
    'write_runtime' => !isset($opts['dry-run']) || (string)$opts['dry-run'] === '0' ? true : false,
    'downsample' => isset($opts['downsample']) ? (int)$opts['downsample'] : null,
    'territorial_radius_cells' => isset($opts['territorial-radius-cells']) ? (int)$opts['territorial-radius-cells'] : null,
    'target_neutral_area_cells' => isset($opts['target-neutral-area-cells']) ? (int)$opts['target-neutral-area-cells'] : null,
]);
echo json_encode($result['summary'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
