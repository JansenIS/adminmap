<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/RasterLogisticsUnifiedModule.php';
$module = new RasterLogisticsUnifiedModule(dirname(__DIR__, 3));
echo json_encode($module->cleanup(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
