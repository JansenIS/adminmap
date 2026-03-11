#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/enterprise_module.php';

function eo_cli_arg(array $argv, string $name, ?string $default = null): ?string {
    foreach ($argv as $arg) {
        if (strpos($arg, '--' . $name . '=') === 0) return substr($arg, strlen($name) + 3);
    }
    return $default;
}

function eo_cli_bool(array $argv, string $name, bool $default = false): bool {
    $raw = eo_cli_arg($argv, $name, null);
    if ($raw === null) return $default;
    return filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
}

try {
    $root = eo_find_repo_root(getcwd());
    $options = [
        'dry_run' => eo_cli_bool($argv, 'dry-run', false),
        'attach_to_turn' => eo_cli_bool($argv, 'attach-to-turn', false),
        'turn_year' => (int)(eo_cli_arg($argv, 'turn-year', '0') ?? '0'),
        'run_label' => (string)(eo_cli_arg($argv, 'run-label', 'cli') ?? 'cli'),
    ];
    $result = eo_run_module($root, $options);
    echo json_encode($result['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
