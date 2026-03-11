<?php

declare(strict_types=1);

function sidecar_repo_root(): string {
    return realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
}

function sidecar_json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function sidecar_read_json_input(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function sidecar_sanitize_module_id(string $id): string {
    return preg_match('/^[a-z0-9_\-]+$/', $id) ? $id : '';
}

function sidecar_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

function sidecar_dir_size(string $dir): int {
    if (!is_dir($dir)) return 0;
    $size = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $item) {
        if ($item->isFile()) $size += (int)$item->getSize();
    }
    return $size;
}

function sidecar_dir_mtime(string $dir): ?int {
    if (!is_dir($dir)) return null;
    $latest = filemtime($dir) ?: 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $item) {
        $mtime = (int)$item->getMTime();
        if ($mtime > $latest) $latest = $mtime;
    }
    return $latest > 0 ? $latest : null;
}

function sidecar_module_defaults(string $moduleId): array {
    $defaults = [
        'river_logistics' => [
            'name' => 'River Logistics',
            'kind' => 'sidecar',
            'entry_admin' => 'mods/river_logistics/admin.html',
            'entry_ui_alt' => 'sidecar_admin.html?module=river_logistics',
            'api' => [
                'run' => 'mods/river_logistics/api/run.php',
            ],
            'runtime_dir' => 'data/module_runtime/river_logistics',
            'supports' => ['dry_run' => true, 'attach_to_turn' => false, 'download' => true, 'cleanup' => true],
            'outputs' => ['network.json', 'summary.json'],
            'tags' => ['logistics', 'river'],
        ],
        'marine_provinces' => [
            'name' => 'Marine Provinces',
            'kind' => 'sidecar',
            'entry_admin' => 'mods/marine_provinces/admin.html',
            'entry_ui_alt' => 'sidecar_admin.html?module=marine_provinces',
            'api' => [
                'run' => 'mods/marine_provinces/api/run.php',
            ],
            'runtime_dir' => 'data/module_runtime/marine_provinces',
            'supports' => ['dry_run' => true, 'attach_to_turn' => false, 'download' => true, 'cleanup' => true],
            'outputs' => ['summary.json', 'network.json'],
            'tags' => ['logistics', 'marine'],
        ],
        'raster_logistics' => [
            'name' => 'Raster Logistics Unified',
            'kind' => 'sidecar',
            'entry_admin' => 'mods/raster_logistics/admin.html',
            'entry_ui_alt' => 'sidecar_admin.html?module=raster_logistics',
            'api' => [
                'run' => 'mods/raster_logistics/api/run.php',
            ],
            'runtime_dir' => 'data/module_runtime/raster_logistics',
            'supports' => ['dry_run' => true, 'attach_to_turn' => false, 'download' => true, 'cleanup' => true],
            'outputs' => ['network.json', 'summary.json'],
            'tags' => ['logistics', 'raster'],
        ],
        'enterprise_overlay' => [
            'name' => 'Enterprise Overlay',
            'kind' => 'sidecar',
            'entry_admin' => 'mods/enterprise_overlay/admin.html',
            'entry_ui_alt' => 'sidecar_admin.html?module=enterprise_overlay',
            'api' => [
                'run' => 'mods/enterprise_overlay/api/run.php',
            ],
            'runtime_dir' => 'data/module_runtime/enterprise_overlay',
            'supports' => ['dry_run' => true, 'attach_to_turn' => true, 'download' => true, 'cleanup' => true],
            'outputs' => ['state_overlay.json', 'trace.json'],
            'tags' => ['economy', 'overlay'],
        ],
        'enterprise_war_runtime' => [
            'name' => 'Enterprise War Runtime',
            'kind' => 'sidecar',
            'runtime_dir' => 'data/module_runtime/enterprise_war_runtime',
            'supports' => ['dry_run' => true, 'attach_to_turn' => false, 'download' => true, 'cleanup' => true],
            'outputs' => [],
            'tags' => ['war', 'economy'],
        ],
        'arrierban_demography' => [
            'name' => 'Arrierban Demography',
            'kind' => 'sidecar',
            'runtime_dir' => 'data/module_runtime/arrierban_demography',
            'supports' => ['dry_run' => true, 'attach_to_turn' => false, 'download' => true, 'cleanup' => true],
            'outputs' => [],
            'tags' => ['war', 'demography'],
        ],
    ];
    return $defaults[$moduleId] ?? [];
}

function sidecar_build_registry(): array {
    $root = sidecar_repo_root();
    $modsRoot = $root . '/mods';
    $entries = [];
    if (!is_dir($modsRoot)) return [];

    $mods = glob($modsRoot . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($mods as $modDir) {
        $moduleFile = $modDir . '/module.json';
        $folder = basename($modDir);
        $item = ['id' => $folder, 'folder' => $folder, 'installed' => false, 'invalid' => false, 'errors' => []];
        if (!is_file($moduleFile)) {
            $item['invalid'] = true;
            $item['errors'][] = 'module.json not found';
            $entries[] = $item;
            continue;
        }

        $raw = file_get_contents($moduleFile);
        $meta = json_decode((string)$raw, true);
        if (!is_array($meta)) {
            $item['invalid'] = true;
            $item['errors'][] = 'invalid JSON in module.json';
            $entries[] = $item;
            continue;
        }

        $id = (string)($meta['id'] ?? $meta['name'] ?? $folder);
        $id = sidecar_sanitize_module_id($id);
        if ($id === '') {
            $item['invalid'] = true;
            $item['errors'][] = 'invalid id/name';
            $entries[] = $item;
            continue;
        }

        $defaults = sidecar_module_defaults($id);
        $entrypoints = is_array($meta['entrypoints'] ?? null) ? $meta['entrypoints'] : [];
        $apiMeta = is_array($meta['api'] ?? null) ? $meta['api'] : [];

        $runtimeDir = (string)($meta['runtime_dir'] ?? $defaults['runtime_dir'] ?? ('data/module_runtime/' . $id));
        $supportsDefault = $defaults['supports'] ?? ['dry_run' => true, 'attach_to_turn' => false, 'download' => true, 'cleanup' => true];
        $supports = array_merge($supportsDefault, is_array($meta['supports'] ?? null) ? $meta['supports'] : []);

        $runApi = (string)($apiMeta['run'] ?? $entrypoints['api_run'] ?? $entrypoints['run_api'] ?? $defaults['api']['run'] ?? ('mods/' . $folder . '/api/run.php'));
        $downloadApi = (string)($apiMeta['download'] ?? $entrypoints['api_download'] ?? ('mods/' . $folder . '/api/download.php'));

        $runtimeAbs = $root . '/' . ltrim($runtimeDir, '/');
        $runtimePresent = is_dir($runtimeAbs);

        $outputs = $meta['outputs'] ?? ($defaults['outputs'] ?? []);
        if (!is_array($outputs)) $outputs = [];

        $entries[] = [
            'id' => $id,
            'name' => (string)($meta['title'] ?? $meta['name'] ?? $defaults['name'] ?? $id),
            'version' => (string)($meta['version'] ?? '0.0.0'),
            'kind' => (string)($meta['kind'] ?? $meta['type'] ?? $defaults['kind'] ?? 'sidecar'),
            'entry_admin' => (string)($meta['entry_admin'] ?? $entrypoints['admin'] ?? $defaults['entry_admin'] ?? ('mods/' . $folder . '/admin.html')),
            'entry_ui_alt' => (string)($meta['entry_ui_alt'] ?? $defaults['entry_ui_alt'] ?? ('sidecar_admin.html?module=' . rawurlencode($id))),
            'api' => [
                'run' => $runApi,
                'download' => $downloadApi,
            ],
            'runtime_dir' => $runtimeDir,
            'supports' => [
                'dry_run' => (bool)($supports['dry_run'] ?? true),
                'attach_to_turn' => (bool)($supports['attach_to_turn'] ?? false),
                'download' => (bool)($supports['download'] ?? true),
                'cleanup' => (bool)($supports['cleanup'] ?? true),
            ],
            'outputs' => array_values(array_filter(array_map('strval', $outputs))),
            'tags' => array_values(array_filter(array_map('strval', is_array($meta['tags'] ?? null) ? $meta['tags'] : ($defaults['tags'] ?? [])))),
            'installed' => true,
            'invalid' => false,
            'errors' => [],
            'status' => [
                'runtime_present' => $runtimePresent,
                'runtime_missing' => !$runtimePresent,
                'runtime_size' => sidecar_dir_size($runtimeAbs),
                'runtime_mtime' => sidecar_dir_mtime($runtimeAbs),
            ],
        ];
    }

    usort($entries, static fn(array $a, array $b): int => strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? '')));
    return $entries;
}

function sidecar_registry_by_id(): array {
    $all = sidecar_build_registry();
    $map = [];
    foreach ($all as $entry) {
        if (!empty($entry['id'])) $map[$entry['id']] = $entry;
    }
    return $map;
}

function sidecar_is_path_in_mods(string $relativePath): bool {
    $root = sidecar_repo_root();
    $target = realpath($root . '/' . ltrim($relativePath, '/'));
    $mods = realpath($root . '/mods');
    if ($target === false || $mods === false) return false;
    return str_starts_with($target, $mods . DIRECTORY_SEPARATOR) || $target === $mods;
}

function sidecar_run_php_endpoint(string $relativePath, array $payload = []): array {
    $root = sidecar_repo_root();
    if (!sidecar_is_path_in_mods($relativePath)) {
        throw new RuntimeException('run endpoint path is outside mods');
    }
    $abs = realpath($root . '/' . ltrim($relativePath, '/'));
    if ($abs === false || !is_file($abs)) {
        throw new RuntimeException('run endpoint not found: ' . $relativePath);
    }

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($abs);
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = array_merge($_ENV, [
        'REQUEST_METHOD' => 'POST',
        'CONTENT_TYPE' => 'application/json',
    ]);
    $proc = proc_open($cmd, $desc, $pipes, $root, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('failed to start php endpoint');
    }
    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    $decoded = json_decode((string)$stdout, true);
    if (!is_array($decoded)) {
        $decoded = ['ok' => false, 'raw' => trim((string)$stdout), 'stderr' => trim((string)$stderr)];
    }
    $decoded['_exec'] = ['code' => $code, 'stderr' => trim((string)$stderr)];
    return $decoded;
}

function sidecar_attach_overlay(string $moduleId, int $turnYear, array $result): array {
    if ($turnYear <= 0) {
        return ['ok' => false, 'error' => 'turn_year must be > 0'];
    }

    $root = sidecar_repo_root();
    $turnFile = $root . '/data/turns/turn_' . $turnYear . '.json';
    if (!is_file($turnFile)) {
        return ['ok' => false, 'error' => 'turn file not found', 'path' => 'data/turns/turn_' . $turnYear . '.json'];
    }

    $turnRaw = file_get_contents($turnFile);
    $turn = json_decode((string)$turnRaw, true);
    if (!is_array($turn)) {
        return ['ok' => false, 'error' => 'invalid turn json'];
    }

    if (!isset($turn['sidecar_overlays']) || !is_array($turn['sidecar_overlays'])) {
        $turn['sidecar_overlays'] = [];
    }
    $turn['sidecar_overlays'][$moduleId] = [
        'attached_at' => gmdate('c'),
        'result' => $result,
    ];

    $ok = file_put_contents(
        $turnFile,
        json_encode($turn, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
    );

    return $ok !== false
        ? ['ok' => true, 'path' => 'data/turns/turn_' . $turnYear . '.json']
        : ['ok' => false, 'error' => 'failed to write turn file'];
}
