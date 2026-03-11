<?php
declare(strict_types=1);

final class RasterLogisticsUnifiedModule
{
    private string $rootDir;
    private string $moduleDir;
    private string $runtimeDir;
    private string $sidecarDir;

    public function __construct(?string $rootDir = null)
    {
        $this->rootDir = $rootDir ? rtrim($rootDir, '/\\') : dirname(__DIR__, 3);
        $this->moduleDir = $this->rootDir . '/mods/raster_logistics';
        $this->runtimeDir = $this->rootDir . '/data/module_runtime/raster_logistics';
        $this->sidecarDir = $this->moduleDir . '/data/sidecar';
    }

    public function run(array $options = []): array
    {
        $cfg = $this->loadConfig($options);
        $this->ensureGd();
        $this->ensureDir($this->runtimeDir);

        [$provinceMap, $adjacency, $unresolved] = $this->buildProvinceRasterInfo();
        $provinceNames = $this->loadProvinceNames();
        foreach ($provinceMap as $pid => &$row) {
            $row['name'] = $provinceNames[$pid] ?? (string)$pid;
        }
        unset($row);

        $sourceStatus = [
            'land' => ['loaded' => true, 'node_count' => count($provinceMap)],
            'river' => ['loaded' => false, 'auto_ran' => false, 'error' => null],
            'marine' => ['loaded' => false, 'auto_ran' => false, 'error' => null],
        ];

        if (!empty($cfg['auto_run_submodules'])) {
            $sourceStatus['river']['auto_ran'] = $this->autoRunRiverModule($sourceStatus['river']);
            $sourceStatus['marine']['auto_ran'] = $this->autoRunMarineModule($sourceStatus['marine']);
        }

        $riverNetwork = $this->readJson($this->rootDir . '/data/module_runtime/river_logistics/network.json', []);
        if ($riverNetwork) {
            $sourceStatus['river']['loaded'] = true;
            $sourceStatus['river']['node_count'] = count((array)($riverNetwork['graph']['nodes'] ?? []));
        }

        $marineNetwork = $this->readJson($this->rootDir . '/data/module_runtime/marine_provinces/network.json', []);
        if ($marineNetwork) {
            $sourceStatus['marine']['loaded'] = true;
            $sourceStatus['marine']['node_count'] = count((array)($marineNetwork['graph']['nodes'] ?? []));
        }

        $graph = $this->buildUnifiedGraph($provinceMap, $adjacency, $riverNetwork, $marineNetwork, $cfg);
        $summary = $this->buildSummary($provinceMap, $graph, $sourceStatus, $unresolved);

        $result = [
            'generated_at' => gmdate('c'),
            'module' => 'raster_logistics',
            'version' => '2.0.0-unified',
            'config' => $cfg,
            'province_map' => $provinceMap,
            'graph' => $graph,
            'sources' => $sourceStatus,
            'summary' => $summary,
            'unresolved_colors' => $unresolved,
        ];

        if (!empty($cfg['write_runtime'])) {
            $this->writeJson($this->runtimeDir . '/network.json', $result);
            $this->writeJson($this->runtimeDir . '/summary.json', $summary);
            if ($unresolved) {
                $this->writeJson($this->runtimeDir . '/unresolved_colors.json', $unresolved);
            }
        }

        return $result;
    }

    public function findRoute(string $fromProvinceId, string $toProvinceId, string $mode = 'cargo'): array
    {
        $network = $this->readJson($this->runtimeDir . '/network.json', []);
        if (!$network) {
            throw new RuntimeException('network.json not found; run the module first');
        }
        $graph = (array)($network['graph'] ?? []);
        $nodes = (array)($graph['nodes'] ?? []);
        $from = 'P:' . trim($fromProvinceId);
        $to = 'P:' . trim($toProvinceId);
        if (!isset($nodes[$from])) {
            throw new InvalidArgumentException('Unknown origin province: ' . $fromProvinceId);
        }
        if (!isset($nodes[$to])) {
            throw new InvalidArgumentException('Unknown destination province: ' . $toProvinceId);
        }
        return $this->dijkstra($graph, $from, $to, $mode);
    }

    public function cleanup(): array
    {
        $removed = [];
        foreach (['network.json', 'summary.json', 'unresolved_colors.json'] as $file) {
            $path = $this->runtimeDir . '/' . $file;
            if (is_file($path)) {
                unlink($path);
                $removed[] = $path;
            }
        }
        return ['ok' => true, 'removed' => $removed];
    }

    private function loadConfig(array $overrides): array
    {
        $file = $this->readJson($this->sidecarDir . '/unified_mode_rules.json', []);
        $cfg = [
            'write_runtime' => true,
            'auto_run_submodules' => true,
            'merge_marine_land_port_into_province' => false,
            'modes' => [
                'cargo' => [
                    'allow' => ['land','river','sea_neutral','sea_territorial','sea_strait','river_embark','river_disembark','river_port_transfer','river_junction','sea_port_exit','land_to_marine_port'],
                    'weight' => [
                        'land' => 1.35,'river' => 0.72,'sea_neutral' => 0.55,'sea_territorial' => 0.62,'sea_strait' => 0.70,
                        'river_embark' => 4.0,'river_disembark' => 4.0,'river_port_transfer' => 1.0,'river_junction' => 0.9,
                        'sea_port_exit' => 1.1,'land_to_marine_port' => 1.0,
                    ],
                ],
                'army' => [
                    'allow' => ['land','river','sea_neutral','sea_territorial','sea_strait','river_embark','river_disembark','river_port_transfer','river_junction','sea_port_exit','land_to_marine_port'],
                    'weight' => [
                        'land' => 1.0,'river' => 0.95,'sea_neutral' => 1.20,'sea_territorial' => 1.05,'sea_strait' => 1.15,
                        'river_embark' => 7.0,'river_disembark' => 7.0,'river_port_transfer' => 1.3,'river_junction' => 1.0,
                        'sea_port_exit' => 1.5,'land_to_marine_port' => 1.2,
                    ],
                ],
                'courier' => [
                    'allow' => ['land','river','sea_neutral','sea_territorial','sea_strait','river_embark','river_disembark','river_port_transfer','river_junction','sea_port_exit','land_to_marine_port'],
                    'weight' => [
                        'land' => 0.82,'river' => 0.70,'sea_neutral' => 0.68,'sea_territorial' => 0.74,'sea_strait' => 0.72,
                        'river_embark' => 3.0,'river_disembark' => 3.0,'river_port_transfer' => 0.8,'river_junction' => 0.7,
                        'sea_port_exit' => 0.9,'land_to_marine_port' => 0.7,
                    ],
                ],
                'naval' => [
                    'allow' => ['sea_neutral','sea_territorial','sea_strait','sea_port_exit','land_to_marine_port'],
                    'weight' => [
                        'sea_neutral' => 0.55,'sea_territorial' => 0.60,'sea_strait' => 0.70,'sea_port_exit' => 1.0,'land_to_marine_port' => 0.9,
                    ],
                ],
            ],
        ];
        if (is_array($file)) $cfg = $this->arrayMergeRecursiveDistinct($cfg, $file);
        if ($overrides) $cfg = $this->arrayMergeRecursiveDistinct($cfg, $overrides);
        return $cfg;
    }

    private function autoRunRiverModule(array &$status): bool
    {
        $path = $this->rootDir . '/mods/river_logistics/lib/RiverLogisticsModule.php';
        if (!is_file($path)) { $status['error'] = 'river module not installed'; return false; }
        require_once $path;
        if (!class_exists('RiverLogisticsModule')) { $status['error'] = 'RiverLogisticsModule class not found'; return false; }
        try { $module = new RiverLogisticsModule($this->rootDir); $module->run(false); return true; }
        catch (Throwable $e) { $status['error'] = $e->getMessage(); return false; }
    }

    private function autoRunMarineModule(array &$status): bool
    {
        $path = $this->rootDir . '/mods/marine_provinces/lib/MarineProvincesModule.php';
        if (!is_file($path)) { $status['error'] = 'marine module not installed'; return false; }
        require_once $path;
        if (!class_exists('MarineProvincesModule')) { $status['error'] = 'MarineProvincesModule class not found'; return false; }
        try { $module = new MarineProvincesModule($this->rootDir); $module->run(['write_runtime' => true]); return true; }
        catch (Throwable $e) { $status['error'] = $e->getMessage(); return false; }
    }

    private function buildUnifiedGraph(array $provinceMap, array $adjacency, array $riverNetwork, array $marineNetwork, array $cfg): array
    {
        $nodes = [];
        $edges = [];
        foreach ($provinceMap as $pid => $province) {
            $nodeId = 'P:' . $pid;
            $nodes[$nodeId] = [
                'id' => $nodeId,'kind' => 'province','province_id' => $pid,'name' => $province['name'] ?? (string)$pid,
                'centroid' => $province['centroid'],'bbox' => $province['bbox'],
            ];
        }
        foreach ($adjacency as $pid => $neighbors) {
            foreach ($neighbors as $neighborPid) {
                if (!isset($provinceMap[$pid], $provinceMap[$neighborPid])) continue;
                $distance = $this->distance($provinceMap[$pid]['centroid'], $provinceMap[$neighborPid]['centroid']);
                $this->addEdge($edges, 'P:' . $pid, 'P:' . $neighborPid, ['class' => 'land','distance' => $distance,'source' => 'land']);
            }
        }
        if ($riverNetwork) $this->importRiverGraph($nodes, $edges, $riverNetwork, $cfg);
        if ($marineNetwork) $this->importMarineGraph($nodes, $edges, $marineNetwork, $cfg);
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    private function importRiverGraph(array &$nodes, array &$edges, array $riverNetwork, array $cfg): void
    {
        $graph = (array)($riverNetwork['graph'] ?? []);
        foreach ((array)($graph['nodes'] ?? []) as $id => $node) {
            $id = (string)$id;
            if (str_starts_with($id, 'province:')) {
                $pid = (string)($node['province_id'] ?? substr($id, 9));
                $nodes['P:' . $pid] ??= ['id' => 'P:' . $pid, 'kind' => 'province', 'province_id' => $pid, 'name' => $pid, 'centroid' => $node['centroid'] ?? null];
                continue;
            }
            $mappedId = $this->mapRiverNodeId($id);
            $nodes[$mappedId] = [
                'id' => $mappedId,'kind' => 'river','river_kind' => $node['type'] ?? 'river_access','province_id' => $node['province_id'] ?? null,
                'route_id' => $node['route_id'] ?? null,'label' => $node['label'] ?? ($node['port_id'] ?? $mappedId),'staple_right' => (bool)($node['staple_right'] ?? false),
                'point' => $node['point'] ?? null,
            ];
        }
        foreach ((array)($graph['edges'] ?? []) as $from => $fromEdges) {
            foreach ((array)$fromEdges as $edge) {
                $mappedFrom = $this->mapRiverNodeId((string)$from);
                $mappedTo = $this->mapRiverNodeId((string)($edge['to'] ?? ''));
                if ($mappedTo === '') continue;
                $class = match ((string)($edge['kind'] ?? 'river')) {
                    'land' => 'land', 'embark' => 'river_embark', 'disembark' => 'river_disembark', 'port_transfer' => 'river_port_transfer', 'junction' => 'river_junction', default => 'river',
                };
                if ($class === 'land') continue;
                $this->addEdge($edges, $mappedFrom, $mappedTo, [
                    'class' => $class,'distance' => (float)($edge['distance'] ?? 1.0),'source' => 'river','route_id' => $edge['route_id'] ?? null,
                    'direction' => $edge['direction'] ?? null,'target_staple_right' => (bool)($edge['target_staple_right'] ?? false),
                ]);
            }
        }
    }

    private function importMarineGraph(array &$nodes, array &$edges, array $marineNetwork, array $cfg): void
    {
        $graph = (array)($marineNetwork['graph'] ?? []);
        $mergePorts = !empty($cfg['merge_marine_land_port_into_province']);
        foreach ((array)($graph['nodes'] ?? []) as $id => $node) {
            $id = (string)$id;
            if (str_starts_with($id, 'LP:')) {
                $pid = substr($id, 3);
                $provinceNode = 'P:' . $pid;
                $nodes[$provinceNode] ??= ['id' => $provinceNode, 'kind' => 'province', 'province_id' => $pid, 'name' => $node['name'] ?? $pid];
                if (!$mergePorts) {
                    $portNode = 'MLP:' . $pid;
                    $nodes[$portNode] = ['id' => $portNode,'kind' => 'marine_land_port','province_id' => $pid,'name' => $node['name'] ?? $pid];
                    $this->addEdge($edges, $provinceNode, $portNode, ['class' => 'land_to_marine_port','distance' => 6.0,'source' => 'marine']);
                    $this->addEdge($edges, $portNode, $provinceNode, ['class' => 'land_to_marine_port','distance' => 6.0,'source' => 'marine']);
                }
                continue;
            }
            $mappedId = $this->mapMarineNodeId($id);
            $nodes[$mappedId] = [
                'id' => $mappedId,'kind' => 'marine','marine_id' => $node['marine_id'] ?? null,'marine_kind' => $node['marine_kind'] ?? null,
                'name' => $node['name'] ?? $mappedId,'owner_province_id' => $node['owner_province_id'] ?? null,'battle_theatre_id' => $node['battle_theatre_id'] ?? null,
            ];
        }
        foreach ((array)($graph['adjacency'] ?? []) as $from => $fromEdges) {
            foreach ((array)$fromEdges as $edge) {
                $mappedFrom = $this->mapMarineEdgeNodeId((string)$from, $mergePorts);
                $mappedTo = $this->mapMarineEdgeNodeId((string)($edge['to'] ?? ''), $mergePorts);
                if ($mappedFrom === '' || $mappedTo === '') continue;
                $class = match ((string)($edge['class'] ?? 'neutral')) {
                    'territorial' => 'sea_territorial', 'strait' => 'sea_strait', 'port_exit' => 'sea_port_exit', default => 'sea_neutral',
                };
                $this->addEdge($edges, $mappedFrom, $mappedTo, ['class' => $class,'distance' => (float)($edge['cost'] ?? 1.0),'source' => 'marine']);
            }
        }
    }

    private function buildSummary(array $provinceMap, array $graph, array $sources, array $unresolved): array
    {
        $counts = ['province' => 0,'river' => 0,'marine' => 0,'marine_land_port' => 0];
        foreach ((array)($graph['nodes'] ?? []) as $node) {
            $kind = (string)($node['kind'] ?? 'province');
            if (isset($counts[$kind])) $counts[$kind]++;
        }
        $edgeCounts = [];
        foreach ((array)($graph['edges'] ?? []) as $fromEdges) {
            foreach ((array)$fromEdges as $edge) {
                $class = (string)($edge['class'] ?? 'unknown');
                $edgeCounts[$class] = ($edgeCounts[$class] ?? 0) + 1;
            }
        }
        ksort($edgeCounts);
        $topConnected = [];
        foreach ((array)($graph['edges'] ?? []) as $nodeId => $fromEdges) $topConnected[] = ['node_id' => $nodeId, 'out_degree' => count((array)$fromEdges)];
        usort($topConnected, static fn(array $a, array $b): int => $b['out_degree'] <=> $a['out_degree']);
        return [
            'generated_at' => gmdate('c'),'province_count' => count($provinceMap),'unresolved_color_count' => count($unresolved),
            'node_counts' => $counts,'edge_counts' => $edgeCounts,'source_status' => $sources,'top_connected_nodes' => array_slice($topConnected, 0, 20),
        ];
    }

    private function dijkstra(array $graph, string $start, string $goal, string $mode): array
    {
        $nodes = (array)($graph['nodes'] ?? []);
        $edges = (array)($graph['edges'] ?? []);
        $cfg = $this->loadConfig([]);
        $modeCfg = (array)($cfg['modes'][$mode] ?? $cfg['modes']['cargo']);
        $allowed = array_fill_keys(array_map('strval', (array)($modeCfg['allow'] ?? [])), true);
        $weights = (array)($modeCfg['weight'] ?? []);
        $dist = [$start => 0.0]; $prev = []; $queue = [$start => 0.0];
        while ($queue) {
            asort($queue); $current = (string)array_key_first($queue); $currentCost = (float)$queue[$current]; unset($queue[$current]);
            if ($current === $goal) break;
            foreach ((array)($edges[$current] ?? []) as $edge) {
                $class = (string)($edge['class'] ?? 'land'); if (!isset($allowed[$class])) continue;
                $weight = (float)($weights[$class] ?? 1.0); $extra = (float)($edge['distance'] ?? 1.0) * $weight;
                if (!empty($edge['target_staple_right']) && $mode === 'cargo') $extra += 6.0;
                $to = (string)($edge['to'] ?? ''); if ($to === '') continue;
                $candidate = $currentCost + $extra;
                if (!isset($dist[$to]) || $candidate < $dist[$to]) { $dist[$to] = $candidate; $prev[$to] = ['node' => $current, 'edge' => $edge]; $queue[$to] = $candidate; }
            }
        }
        if (!isset($dist[$goal])) return ['ok' => false, 'error' => 'No route found', 'mode' => $mode];
        $pathNodes = []; $pathEdges = []; $cursor = $goal;
        while ($cursor !== $start) { $pathNodes[] = $cursor; $pathEdges[] = $prev[$cursor]['edge']; $cursor = $prev[$cursor]['node']; }
        $pathNodes[] = $start; $pathNodes = array_reverse($pathNodes); $pathEdges = array_reverse($pathEdges);
        $provinceSequence = [];
        foreach ($pathNodes as $nodeId) {
            $pid = $nodes[$nodeId]['province_id'] ?? null;
            if ($pid !== null && (!count($provinceSequence) || end($provinceSequence) !== $pid)) $provinceSequence[] = (string)$pid;
        }
        return ['ok' => true,'mode' => $mode,'from' => $start,'to' => $goal,'total_cost' => round((float)$dist[$goal], 2),'path_nodes' => $pathNodes,'path_edges' => $pathEdges,'province_sequence' => $provinceSequence];
    }

    private function buildProvinceRasterInfo(): array
    {
        $provincePng = $this->rootDir . '/provinces_id.png';
        if (!is_file($provincePng)) throw new RuntimeException('provinces_id.png not found at ' . $provincePng);
        $img = imagecreatefrompng($provincePng); if (!$img) throw new RuntimeException('Failed to open provinces_id.png');
        $colorMap = $this->loadJson($this->sidecarDir . '/province_color_map.json', []);
        $width = imagesx($img); $height = imagesy($img); $raw = []; $adjacency = []; $unresolved = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($img, $x, $y); $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF; $key = sprintf('%02x%02x%02x', $r, $g, $b);
                if ($key === '000000') continue;
                $pid = isset($colorMap[$key]) ? (string)$colorMap[$key] : ('color:' . $key);
                if (!isset($raw[$pid])) {
                    $raw[$pid] = ['id' => $pid,'source_color' => $key,'pixel_count' => 0,'sum_x' => 0,'sum_y' => 0,'min_x' => $x,'min_y' => $y,'max_x' => $x,'max_y' => $y];
                    if (!isset($colorMap[$key])) $unresolved[$key] = ['color_key' => $key, 'suggested_id' => 'color:' . $key];
                }
                $raw[$pid]['pixel_count']++; $raw[$pid]['sum_x'] += $x; $raw[$pid]['sum_y'] += $y; $raw[$pid]['min_x'] = min($raw[$pid]['min_x'], $x); $raw[$pid]['min_y'] = min($raw[$pid]['min_y'], $y); $raw[$pid]['max_x'] = max($raw[$pid]['max_x'], $x); $raw[$pid]['max_y'] = max($raw[$pid]['max_y'], $y);
                if ($x + 1 < $width) { $pid2 = $this->provinceIdAt($img, $x + 1, $y, $colorMap); if ($pid2 && $pid2 !== $pid) { $adjacency[$pid][$pid2] = true; $adjacency[$pid2][$pid] = true; }}
                if ($y + 1 < $height) { $pid2 = $this->provinceIdAt($img, $x, $y + 1, $colorMap); if ($pid2 && $pid2 !== $pid) { $adjacency[$pid][$pid2] = true; $adjacency[$pid2][$pid] = true; }}
            }
        }
        imagedestroy($img);
        $provinceMap = [];
        foreach ($raw as $pid => $info) {
            $count = max(1, (int)$info['pixel_count']);
            $provinceMap[$pid] = ['id' => $pid,'source_color' => $info['source_color'],'pixel_count' => $count,'centroid' => [round($info['sum_x'] / $count, 2), round($info['sum_y'] / $count, 2)],'bbox' => [$info['min_x'], $info['min_y'], $info['max_x'], $info['max_y']],'neighbors' => array_values(array_keys($adjacency[$pid] ?? []))];
        }
        $adjacency = array_map(static fn(array $set): array => array_values(array_keys($set)), $adjacency);
        return [$provinceMap, $adjacency, array_values($unresolved)];
    }

    private function provinceIdAt($img, int $x, int $y, array $colorMap): ?string
    {
        $width = imagesx($img); $height = imagesy($img); if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) return null;
        $rgb = imagecolorat($img, $x, $y); $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF; $key = sprintf('%02x%02x%02x', $r, $g, $b);
        if ($key === '000000') return null; return isset($colorMap[$key]) ? (string)$colorMap[$key] : ('color:' . $key);
    }

    private function loadProvinceNames(): array
    {
        $out = [];
        foreach ([$this->rootDir . '/provinces.json', $this->rootDir . '/data/map_state.json'] as $path) {
            $data = $this->readJson($path, []);
            foreach ($this->extractProvinceRecords($data) as $row) {
                $id = (string)($row['id'] ?? $row['province_id'] ?? ''); if ($id === '') continue;
                $out[$id] = (string)($row['name'] ?? $row['province_name'] ?? $row['title'] ?? $id);
            }
        }
        return $out;
    }

    private function extractProvinceRecords(mixed $data): array
    {
        if (!is_array($data)) return [];
        if (isset($data['provinces']) && is_array($data['provinces'])) return array_values(array_filter($data['provinces'], 'is_array'));
        if (array_is_list($data)) return array_values(array_filter($data, 'is_array'));
        return [];
    }

    private function mapRiverNodeId(string $id): string
    {
        if (str_starts_with($id, 'province:')) return 'P:' . substr($id, 9);
        if (str_starts_with($id, 'river_access:')) return 'RA:' . substr($id, 13);
        if (str_starts_with($id, 'river_port:')) return 'RP:' . substr($id, 11);
        return $id === '' ? '' : 'R:' . $id;
    }

    private function mapMarineNodeId(string $id): string
    {
        if (str_starts_with($id, 'MP:')) return 'M:' . substr($id, 3);
        if (str_starts_with($id, 'LP:')) return 'MLP:' . substr($id, 3);
        return $id === '' ? '' : 'M:' . $id;
    }

    private function mapMarineEdgeNodeId(string $id, bool $mergePorts): string
    {
        if (str_starts_with($id, 'MP:')) return 'M:' . substr($id, 3);
        if (str_starts_with($id, 'LP:')) { $pid = substr($id, 3); return $mergePorts ? 'P:' . $pid : 'MLP:' . $pid; }
        return $id === '' ? '' : 'M:' . $id;
    }

    private function addEdge(array &$edges, string $from, string $to, array $payload): void
    {
        if ($from === '' || $to === '') return; $payload['from'] = $from; $payload['to'] = $to; $edges[$from][] = $payload;
    }

    private function arrayMergeRecursiveDistinct(array $a, array $b): array
    {
        $merged = $a;
        foreach ($b as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key]) && !array_is_list($value) && !array_is_list($merged[$key])) $merged[$key] = $this->arrayMergeRecursiveDistinct($merged[$key], $value);
            else $merged[$key] = $value;
        }
        return $merged;
    }

    private function ensureDir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) throw new RuntimeException('Failed to create directory: ' . $path);
    }

    private function ensureGd(): void
    {
        if (!function_exists('imagecreatefrompng')) throw new RuntimeException('PHP GD is required (imagecreatefrompng not available)');
    }

    private function readJson(string $path, mixed $default): mixed
    {
        if (!is_file($path)) return $default; $raw = file_get_contents($path); if ($raw === false || $raw === '') return $default; $decoded = json_decode($raw, true); return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    private function writeJson(string $path, mixed $data): void
    {
        $this->ensureDir(dirname($path)); file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private function distance(array $a, array $b): float
    {
        return sqrt((($a[0] ?? 0) - ($b[0] ?? 0)) ** 2 + (($a[1] ?? 0) - ($b[1] ?? 0)) ** 2);
    }
}
