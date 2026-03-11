<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../../../') ?: dirname(__DIR__, 3);
$runtimeDir = $root . '/data/module_runtime/marine_provinces';
if (!is_dir($runtimeDir)) {
    echo "Runtime dir not found\n";
    exit(0);
}
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($runtimeDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $file) {
    if ($file->isDir()) {
        @rmdir($file->getPathname());
    } else {
        @unlink($file->getPathname());
    }
}
@rmdir($runtimeDir);
echo "Removed $runtimeDir\n";
