<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/RiverLogisticsModule.php';

$dryRun = true;
foreach ($argv as $arg) {
    if ($arg === '--dry-run=0' || $arg === '--write=1') {
        $dryRun = false;
    }
}

try {
    $module = new RiverLogisticsModule();
    $result = $module->run($dryRun);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
