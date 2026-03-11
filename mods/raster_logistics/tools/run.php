<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/RasterLogisticsUnifiedModule.php';
$options = [];
foreach ($argv as $arg) {
    if (!str_starts_with($arg, '--')) continue;
    [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
    $options[$k] = in_array(strtolower($v), ['0', 'false', 'no'], true) ? false : (is_numeric($v) ? 0 + $v : $v);
}
$module = new RasterLogisticsUnifiedModule(dirname(__DIR__, 3));
$result = $module->run($options);
echo json_encode(['ok' => true, 'summary' => $result['summary'] ?? null, 'sources' => $result['sources'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
