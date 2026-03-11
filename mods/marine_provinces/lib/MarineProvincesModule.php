<?php

declare(strict_types=1);

final class MarineProvincesModule
{
    private string $rootDir;
    private string $moduleDir;
    private string $runtimeDir;
    private string $sidecarDir;

    public function __construct(string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->moduleDir = $this->rootDir . '/mods/marine_provinces';
        $this->runtimeDir = $this->rootDir . '/data/module_runtime/marine_provinces';
        $this->sidecarDir = $this->moduleDir . '/data/sidecar';

        if (!is_dir($this->runtimeDir)) {
            @mkdir($this->runtimeDir, 0777, true);
        }
    }

    public function run(array $options = []): array
    {
        $cfg = $this->loadConfig($options);
        $provinceData = $this->analyzeProvinceRaster($cfg);
        $seaData = $this->analyzeSeaRaster($cfg);
        $territorial = $this->buildTerritorialZones($provinceData, $seaData, $cfg);
        $neutral = $this->buildNeutralSeaProvinces($seaData, $territorial, $cfg);
        $marineGrid = $this->composeMarineGrid($seaData, $territorial, $neutral);
        $marineProvinces = $this->buildMarineProvinceRecords($marineGrid, $provinceData, $cfg);
        $graph = $this->buildMarineGraph($marineGrid, $marineProvinces, $provinceData, $cfg);
        $summary = $this->buildSummary($provinceData, $seaData, $territorial, $marineProvinces, $graph);

        $result = [
            'meta' => [
                'module' => 'marine_provinces',
                'generated_at' => gmdate('c'),
                'root_dir' => $this->rootDir,
                'config' => $cfg,
            ],
            'province_raster' => $provinceData['meta'],
            'sea_raster' => $seaData['meta'],
            'territorial' => $territorial['meta'],
            'marine_provinces' => $marineProvinces,
            'graph' => $graph,
            'summary' => $summary,
        ];

        if (!empty($cfg['write_runtime'])) {
            $this->writeJson($this->runtimeDir . '/network.json', $result);
            $this->writeJson($this->runtimeDir . '/summary.json', $summary);
            if (!empty($provinceData['unresolved_color_map'])) {
                $this->writeJson($this->runtimeDir . '/unresolved_color_map.json', $provinceData['unresolved_color_map']);
            }
            $this->writeOverlayPng($marineGrid, $provinceData, $this->runtimeDir . '/marine_overlay.png');
        }

        return $result;
    }

    public function computeRoute(string $from, string $to, array $options = []): array
    {
        $networkPath = $this->runtimeDir . '/network.json';
        if (!is_file($networkPath)) {
            $this->run();
        }
        $network = $this->readJson($networkPath, []);
        $graph = $network['graph'] ?? [];
        $nodes = $graph['nodes'] ?? [];
        $adj = $graph['adjacency'] ?? [];
        if (!$nodes || !$adj) {
            throw new RuntimeException('Marine graph is missing. Run module first.');
        }

        $cfg = $this->loadConfig();
        $modes = $cfg['modes'] ?? [];
        $mode = (string)($options['mode'] ?? 'naval_trade');
        $rule = $modes[$mode] ?? ($modes['naval_trade'] ?? ['multipliers' => []]);

        $fromNode = $this->resolveRouteNode($from, $nodes);
        $toNode = $this->resolveRouteNode($to, $nodes);

        $route = $this->dijkstra(
            $nodes,
            $adj,
            $fromNode,
            $toNode,
            static function (array $edge) use ($rule): ?float {
                $class = (string)($edge['class'] ?? 'neutral');
                if (!empty($rule['forbid']) && in_array($class, (array)$rule['forbid'], true)) {
                    return null;
                }
                $mul = (float)($rule['multipliers'][$class] ?? 1.0);
                return ((float)($edge['cost'] ?? 1.0)) * $mul;
            }
        );

        return [
            'ok' => !empty($route['path']),
            'from' => $from,
            'to' => $to,
            'from_node' => $fromNode,
            'to_node' => $toNode,
            'mode' => $mode,
            'path' => $route['path'] ?? [],
            'total_cost' => $route['distance'] ?? null,
        ];
    }

    private function loadConfig(array $overrides = []): array
    {
        $cfg = $this->readJson($this->sidecarDir . '/marine_calibration.json', []);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $cfg['downsample'] = (int)($overrides['downsample'] ?? $cfg['downsample'] ?? 4);
        $cfg['territorial_radius_cells'] = (int)($overrides['territorial_radius_cells'] ?? $cfg['territorial_radius_cells'] ?? 18);
        $cfg['target_neutral_area_cells'] = (int)($overrides['target_neutral_area_cells'] ?? $cfg['target_neutral_area_cells'] ?? 1800);
        $cfg['min_neutral_area_cells'] = (int)($cfg['min_neutral_area_cells'] ?? 500);
        $cfg['max_neutral_seeds_per_component'] = (int)($cfg['max_neutral_seeds_per_component'] ?? 12);
        $cfg['coast_dilation_steps'] = (int)($cfg['coast_dilation_steps'] ?? 1);
        $cfg['sea_detect'] = is_array($cfg['sea_detect'] ?? null) ? $cfg['sea_detect'] : [];
        $cfg['modes'] = is_array($cfg['modes'] ?? null) ? $cfg['modes'] : [];
        $cfg['write_runtime'] = (bool)($overrides['write_runtime'] ?? true);
        return $cfg;
    }

    private function analyzeProvinceRaster(array $cfg): array
    {
        $path = $this->rootDir . '/provinces_id.png';
        if (!is_file($path)) {
            throw new RuntimeException('provinces_id.png not found.');
        }
        $im = $this->loadPng($path);
        $w = imagesx($im);
        $h = imagesy($im);
        $ds = max(1, (int)$cfg['downsample']);

        $colors = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($im, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha === 127) {
                    continue;
                }
                $hex = $this->rgbToHex($this->rgbaToRgb($rgba));
                if (!isset($colors[$hex])) {
                    $colors[$hex] = ['hex' => $hex, 'area' => 0, 'sum_x' => 0.0, 'sum_y' => 0.0, 'min_x' => $x, 'min_y' => $y, 'max_x' => $x, 'max_y' => $y];
                }
                $colors[$hex]['area']++;
                $colors[$hex]['sum_x'] += $x;
                $colors[$hex]['sum_y'] += $y;
                $colors[$hex]['min_x'] = min($colors[$hex]['min_x'], $x);
                $colors[$hex]['min_y'] = min($colors[$hex]['min_y'], $y);
                $colors[$hex]['max_x'] = max($colors[$hex]['max_x'], $x);
                $colors[$hex]['max_y'] = max($colors[$hex]['max_y'], $y);
            }
        }

        $records = $this->loadProvinceRecords();
        [$colorMap, $unresolved] = $this->resolveProvinceColors($colors, $records);

        $gridW = (int)ceil($w / $ds);
        $gridH = (int)ceil($h / $ds);
        $grid = array_fill(0, $gridH, array_fill(0, $gridW, null));
        $provinceCells = [];

        for ($gy = 0; $gy < $gridH; $gy++) {
            for ($gx = 0; $gx < $gridW; $gx++) {
                $counts = [];
                for ($py = $gy * $ds; $py < min($h, ($gy + 1) * $ds); $py++) {
                    for ($px = $gx * $ds; $px < min($w, ($gx + 1) * $ds); $px++) {
                        $rgba = imagecolorat($im, $px, $py);
                        $alpha = ($rgba >> 24) & 0x7F;
                        if ($alpha === 127) {
                            continue;
                        }
                        $hex = $this->rgbToHex($this->rgbaToRgb($rgba));
                        $pid = (string)($colorMap[$hex]['id'] ?? ('color:' . $hex));
                        $counts[$pid] = ($counts[$pid] ?? 0) + 1;
                    }
                }
                if (!$counts) {
                    continue;
                }
                arsort($counts);
                $pid = (string)array_key_first($counts);
                $grid[$gy][$gx] = $pid;
                $provinceCells[$pid][] = [$gx, $gy];
            }
        }
        imagedestroy($im);

        $provinceNames = [];
        foreach ($colorMap as $hex => $r) {
            $provinceNames[(string)$r['id']] = (string)($r['name'] ?? $r['id']);
        }
        foreach ($unresolved as $hex => $r) {
            $pid = 'color:' . $hex;
            $provinceNames[$pid] = 'Color ' . $hex;
        }

        $adjacency = [];
        for ($y = 0; $y < $gridH; $y++) {
            for ($x = 0; $x < $gridW; $x++) {
                $a = $grid[$y][$x];
                if ($a === null) {
                    continue;
                }
                foreach ([[1,0],[0,1]] as [$dx,$dy]) {
                    $nx = $x + $dx; $ny = $y + $dy;
                    if ($nx < 0 || $ny < 0 || $nx >= $gridW || $ny >= $gridH) {
                        continue;
                    }
                    $b = $grid[$ny][$nx];
                    if ($b !== null && $a !== $b) {
                        $k = $this->pairKey($a, $b);
                        $adjacency[$k] = ($adjacency[$k] ?? 0) + 1;
                    }
                }
            }
        }

        return [
            'grid' => $grid,
            'grid_w' => $gridW,
            'grid_h' => $gridH,
            'province_cells' => $provinceCells,
            'province_names' => $provinceNames,
            'adjacency' => $adjacency,
            'unresolved_color_map' => $unresolved,
            'meta' => [
                'width_px' => $w,
                'height_px' => $h,
                'downsample' => $ds,
                'grid_w' => $gridW,
                'grid_h' => $gridH,
                'province_count' => count($provinceCells),
                'adjacent_pairs' => count($adjacency),
            ],
        ];
    }

    private function analyzeSeaRaster(array $cfg): array
    {
        $manualMask = $this->sidecarDir . '/sea_mask.png';
        $sourcePath = null;
        $useManual = !empty($cfg['sea_detect']['use_manual_mask_if_present']) && is_file($manualMask);
        if ($useManual) {
            $sourcePath = $manualMask;
        } else {
            $sourcePath = $this->rootDir . '/map.png';
        }
        if (!is_file($sourcePath)) {
            throw new RuntimeException('map.png or sidecar sea_mask.png not found.');
        }

        $im = $this->loadPng($sourcePath);
        $w = imagesx($im);
        $h = imagesy($im);
        $ds = max(1, (int)$cfg['downsample']);
        $gridW = (int)ceil($w / $ds);
        $gridH = (int)ceil($h / $ds);
        $candidate = array_fill(0, $gridH, array_fill(0, $gridW, false));

        for ($gy = 0; $gy < $gridH; $gy++) {
            for ($gx = 0; $gx < $gridW; $gx++) {
                $water = 0;
                $total = 0;
                for ($py = $gy * $ds; $py < min($h, ($gy + 1) * $ds); $py++) {
                    for ($px = $gx * $ds; $px < min($w, ($gx + 1) * $ds); $px++) {
                        $rgba = imagecolorat($im, $px, $py);
                        $alpha = ($rgba >> 24) & 0x7F;
                        if ($alpha === 127) {
                            continue;
                        }
                        $total++;
                        if ($useManual) {
                            $rgb = $this->rgbaToRgb($rgba);
                            if (($rgb['r'] + $rgb['g'] + $rgb['b']) > 12) {
                                $water++;
                            }
                        } else {
                            if ($this->isSeaPixel($this->rgbaToRgb($rgba), $cfg['sea_detect'])) {
                                $water++;
                            }
                        }
                    }
                }
                if ($total > 0 && $water / $total >= 0.45) {
                    $candidate[$gy][$gx] = true;
                }
            }
        }
        imagedestroy($im);

        $borderComponents = $this->findBorderConnectedWater($candidate, (int)($cfg['sea_detect']['min_border_component_cells'] ?? 120));
        $marineMask = $this->pruneToOpenSea($borderComponents['mask'], $cfg);
        $components = $this->connectedComponents($marineMask);

        return [
            'candidate' => $candidate,
            'mask' => $marineMask,
            'components' => $components,
            'meta' => [
                'source' => $sourcePath,
                'grid_w' => $gridW,
                'grid_h' => $gridH,
                'marine_cells' => $this->countTrue($marineMask),
                'component_count' => count($components),
            ],
        ];
    }

    private function buildTerritorialZones(array $provinceData, array $seaData, array $cfg): array
    {
        $grid = $provinceData['grid'];
        $sea = $seaData['mask'];
        $h = count($sea);
        $w = $h ? count($sea[0]) : 0;
        $radius = max(1, (int)$cfg['territorial_radius_cells']);

        $seeds = [];
        $coastal = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (!$sea[$y][$x]) {
                    continue;
                }
                foreach ($this->neighbors4($x, $y, $w, $h) as [$nx, $ny]) {
                    $pid = $grid[$ny][$nx] ?? null;
                    if ($pid !== null) {
                        $coastal[$pid] = true;
                        $key = $pid . '@' . $x . ',' . $y;
                        $seeds[$key] = ['pid' => $pid, 'x' => $x, 'y' => $y];
                    }
                }
            }
        }

        $assign = array_fill(0, $h, array_fill(0, $w, null));
        $dist = array_fill(0, $h, array_fill(0, $w, PHP_INT_MAX));
        $queue = new SplQueue();
        foreach ($seeds as $seed) {
            $assign[$seed['y']][$seed['x']] = (string)$seed['pid'];
            $dist[$seed['y']][$seed['x']] = 0;
            $queue->enqueue([$seed['x'], $seed['y'], (string)$seed['pid']]);
        }

        while (!$queue->isEmpty()) {
            [$x, $y, $pid] = $queue->dequeue();
            $d = $dist[$y][$x];
            if ($d >= $radius) {
                continue;
            }
            foreach ($this->neighbors4($x, $y, $w, $h) as [$nx, $ny]) {
                if (!$sea[$ny][$nx]) {
                    continue;
                }
                $nd = $d + 1;
                if ($nd < $dist[$ny][$nx]) {
                    $dist[$ny][$nx] = $nd;
                    $assign[$ny][$nx] = $pid;
                    $queue->enqueue([$nx, $ny, $pid]);
                } elseif ($nd === $dist[$ny][$nx] && $assign[$ny][$nx] !== $pid) {
                    $assign[$ny][$nx] = null;
                }
            }
        }

        return [
            'assign' => $assign,
            'dist' => $dist,
            'coastal_provinces' => array_values(array_map('strval', array_keys($coastal))),
            'meta' => [
                'coastal_province_count' => count($coastal),
                'territorial_radius_cells' => $radius,
            ],
        ];
    }

    private function buildNeutralSeaProvinces(array $seaData, array $territorial, array $cfg): array
    {
        $sea = $seaData['mask'];
        $h = count($sea);
        $w = $h ? count($sea[0]) : 0;
        $free = array_fill(0, $h, array_fill(0, $w, false));
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $free[$y][$x] = $sea[$y][$x] && ($territorial['assign'][$y][$x] === null);
            }
        }

        $components = $this->connectedComponents($free);
        $targetArea = max(1, (int)$cfg['target_neutral_area_cells']);
        $minArea = max(1, (int)$cfg['min_neutral_area_cells']);
        $maxSeeds = max(1, (int)$cfg['max_neutral_seeds_per_component']);

        $assign = array_fill(0, $h, array_fill(0, $w, null));
        $meta = [];
        foreach ($components as $componentIndex => $component) {
            if (($component['area'] ?? 0) < $minArea) {
                $seedId = 'NW:' . ($componentIndex + 1) . ':1';
                foreach ($component['cells'] as [$x, $y]) {
                    $assign[$y][$x] = $seedId;
                }
                $meta[$seedId] = ['component' => $componentIndex + 1, 'seed_index' => 1, 'seed' => $component['cells'][0], 'area' => $component['area']];
                continue;
            }
            $seedCount = (int)ceil($component['area'] / $targetArea);
            $seedCount = max(1, min($maxSeeds, $seedCount));
            $seeds = $this->pickComponentSeeds($component['cells'], $seedCount);
            $queue = new SplQueue();
            $dist = [];
            foreach ($seeds as $seedIndex => [$sx, $sy]) {
                $sid = 'NW:' . ($componentIndex + 1) . ':' . ($seedIndex + 1);
                $queue->enqueue([$sx, $sy, $sid]);
                $assign[$sy][$sx] = $sid;
                $dist[$sy . ':' . $sx] = 0;
                $meta[$sid] = ['component' => $componentIndex + 1, 'seed_index' => $seedIndex + 1, 'seed' => [$sx, $sy], 'area' => 0];
            }
            $cellSet = [];
            foreach ($component['cells'] as [$cx, $cy]) {
                $cellSet[$cy . ':' . $cx] = true;
            }
            while (!$queue->isEmpty()) {
                [$x, $y, $sid] = $queue->dequeue();
                $d = $dist[$y . ':' . $x] ?? 0;
                foreach ($this->neighbors4($x, $y, $w, $h) as [$nx, $ny]) {
                    $key = $ny . ':' . $nx;
                    if (!isset($cellSet[$key])) {
                        continue;
                    }
                    if (!isset($dist[$key])) {
                        $dist[$key] = $d + 1;
                        $assign[$ny][$nx] = $sid;
                        $queue->enqueue([$nx, $ny, $sid]);
                    }
                }
            }
        }

        return [
            'assign' => $assign,
            'meta' => $meta,
            'component_count' => count($components),
        ];
    }

    private function composeMarineGrid(array $seaData, array $territorial, array $neutral): array
    {
        $sea = $seaData['mask'];
        $h = count($sea);
        $w = $h ? count($sea[0]) : 0;
        $grid = array_fill(0, $h, array_fill(0, $w, null));
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (!$sea[$y][$x]) {
                    continue;
                }
                $owner = $territorial['assign'][$y][$x];
                if ($owner !== null && $owner !== '') {
                    $grid[$y][$x] = 'TS:' . $owner;
                } else {
                    $grid[$y][$x] = $neutral['assign'][$y][$x];
                }
            }
        }
        return $grid;
    }

    private function buildMarineProvinceRecords(array $marineGrid, array $provinceData, array $cfg): array
    {
        $h = count($marineGrid);
        $w = $h ? count($marineGrid[0]) : 0;
        $records = [];
        $labels = $this->readJson($this->sidecarDir . '/sea_labels.json', []);
        $ports = $this->readJson($this->sidecarDir . '/ports.json', []);
        $portByProvince = [];
        foreach ((array)$ports as $port) {
            if (is_array($port) && isset($port['province_id'])) {
                $portByProvince[(string)$port['province_id']] = $port;
            }
        }
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $id = $marineGrid[$y][$x];
                if ($id === null) {
                    continue;
                }
                if (!isset($records[$id])) {
                    $records[$id] = [
                        'id' => $id,
                        'kind' => str_starts_with($id, 'TS:') ? 'territorial' : 'neutral',
                        'owner_province_id' => str_starts_with($id, 'TS:') ? substr($id, 3) : null,
                        'owner_province_name' => str_starts_with($id, 'TS:') ? ($provinceData['province_names'][substr($id, 3)] ?? substr($id, 3)) : null,
                        'name' => $labels[$id]['name'] ?? (str_starts_with($id, 'TS:') ? 'Территориальные воды ' . ($provinceData['province_names'][substr($id, 3)] ?? substr($id, 3)) : 'Нейтральные воды ' . $id),
                        'area_cells' => 0,
                        'sum_x' => 0.0,
                        'sum_y' => 0.0,
                        'bbox' => ['min_x' => $x, 'min_y' => $y, 'max_x' => $x, 'max_y' => $y],
                        'adjacent' => [],
                        'battle_theatre_id' => $id,
                        'port_enabled' => str_starts_with($id, 'TS:') ? (isset($portByProvince[substr($id, 3)]) ? (bool)($portByProvince[substr($id, 3)]['enabled'] ?? true) : true) : false,
                    ];
                }
                $records[$id]['area_cells']++;
                $records[$id]['sum_x'] += $x;
                $records[$id]['sum_y'] += $y;
                $records[$id]['bbox']['min_x'] = min($records[$id]['bbox']['min_x'], $x);
                $records[$id]['bbox']['min_y'] = min($records[$id]['bbox']['min_y'], $y);
                $records[$id]['bbox']['max_x'] = max($records[$id]['bbox']['max_x'], $x);
                $records[$id]['bbox']['max_y'] = max($records[$id]['bbox']['max_y'], $y);
            }
        }
        foreach ($records as $id => &$row) {
            $row['centroid'] = [
                'x' => $row['sum_x'] / max(1, $row['area_cells']),
                'y' => $row['sum_y'] / max(1, $row['area_cells']),
            ];
            unset($row['sum_x'], $row['sum_y']);
        }
        unset($row);
        return $records;
    }

    private function buildMarineGraph(array $marineGrid, array $marineProvinces, array $provinceData, array $cfg): array
    {
        $h = count($marineGrid);
        $w = $h ? count($marineGrid[0]) : 0;
        $nodes = [];
        $adjacency = [];

        foreach ($marineProvinces as $mid => $item) {
            $nodeId = 'MP:' . $mid;
            $nodes[$nodeId] = [
                'id' => $nodeId,
                'kind' => 'marine',
                'marine_id' => $mid,
                'marine_kind' => $item['kind'],
                'name' => $item['name'],
                'owner_province_id' => $item['owner_province_id'],
                'battle_theatre_id' => $item['battle_theatre_id'],
            ];
        }

        foreach ($marineProvinces as $mid => $item) {
            if ($item['kind'] === 'territorial' && !empty($item['port_enabled']) && $item['owner_province_id'] !== null) {
                $pid = (string)$item['owner_province_id'];
                $landNode = 'LP:' . $pid;
                $nodes[$landNode] = [
                    'id' => $landNode,
                    'kind' => 'land_port',
                    'province_id' => $pid,
                    'name' => $provinceData['province_names'][$pid] ?? $pid,
                ];
                $this->addEdge($adjacency, $landNode, 'MP:' . $mid, 1.0, 'port_exit');
                $this->addEdge($adjacency, 'MP:' . $mid, $landNode, 1.0, 'port_exit');
            }
        }

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $a = $marineGrid[$y][$x];
                if ($a === null) {
                    continue;
                }
                foreach ([[1,0],[0,1]] as [$dx,$dy]) {
                    $nx = $x + $dx; $ny = $y + $dy;
                    if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                        continue;
                    }
                    $b = $marineGrid[$ny][$nx];
                    if ($b === null || $a === $b) {
                        continue;
                    }
                    $aNode = 'MP:' . $a;
                    $bNode = 'MP:' . $b;
                    $class = (str_starts_with($a, 'TS:') || str_starts_with($b, 'TS:')) ? 'territorial' : 'neutral';
                    if (str_starts_with($a, 'TS:') && str_starts_with($b, 'TS:')) {
                        $class = 'strait';
                    }
                    $this->addEdge($adjacency, $aNode, $bNode, 1.0, $class);
                    $this->addEdge($adjacency, $bNode, $aNode, 1.0, $class);
                }
            }
        }

        return [
            'nodes' => $nodes,
            'adjacency' => $adjacency,
        ];
    }

    private function buildSummary(array $provinceData, array $seaData, array $territorial, array $marineProvinces, array $graph): array
    {
        $territorialCount = 0;
        $neutralCount = 0;
        foreach ($marineProvinces as $row) {
            if (($row['kind'] ?? '') === 'territorial') {
                $territorialCount++;
            } else {
                $neutralCount++;
            }
        }
        $coastalPorts = 0;
        foreach ($graph['nodes'] as $node) {
            if (($node['kind'] ?? '') === 'land_port') {
                $coastalPorts++;
            }
        }
        return [
            'generated_at' => gmdate('c'),
            'province_count' => $provinceData['meta']['province_count'] ?? 0,
            'marine_cells' => $seaData['meta']['marine_cells'] ?? 0,
            'coastal_province_count' => $territorial['meta']['coastal_province_count'] ?? 0,
            'territorial_marine_province_count' => $territorialCount,
            'neutral_marine_province_count' => $neutralCount,
            'land_port_node_count' => $coastalPorts,
            'marine_node_count' => count($marineProvinces),
            'graph_node_count' => count($graph['nodes'] ?? []),
            'graph_edge_bucket_count' => count($graph['adjacency'] ?? []),
        ];
    }

    private function resolveRouteNode(string $value, array $nodes): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Empty route endpoint');
        }
        if (isset($nodes[$value])) {
            return $value;
        }
        $land = 'LP:' . $value;
        if (isset($nodes[$land])) {
            return $land;
        }
        $marine = 'MP:' . $value;
        if (isset($nodes[$marine])) {
            return $marine;
        }
        throw new InvalidArgumentException('Unknown route node: ' . $value);
    }

    private function addEdge(array &$adjacency, string $from, string $to, float $cost, string $class): void
    {
        if (!isset($adjacency[$from])) {
            $adjacency[$from] = [];
        }
        foreach ($adjacency[$from] as &$edge) {
            if (($edge['to'] ?? null) === $to) {
                $edge['cost'] = min((float)$edge['cost'], $cost);
                return;
            }
        }
        unset($edge);
        $adjacency[$from][] = ['to' => $to, 'cost' => $cost, 'class' => $class];
    }

    private function dijkstra(array $nodes, array $adjacency, string $start, string $goal, callable $edgeCost): array
    {
        $dist = [];
        $prev = [];
        foreach ($nodes as $id => $_) {
            $dist[$id] = INF;
        }
        $dist[$start] = 0.0;
        $queue = new SplPriorityQueue();
        $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $queue->insert($start, 0.0);
        while (!$queue->isEmpty()) {
            $current = $queue->extract();
            $u = $current['data'];
            $prio = -$current['priority'];
            if ($prio > $dist[$u]) {
                continue;
            }
            if ($u === $goal) {
                break;
            }
            foreach ($adjacency[$u] ?? [] as $edge) {
                $w = $edgeCost($edge);
                if ($w === null) {
                    continue;
                }
                $v = (string)$edge['to'];
                $alt = $dist[$u] + $w;
                if ($alt < ($dist[$v] ?? INF)) {
                    $dist[$v] = $alt;
                    $prev[$v] = $u;
                    $queue->insert($v, -$alt);
                }
            }
        }
        if (!isset($dist[$goal]) || !is_finite($dist[$goal])) {
            return ['path' => [], 'distance' => null];
        }
        $path = [$goal];
        $cursor = $goal;
        while (isset($prev[$cursor])) {
            $cursor = $prev[$cursor];
            array_unshift($path, $cursor);
            if ($cursor === $start) {
                break;
            }
        }
        return ['path' => $path, 'distance' => $dist[$goal]];
    }

    private function connectedComponents(array $mask): array
    {
        $h = count($mask);
        $w = $h ? count($mask[0]) : 0;
        $seen = array_fill(0, $h, array_fill(0, $w, false));
        $components = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (empty($mask[$y][$x]) || !empty($seen[$y][$x])) {
                    continue;
                }
                $queue = new SplQueue();
                $queue->enqueue([$x, $y]);
                $seen[$y][$x] = true;
                $cells = [];
                while (!$queue->isEmpty()) {
                    [$cx, $cy] = $queue->dequeue();
                    $cells[] = [$cx, $cy];
                    foreach ($this->neighbors4($cx, $cy, $w, $h) as [$nx, $ny]) {
                        if (empty($mask[$ny][$nx]) || !empty($seen[$ny][$nx])) {
                            continue;
                        }
                        $seen[$ny][$nx] = true;
                        $queue->enqueue([$nx, $ny]);
                    }
                }
                $components[] = ['area' => count($cells), 'cells' => $cells];
            }
        }
        usort($components, static fn(array $a, array $b): int => ($b['area'] <=> $a['area']));
        return $components;
    }

    private function findBorderConnectedWater(array $mask, int $minSize): array
    {
        $h = count($mask);
        $w = $h ? count($mask[0]) : 0;
        $seen = array_fill(0, $h, array_fill(0, $w, false));
        $keep = array_fill(0, $h, array_fill(0, $w, false));
        $components = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (empty($mask[$y][$x]) || !empty($seen[$y][$x])) {
                    continue;
                }
                $queue = new SplQueue();
                $queue->enqueue([$x, $y]);
                $seen[$y][$x] = true;
                $cells = [];
                $touchesBorder = false;
                while (!$queue->isEmpty()) {
                    [$cx, $cy] = $queue->dequeue();
                    $cells[] = [$cx, $cy];
                    if ($cx === 0 || $cy === 0 || $cx === $w - 1 || $cy === $h - 1) {
                        $touchesBorder = true;
                    }
                    foreach ($this->neighbors4($cx, $cy, $w, $h) as [$nx, $ny]) {
                        if (empty($mask[$ny][$nx]) || !empty($seen[$ny][$nx])) {
                            continue;
                        }
                        $seen[$ny][$nx] = true;
                        $queue->enqueue([$nx, $ny]);
                    }
                }
                if ($touchesBorder && count($cells) >= $minSize) {
                    foreach ($cells as [$cx, $cy]) {
                        $keep[$cy][$cx] = true;
                    }
                    $components[] = ['area' => count($cells), 'cells' => $cells];
                }
            }
        }
        return ['mask' => $keep, 'components' => $components];
    }

    private function pruneToOpenSea(array $mask, array $cfg): array
    {
        $h = count($mask);
        $w = $h ? count($mask[0]) : 0;
        $radius = max(1, (int)($cfg['sea_detect']['open_sea_neighbor_radius'] ?? 2));
        $threshold = max(1, (int)($cfg['sea_detect']['open_sea_neighbor_threshold'] ?? 7));
        $borderMargin = max(0, (int)($cfg['sea_detect']['keep_border_margin_cells'] ?? 2));
        $open = array_fill(0, $h, array_fill(0, $w, false));
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (empty($mask[$y][$x])) {
                    continue;
                }
                if ($x <= $borderMargin || $y <= $borderMargin || $x >= $w - 1 - $borderMargin || $y >= $h - 1 - $borderMargin) {
                    $open[$y][$x] = true;
                    continue;
                }
                $count = 0;
                for ($dy = -$radius; $dy <= $radius; $dy++) {
                    for ($dx = -$radius; $dx <= $radius; $dx++) {
                        $nx = $x + $dx; $ny = $y + $dy;
                        if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                            continue;
                        }
                        if (!empty($mask[$ny][$nx])) {
                            $count++;
                        }
                    }
                }
                if ($count >= $threshold) {
                    $open[$y][$x] = true;
                }
            }
        }
        $steps = max(0, (int)($cfg['coast_dilation_steps'] ?? 1));
        for ($i = 0; $i < $steps; $i++) {
            $next = $open;
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    if (!empty($open[$y][$x])) {
                        continue;
                    }
                    if (empty($mask[$y][$x])) {
                        continue;
                    }
                    foreach ($this->neighbors4($x, $y, $w, $h) as [$nx, $ny]) {
                        if (!empty($open[$ny][$nx])) {
                            $next[$y][$x] = true;
                            break;
                        }
                    }
                }
            }
            $open = $next;
        }
        return $open;
    }

    private function pickComponentSeeds(array $cells, int $count): array
    {
        if ($count <= 1 || count($cells) <= 1) {
            return [$cells[(int)floor(count($cells) / 2)]];
        }
        $seeds = [$cells[0]];
        while (count($seeds) < $count) {
            $bestCell = null;
            $bestScore = -1;
            foreach ($cells as $cell) {
                $minDist = PHP_INT_MAX;
                foreach ($seeds as $seed) {
                    $d = abs($cell[0] - $seed[0]) + abs($cell[1] - $seed[1]);
                    if ($d < $minDist) {
                        $minDist = $d;
                    }
                }
                if ($minDist > $bestScore) {
                    $bestScore = $minDist;
                    $bestCell = $cell;
                }
            }
            if ($bestCell === null) {
                break;
            }
            $seeds[] = $bestCell;
        }
        return $seeds;
    }

    private function isSeaPixel(array $rgb, array $cfg): bool
    {
        $r = (int)$rgb['r']; $g = (int)$rgb['g']; $b = (int)$rgb['b'];
        $blueMin = (int)($cfg['blue_min'] ?? 72);
        $blueGreenBias = (int)($cfg['blue_green_bias'] ?? -10);
        $blueRedDiffMin = (int)($cfg['blue_red_diff_min'] ?? 18);
        $valueMin = (int)($cfg['value_min'] ?? 35);
        $v = max($r, $g, $b);
        if ($v < $valueMin) {
            return false;
        }
        if ($b < $blueMin) {
            return false;
        }
        if (($b - $r) < $blueRedDiffMin) {
            return false;
        }
        if (($b - $g) < $blueGreenBias) {
            return false;
        }
        return true;
    }

    private function loadProvinceRecords(): array
    {
        $candidates = [
            $this->rootDir . '/provinces.json',
            $this->rootDir . '/data/map_state.json',
        ];
        $records = [];
        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }
            $data = $this->readJson($path, []);
            $list = [];
            if (isset($data['provinces']) && is_array($data['provinces'])) {
                $list = $data['provinces'];
            } elseif (is_array($data)) {
                $list = $data;
            }
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = $item['id'] ?? $item['province_id'] ?? $item['pid'] ?? null;
                if ($id === null) {
                    continue;
                }
                $name = $item['name'] ?? $item['title'] ?? $item['label'] ?? ('Province ' . $id);
                $records[(string)$id] = ['id' => (string)$id, 'name' => (string)$name];
            }
            if ($records) {
                break;
            }
        }
        return $records;
    }

    private function resolveProvinceColors(array $colors, array $records): array
    {
        $manual = $this->readJson($this->sidecarDir . '/province_color_map.json', []);
        $resolved = [];
        $unresolved = [];
        foreach ($colors as $hex => $_) {
            $m = $manual[$hex] ?? null;
            if (is_array($m) && isset($m['id'])) {
                $id = (string)$m['id'];
                $resolved[$hex] = [
                    'id' => $id,
                    'name' => (string)($m['name'] ?? ($records[$id]['name'] ?? $id)),
                ];
            } else {
                $unresolved[$hex] = ['hex' => $hex];
            }
        }
        return [$resolved, $unresolved];
    }

    private function writeOverlayPng(array $marineGrid, array $provinceData, string $path): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            return;
        }
        $h = count($marineGrid);
        $w = $h ? count($marineGrid[0]) : 0;
        if ($w === 0 || $h === 0) {
            return;
        }
        $scale = 2;
        $im = imagecreatetruecolor($w * $scale, $h * $scale);
        imagealphablending($im, true);
        imagesavealpha($im, true);
        $bg = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefill($im, 0, 0, $bg);
        $colors = [];
        foreach ($marineGrid as $y => $row) {
            foreach ($row as $x => $id) {
                if ($id === null) {
                    continue;
                }
                if (!isset($colors[$id])) {
                    $hash = abs(crc32($id));
                    $r = 60 + ($hash % 160);
                    $g = 60 + (($hash >> 8) % 160);
                    $b = 90 + (($hash >> 16) % 150);
                    if (str_starts_with($id, 'TS:')) {
                        $r = min(255, $r + 35);
                    }
                    $colors[$id] = imagecolorallocatealpha($im, $r, $g, $b, 28);
                }
                imagefilledrectangle($im, $x * $scale, $y * $scale, ($x + 1) * $scale - 1, ($y + 1) * $scale - 1, $colors[$id]);
            }
        }
        imagepng($im, $path);
        imagedestroy($im);
    }

    private function countTrue(array $mask): int
    {
        $n = 0;
        foreach ($mask as $row) {
            foreach ($row as $v) {
                if ($v) {
                    $n++;
                }
            }
        }
        return $n;
    }

    private function neighbors4(int $x, int $y, int $w, int $h): array
    {
        $out = [];
        foreach ([[1,0],[-1,0],[0,1],[0,-1]] as [$dx,$dy]) {
            $nx = $x + $dx; $ny = $y + $dy;
            if ($nx >= 0 && $ny >= 0 && $nx < $w && $ny < $h) {
                $out[] = [$nx, $ny];
            }
        }
        return $out;
    }

    private function pairKey(string $a, string $b): string
    {
        return strcmp($a, $b) < 0 ? ($a . '|' . $b) : ($b . '|' . $a);
    }

    private function loadPng(string $path)
    {
        if (!function_exists('imagecreatefrompng')) {
            throw new RuntimeException('PHP GD extension is required for marine_provinces module. Install php-gd/php8.x-gd.');
        }
        $im = @imagecreatefrompng($path);
        if (!$im) {
            throw new RuntimeException('Failed to load PNG: ' . $path);
        }
        return $im;
    }

    private function rgbaToRgb(int $rgba): array
    {
        return [
            'r' => ($rgba >> 16) & 0xFF,
            'g' => ($rgba >> 8) & 0xFF,
            'b' => $rgba & 0xFF,
        ];
    }

    private function rgbToHex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', $rgb['r'], $rgb['g'], $rgb['b']);
    }

    private function readJson(string $path, mixed $default): mixed
    {
        if (!is_file($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $data = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : $default;
    }

    private function writeJson(string $path, mixed $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
