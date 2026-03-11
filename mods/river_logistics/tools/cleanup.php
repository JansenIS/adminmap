<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/RiverLogisticsModule.php';

try {
    $module = new RiverLogisticsModule();
    $result = $module->cleanup();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
