<?php

declare(strict_types=1);

final class RiverLogisticsModule
{
    private string $projectRoot;
    private string $moduleRoot;
    private string $sidecarRoot;
    private string $runtimeRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->moduleRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
        $this->projectRoot = $projectRoot
            ? rtrim($projectRoot, DIRECTORY_SEPARATOR)
            : (realpath($this->moduleRoot . '/../../..') ?: dirname($this->moduleRoot, 3));
        $this->sidecarRoot = $this->moduleRoot . '/data/sidecar';
        $this->runtimeRoot = $this->projectRoot . '/data/module_runtime/river_logistics';
    }

    public function run(bool $dryRun = false): array
    {
        $this->ensureGd();
        $this->ensureDir($this->runtimeRoot);

        [$provinceMap, $adjacency, $unresolvedColors] = $this->buildProvinceRasterInfo();
        $routesConfig = $this->loadJson($this->sidecarRoot . '/river_routes.json', ['routes' => []]);
        $cal = $this->loadJson($this->sidecarRoot . '/river_calibration.json', []);
        $staple = $this->loadJson($this->sidecarRoot . '/staple_rights_river.json', ['province_ids' => [], 'port_ids' => []]);

        $routesResult = $this->analyzeRoutes($provinceMap, $routesConfig['routes'] ?? [], $cal, $staple);
        $graph = $this->buildGraph($provinceMap, $adjacency, $routesResult, $cal, $staple);
        $summary = $this->buildSummary($provinceMap, $adjacency, $routesResult, $graph, $unresolvedColors);

        if (!$dryRun) {
            $this->saveJson($this->runtimeRoot . '/network.json', [
                'generated_at' => gmdate('c'),
                'province_map' => $provinceMap,
                'adjacency' => $adjacency,
                'routes' => $routesResult,
                'graph' => $graph,
            ]);
            $this->saveJson($this->runtimeRoot . '/summary.json', $summary);
            $this->saveJson($this->runtimeRoot . '/unresolved_colors.json', $unresolvedColors);
            $this->saveJson($this->runtimeRoot . '/preview_routes.json', [
                'routes' => array_values(array_map(static fn(array $r) => [
                    'id' => $r['id'],
                    'name' => $r['name'],
                    'province_sequence' => $r['province_sequence'],
                    'ports' => $r['ports'],
                ], $routesResult['routes'])),
            ]);
        }

        return [
            'ok' => true,
            'dry_run' => $dryRun,
            'summary' => $summary,
            'runtime_root' => $this->runtimeRoot,
        ];
    }

    public function findRoute(string $fromProvinceId, string $toProvinceId, string $mode = 'cargo'): array
    {
        $network = $this->loadJson($this->runtimeRoot . '/network.json', []);
        if (!$network) {
            throw new RuntimeException('network.json not found; run the module first');
        }
        $graph = $network['graph'] ?? [];
        $provinceMap = $network['province_map'] ?? [];
        $from = 'province:' . $fromProvinceId;
        $to = 'province:' . $toProvinceId;
        if (!isset($graph['nodes'][$from])) {
            throw new RuntimeException('Unknown from province id: ' . $fromProvinceId);
        }
        if (!isset($graph['nodes'][$to])) {
            throw new RuntimeException('Unknown to province id: ' . $toProvinceId);
        }
        return $this->dijkstra($graph, $from, $to, $mode, $provinceMap);
    }

    public function loadRoutesConfig(): array
    {
        return $this->loadJson($this->sidecarRoot . '/river_routes.json', ['routes' => []]);
    }

    public function saveRoutesConfig(array $payload): array
    {
        $routes = $payload['routes'] ?? [];
        if (!is_array($routes)) {
            throw new InvalidArgumentException('routes must be an array');
        }
        $normalized = ['routes' => []];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $normalized['routes'][] = [
                'id' => (string)($route['id'] ?? ('river_' . uniqid())),
                'name' => (string)($route['name'] ?? 'River'),
                'navigable' => (bool)($route['navigable'] ?? true),
                'cargo_class' => (string)($route['cargo_class'] ?? 'major'),
                'direction' => (string)($route['direction'] ?? 'forward'),
                'polyline' => array_values(array_map(static function ($p): array {
                    return [intval($p[0] ?? 0), intval($p[1] ?? 0)];
                }, is_array($route['polyline'] ?? null) ? $route['polyline'] : [])),
                'ports' => array_values(array_map(static function ($p): array {
                    return [
                        'id' => (string)($p['id'] ?? ('port_' . uniqid())),
                        'name' => (string)($p['name'] ?? 'Port'),
                        'point' => [intval($p['point'][0] ?? 0), intval($p['point'][1] ?? 0)],
                        'kind' => (string)($p['kind'] ?? 'port'),
                    ];
                }, is_array($route['ports'] ?? null) ? $route['ports'] : [])),
            ];
        }
        $this->saveJson($this->sidecarRoot . '/river_routes.json', $normalized);
        return ['ok' => true, 'saved_routes' => count($normalized['routes'])];
    }

    public function cleanup(): array
    {
        if (!is_dir($this->runtimeRoot)) {
            return ['ok' => true, 'removed' => []];
        }
        $removed = [];
        $files = scandir($this->runtimeRoot) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $this->runtimeRoot . '/' . $file;
            if (is_file($path)) {
                unlink($path);
                $removed[] = $path;
            }
        }
        return ['ok' => true, 'removed' => $removed];
    }

    private function ensureGd(): void
    {
        if (!function_exists('imagecreatefrompng')) {
            throw new RuntimeException('php-gd is required: imagecreatefrompng() is not available');
        }
    }

    private function buildProvinceRasterInfo(): array
    {
        $provincePng = $this->projectRoot . '/provinces_id.png';
        if (!is_file($provincePng)) {
            throw new RuntimeException('provinces_id.png not found at ' . $provincePng);
        }
        $img = imagecreatefrompng($provincePng);
        if (!$img) {
            throw new RuntimeException('Failed to open provinces_id.png');
        }

        $colorMap = $this->loadJson($this->sidecarRoot . '/province_color_map.json', []);
        $width = imagesx($img);
        $height = imagesy($img);
        $raw = [];
        $adjacency = [];
        $unresolved = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $key = sprintf('%02x%02x%02x', $r, $g, $b);
                if ($key === '000000') {
                    continue;
                }
                $pid = isset($colorMap[$key]) ? (string)$colorMap[$key] : ('color:' . $key);
                if (!isset($raw[$pid])) {
                    $raw[$pid] = [
                        'id' => $pid,
                        'source_color' => $key,
                        'pixel_count' => 0,
                        'sum_x' => 0,
                        'sum_y' => 0,
                        'min_x' => $x,
                        'min_y' => $y,
                        'max_x' => $x,
                        'max_y' => $y,
                        'is_unresolved_color' => !isset($colorMap[$key]),
                    ];
                    if (!isset($colorMap[$key])) {
                        $unresolved[$key] = [
                            'color_key' => $key,
                            'suggested_id' => 'color:' . $key,
                        ];
                    }
                }
                $raw[$pid]['pixel_count']++;
                $raw[$pid]['sum_x'] += $x;
                $raw[$pid]['sum_y'] += $y;
                $raw[$pid]['min_x'] = min($raw[$pid]['min_x'], $x);
                $raw[$pid]['min_y'] = min($raw[$pid]['min_y'], $y);
                $raw[$pid]['max_x'] = max($raw[$pid]['max_x'], $x);
                $raw[$pid]['max_y'] = max($raw[$pid]['max_y'], $y);

                if ($x + 1 < $width) {
                    $pid2 = $this->provinceIdAt($img, $x + 1, $y, $colorMap);
                    if ($pid2 && $pid2 !== $pid) {
                        $adjacency[$pid][$pid2] = true;
                        $adjacency[$pid2][$pid] = true;
                    }
                }
                if ($y + 1 < $height) {
                    $pid2 = $this->provinceIdAt($img, $x, $y + 1, $colorMap);
                    if ($pid2 && $pid2 !== $pid) {
                        $adjacency[$pid][$pid2] = true;
                        $adjacency[$pid2][$pid] = true;
                    }
                }
            }
        }
        imagedestroy($img);

        $provinceMap = [];
        foreach ($raw as $pid => $info) {
            $count = max(1, (int)$info['pixel_count']);
            $provinceMap[$pid] = [
                'id' => $pid,
                'source_color' => $info['source_color'],
                'pixel_count' => $count,
                'centroid' => [
                    round($info['sum_x'] / $count, 2),
                    round($info['sum_y'] / $count, 2),
                ],
                'bbox' => [
                    $info['min_x'],
                    $info['min_y'],
                    $info['max_x'],
                    $info['max_y'],
                ],
                'neighbors' => array_values(array_keys($adjacency[$pid] ?? [])),
                'is_unresolved_color' => (bool)$info['is_unresolved_color'],
            ];
        }
        $adjacency = array_map(static fn(array $set): array => array_values(array_keys($set)), $adjacency);

        return [$provinceMap, $adjacency, array_values($unresolved)];
    }

    private function analyzeRoutes(array $provinceMap, array $routes, array $cal, array $staple): array
    {
        $provincePng = $this->projectRoot . '/provinces_id.png';
        $colorMap = $this->loadJson($this->sidecarRoot . '/province_color_map.json', []);
        $img = imagecreatefrompng($provincePng);
        if (!$img) {
            throw new RuntimeException('Failed to open provinces_id.png');
        }
        $sampleStep = max(1, (int)($cal['sample_step_px'] ?? 6));
        $radius = max(0, (int)($cal['river_touch_radius_px'] ?? 3));
        $junctionSnap = max(1, (int)($cal['junction_snap_px'] ?? 10));

        $resultRoutes = [];
        foreach ($routes as $route) {
            $polyline = is_array($route['polyline'] ?? null) ? $route['polyline'] : [];
            if (count($polyline) < 2) {
                continue;
            }
            $samples = $this->samplePolyline($polyline, $sampleStep);
            $touchStats = [];
            foreach ($samples as $index => $point) {
                $pids = $this->sampleProvinceNeighborhood($img, (int)$point[0], (int)$point[1], $radius, $colorMap);
                foreach ($pids as $pid) {
                    if (!isset($touchStats[$pid])) {
                        $touchStats[$pid] = [
                            'count' => 0,
                            'first_sample' => $index,
                            'last_sample' => $index,
                            'positions' => [],
                        ];
                    }
                    $touchStats[$pid]['count']++;
                    $touchStats[$pid]['first_sample'] = min($touchStats[$pid]['first_sample'], $index);
                    $touchStats[$pid]['last_sample'] = max($touchStats[$pid]['last_sample'], $index);
                    $touchStats[$pid]['positions'][] = $point;
                }
            }
            uasort($touchStats, static function (array $a, array $b): int {
                return $a['first_sample'] <=> $b['first_sample'];
            });

            $provinceSequence = [];
            foreach ($touchStats as $pid => $stat) {
                $provinceSequence[] = [
                    'province_id' => $pid,
                    'first_sample' => $stat['first_sample'],
                    'last_sample' => $stat['last_sample'],
                    'touch_count' => $stat['count'],
                ];
            }

            $ports = [];
            foreach ((array)($route['ports'] ?? []) as $port) {
                $pt = $port['point'] ?? [0, 0];
                $pids = $this->sampleProvinceNeighborhood($img, (int)($pt[0] ?? 0), (int)($pt[1] ?? 0), $radius + 1, $colorMap);
                $provinceId = $pids[0] ?? null;
                $sampleOrder = $this->nearestSampleOrder($samples, [(int)($pt[0] ?? 0), (int)($pt[1] ?? 0)]);
                $ports[] = [
                    'id' => (string)($port['id'] ?? ('port_' . uniqid())),
                    'name' => (string)($port['name'] ?? 'Port'),
                    'kind' => (string)($port['kind'] ?? 'port'),
                    'point' => [intval($pt[0] ?? 0), intval($pt[1] ?? 0)],
                    'province_id' => $provinceId,
                    'sample_order' => $sampleOrder,
                    'staple_right' => in_array((string)($port['id'] ?? ''), (array)($staple['port_ids'] ?? []), true),
                ];
            }

            $resultRoutes[] = [
                'id' => (string)($route['id'] ?? ('river_' . uniqid())),
                'name' => (string)($route['name'] ?? 'River'),
                'navigable' => (bool)($route['navigable'] ?? true),
                'cargo_class' => (string)($route['cargo_class'] ?? 'major'),
                'direction' => (string)($route['direction'] ?? 'forward'),
                'polyline' => array_values($polyline),
                'samples' => $samples,
                'province_sequence' => $provinceSequence,
                'ports' => $ports,
                'length_px' => round($this->polylineLength($polyline), 2),
            ];
        }
        imagedestroy($img);

        $junctions = [];
        for ($i = 0, $n = count($resultRoutes); $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $resultRoutes[$i];
                $b = $resultRoutes[$j];
                foreach ($a['samples'] as $ia => $pa) {
                    foreach ($b['samples'] as $ib => $pb) {
                        if ($this->distance($pa, $pb) <= $junctionSnap) {
                            $junctions[] = [
                                'route_a' => $a['id'],
                                'sample_a' => $ia,
                                'route_b' => $b['id'],
                                'sample_b' => $ib,
                                'point' => [
                                    intval(round(($pa[0] + $pb[0]) / 2)),
                                    intval(round(($pa[1] + $pb[1]) / 2)),
                                ],
                            ];
                            break 2;
                        }
                    }
                }
            }
        }

        return ['routes' => $resultRoutes, 'junctions' => $junctions];
    }

    private function buildGraph(array $provinceMap, array $adjacency, array $routesResult, array $cal, array $staple): array
    {
        $nodes = [];
        $edges = [];

        foreach ($provinceMap as $pid => $province) {
            $nodes['province:' . $pid] = [
                'id' => 'province:' . $pid,
                'type' => 'province',
                'province_id' => $pid,
                'label' => $pid,
                'centroid' => $province['centroid'],
            ];
        }

        foreach ($adjacency as $pid => $neighbors) {
            foreach ($neighbors as $neighborPid) {
                if (!isset($provinceMap[$pid], $provinceMap[$neighborPid])) {
                    continue;
                }
                $distance = $this->distance($provinceMap[$pid]['centroid'], $provinceMap[$neighborPid]['centroid']);
                $this->addEdge($edges, 'province:' . $pid, 'province:' . $neighborPid, [
                    'kind' => 'land',
                    'distance' => $distance,
                ]);
                $this->addEdge($edges, 'province:' . $neighborPid, 'province:' . $pid, [
                    'kind' => 'land',
                    'distance' => $distance,
                ]);
            }
        }

        $stapleProvinceSet = array_fill_keys(array_map('strval', (array)($staple['province_ids'] ?? [])), true);
        $routeProvinceNodeMap = [];

        foreach ($routesResult['routes'] as $route) {
            $sequence = $route['province_sequence'];
            $prevNode = null;
            $prevSample = null;
            foreach ($sequence as $entry) {
                $pid = (string)$entry['province_id'];
                $nodeId = 'river_access:' . $route['id'] . ':' . $pid;
                $routeProvinceNodeMap[$route['id']][$pid] = $nodeId;
                $nodes[$nodeId] = [
                    'id' => $nodeId,
                    'type' => 'river_access',
                    'province_id' => $pid,
                    'route_id' => $route['id'],
                    'first_sample' => $entry['first_sample'],
                    'last_sample' => $entry['last_sample'],
                    'staple_right' => isset($stapleProvinceSet[$pid]),
                ];

                $embarkPenalty = (float)($cal['embark_penalty'] ?? 18);
                $disembarkPenalty = (float)($cal['disembark_penalty'] ?? 18);
                $this->addEdge($edges, 'province:' . $pid, $nodeId, [
                    'kind' => 'embark',
                    'distance' => $embarkPenalty,
                ]);
                $this->addEdge($edges, $nodeId, 'province:' . $pid, [
                    'kind' => 'disembark',
                    'distance' => $disembarkPenalty,
                ]);

                if ($prevNode !== null && $prevSample !== null) {
                    $segmentSamples = abs((int)$entry['first_sample'] - (int)$prevSample);
                    $distance = max(1.0, (float)$segmentSamples);
                    $this->addEdge($edges, $prevNode, $nodeId, [
                        'kind' => 'river',
                        'distance' => $distance,
                        'route_id' => $route['id'],
                        'direction' => $route['direction'],
                        'target_staple_right' => isset($stapleProvinceSet[$pid]),
                    ]);
                    $this->addEdge($edges, $nodeId, $prevNode, [
                        'kind' => 'river',
                        'distance' => $distance,
                        'route_id' => $route['id'],
                        'direction' => $route['direction'] === 'forward' ? 'reverse' : ($route['direction'] === 'reverse' ? 'forward' : 'bidirectional'),
                        'target_staple_right' => isset($stapleProvinceSet[$prevNode ? explode(':', $prevNode)[2] ?? '' : '']),
                    ]);
                }
                $prevNode = $nodeId;
                $prevSample = (int)$entry['first_sample'];
            }

            foreach ($route['ports'] as $port) {
                $portNodeId = 'river_port:' . $route['id'] . ':' . $port['id'];
                $nodes[$portNodeId] = [
                    'id' => $portNodeId,
                    'type' => 'river_port',
                    'province_id' => $port['province_id'],
                    'route_id' => $route['id'],
                    'port_id' => $port['id'],
                    'label' => $port['name'],
                    'point' => $port['point'],
                    'staple_right' => (bool)($port['staple_right'] ?? false),
                ];
                if (!empty($port['province_id']) && isset($routeProvinceNodeMap[$route['id']][$port['province_id']])) {
                    $accessNode = $routeProvinceNodeMap[$route['id']][$port['province_id']];
                    $transferPenalty = (float)($cal['port_transfer_penalty'] ?? 10);
                    $this->addEdge($edges, $accessNode, $portNodeId, [
                        'kind' => 'port_transfer',
                        'distance' => $transferPenalty,
                    ]);
                    $this->addEdge($edges, $portNodeId, $accessNode, [
                        'kind' => 'port_transfer',
                        'distance' => $transferPenalty,
                    ]);
                }
            }
        }

        foreach ($routesResult['junctions'] as $junction) {
            $a = $this->routeAccessNodeAtSample($routesResult['routes'], $junction['route_a'], (int)$junction['sample_a']);
            $b = $this->routeAccessNodeAtSample($routesResult['routes'], $junction['route_b'], (int)$junction['sample_b']);
            if ($a && $b) {
                $this->addEdge($edges, $a, $b, [
                    'kind' => 'junction',
                    'distance' => 4.0,
                ]);
                $this->addEdge($edges, $b, $a, [
                    'kind' => 'junction',
                    'distance' => 4.0,
                ]);
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    private function dijkstra(array $graph, string $start, string $goal, string $mode, array $provinceMap): array
    {
        $nodes = $graph['nodes'] ?? [];
        $edges = $graph['edges'] ?? [];
        $dist = [$start => 0.0];
        $prev = [];
        $queue = [$start => 0.0];

        while ($queue) {
            asort($queue);
            $current = (string)array_key_first($queue);
            $currentCost = $queue[$current];
            unset($queue[$current]);

            if ($current === $goal) {
                break;
            }

            foreach (($edges[$current] ?? []) as $edge) {
                $to = (string)$edge['to'];
                $cost = $currentCost + $this->edgeCost($edge, $mode);
                if (!isset($dist[$to]) || $cost < $dist[$to]) {
                    $dist[$to] = $cost;
                    $prev[$to] = ['node' => $current, 'edge' => $edge];
                    $queue[$to] = $cost;
                }
            }
        }

        if (!isset($dist[$goal])) {
            return ['ok' => false, 'error' => 'No route found'];
        }

        $pathNodes = [];
        $pathEdges = [];
        $cursor = $goal;
        while ($cursor !== $start) {
            $pathNodes[] = $cursor;
            $pathEdges[] = $prev[$cursor]['edge'];
            $cursor = $prev[$cursor]['node'];
        }
        $pathNodes[] = $start;
        $pathNodes = array_reverse($pathNodes);
        $pathEdges = array_reverse($pathEdges);

        $provinces = [];
        foreach ($pathNodes as $nodeId) {
            $node = $nodes[$nodeId] ?? null;
            if (!$node) {
                continue;
            }
            $pid = $node['province_id'] ?? null;
            if ($pid && (!count($provinces) || end($provinces) !== $pid)) {
                $provinces[] = $pid;
            }
        }

        return [
            'ok' => true,
            'mode' => $mode,
            'from' => $start,
            'to' => $goal,
            'total_cost' => round($dist[$goal], 2),
            'path_nodes' => $pathNodes,
            'path_edges' => $pathEdges,
            'province_sequence' => $provinces,
        ];
    }

    private function edgeCost(array $edge, string $mode): float
    {
        $distance = (float)($edge['distance'] ?? 1.0);
        $kind = (string)($edge['kind'] ?? 'land');

        if ($kind === 'land') {
            $factor = match ($mode) {
                'cargo' => 1.35,
                'army' => 1.15,
                'courier' => 0.90,
                default => 1.0,
            };
            return $distance * $factor;
        }

        if ($kind === 'river') {
            $factor = match ($mode) {
                'cargo' => 0.42,
                'army' => 0.82,
                'courier' => 0.70,
                default => 0.6,
            };
            $direction = (string)($edge['direction'] ?? 'bidirectional');
            if ($direction === 'forward') {
                $distance *= 0.85;
            } elseif ($direction === 'reverse') {
                $distance *= 1.35;
            }
            if ($mode === 'cargo' && !empty($edge['target_staple_right'])) {
                $distance += 24.0;
            }
            return $distance * $factor;
        }

        if ($kind === 'embark' || $kind === 'disembark') {
            if ($mode === 'army') {
                return $distance + 10.0;
            }
            if ($mode === 'courier') {
                return max(1.0, $distance - 10.0);
            }
            return $distance;
        }

        return $distance;
    }

    private function buildSummary(array $provinceMap, array $adjacency, array $routesResult, array $graph, array $unresolvedColors): array
    {
        $riverProvinceIds = [];
        foreach ($routesResult['routes'] as $route) {
            foreach ($route['province_sequence'] as $entry) {
                $riverProvinceIds[(string)$entry['province_id']] = true;
            }
        }

        return [
            'generated_at' => gmdate('c'),
            'province_count' => count($provinceMap),
            'land_edges' => array_sum(array_map('count', $adjacency)),
            'river_count' => count($routesResult['routes']),
            'river_junction_count' => count($routesResult['junctions']),
            'river_access_province_count' => count($riverProvinceIds),
            'graph_node_count' => count($graph['nodes'] ?? []),
            'graph_edge_count' => array_sum(array_map('count', $graph['edges'] ?? [])),
            'unresolved_color_count' => count($unresolvedColors),
        ];
    }

    private function samplePolyline(array $polyline, int $step): array
    {
        $samples = [];
        if (count($polyline) < 2) {
            return $samples;
        }
        for ($i = 0, $n = count($polyline) - 1; $i < $n; $i++) {
            $a = $polyline[$i];
            $b = $polyline[$i + 1];
            $dist = max(1.0, $this->distance($a, $b));
            $parts = max(1, (int)floor($dist / $step));
            for ($k = 0; $k <= $parts; $k++) {
                $t = $k / $parts;
                $x = (int)round($a[0] + ($b[0] - $a[0]) * $t);
                $y = (int)round($a[1] + ($b[1] - $a[1]) * $t);
                if (!$samples || end($samples) !== [$x, $y]) {
                    $samples[] = [$x, $y];
                }
            }
        }
        return $samples;
    }

    private function nearestSampleOrder(array $samples, array $point): int
    {
        $best = 0;
        $bestDist = INF;
        foreach ($samples as $i => $sample) {
            $dist = $this->distance($sample, $point);
            if ($dist < $bestDist) {
                $best = $i;
                $bestDist = $dist;
            }
        }
        return $best;
    }

    private function sampleProvinceNeighborhood($img, int $x, int $y, int $radius, array $colorMap): array
    {
        $width = imagesx($img);
        $height = imagesy($img);
        $set = [];
        for ($dy = -$radius; $dy <= $radius; $dy++) {
            for ($dx = -$radius; $dx <= $radius; $dx++) {
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx < 0 || $yy < 0 || $xx >= $width || $yy >= $height) {
                    continue;
                }
                $pid = $this->provinceIdAt($img, $xx, $yy, $colorMap);
                if ($pid) {
                    $set[$pid] = true;
                }
            }
        }
        return array_values(array_keys($set));
    }

    private function provinceIdAt($img, int $x, int $y, array $colorMap): ?string
    {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $key = sprintf('%02x%02x%02x', $r, $g, $b);
        if ($key === '000000') {
            return null;
        }
        return isset($colorMap[$key]) ? (string)$colorMap[$key] : ('color:' . $key);
    }

    private function routeAccessNodeAtSample(array $routes, string $routeId, int $sampleOrder): ?string
    {
        foreach ($routes as $route) {
            if (($route['id'] ?? '') !== $routeId) {
                continue;
            }
            $best = null;
            $bestGap = INF;
            foreach ($route['province_sequence'] as $entry) {
                $gap = abs((int)$entry['first_sample'] - $sampleOrder);
                if ($gap < $bestGap) {
                    $bestGap = $gap;
                    $best = 'river_access:' . $routeId . ':' . $entry['province_id'];
                }
            }
            return $best;
        }
        return null;
    }

    private function polylineLength(array $polyline): float
    {
        $sum = 0.0;
        for ($i = 0, $n = count($polyline) - 1; $i < $n; $i++) {
            $sum += $this->distance($polyline[$i], $polyline[$i + 1]);
        }
        return $sum;
    }

    private function distance(array $a, array $b): float
    {
        return sqrt((($a[0] ?? 0) - ($b[0] ?? 0)) ** 2 + (($a[1] ?? 0) - ($b[1] ?? 0)) ** 2);
    }

    private function addEdge(array &$edges, string $from, string $to, array $payload): void
    {
        $payload['to'] = $to;
        $edges[$from][] = $payload;
    }

    private function ensureDir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create directory: ' . $path);
        }
    }

    private function loadJson(string $path, $default)
    {
        if (!is_file($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    private function saveJson(string $path, array $payload): void
    {
        $dir = dirname($path);
        $this->ensureDir($dir);
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
