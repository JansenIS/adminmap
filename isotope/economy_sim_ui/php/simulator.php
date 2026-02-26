<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class EconomySimulator
{
    private PDO $db;

    /** @var array<int,array{id:string,name:string,unit:string,tier:string,base_price:float,demand:float,decay:float,rarity:float}> */
    private array $commodities = [
        ['id' => 'wood_raw', 'name' => 'Древесина', 'unit' => 'м³', 'tier' => 'raw', 'base_price' => 8.0, 'demand' => 0.45, 'decay' => 0.01, 'rarity' => 0.05],
        ['id' => 'fiber_raw', 'name' => 'Прядильное волокно', 'unit' => 'кг', 'tier' => 'raw', 'base_price' => 6.0, 'demand' => 0.42, 'decay' => 0.015, 'rarity' => 0.08],
        ['id' => 'hides_raw', 'name' => 'Необработанные шкуры', 'unit' => 'шт', 'tier' => 'raw', 'base_price' => 10.0, 'demand' => 0.18, 'decay' => 0.00, 'rarity' => 0.10],
        ['id' => 'rubber_raw', 'name' => 'Сырьевой каучук', 'unit' => 'кг', 'tier' => 'raw', 'base_price' => 14.0, 'demand' => 0.20, 'decay' => 0.00, 'rarity' => 0.20],
        ['id' => 'petrochem_raw', 'name' => 'Нефтехимическое сырьё', 'unit' => 'кг', 'tier' => 'raw', 'base_price' => 18.0, 'demand' => 0.22, 'decay' => 0.00, 'rarity' => 0.25],
        ['id' => 'stone', 'name' => 'Камень', 'unit' => 'т', 'tier' => 'raw', 'base_price' => 30.0, 'demand' => 0.26, 'decay' => 0.00, 'rarity' => 0.20],
        ['id' => 'iron_ore', 'name' => 'Железная руда', 'unit' => 'т', 'tier' => 'raw', 'base_price' => 20.0, 'demand' => 0.28, 'decay' => 0.00, 'rarity' => 0.18],
        ['id' => 'mutabryukva', 'name' => 'Мутабрюква', 'unit' => 'кг', 'tier' => 'raw', 'base_price' => 0.35, 'demand' => 1.10, 'decay' => 0.05, 'rarity' => 0.03],
        ['id' => 'mutachicken', 'name' => 'Мутакурицы', 'unit' => 'шт', 'tier' => 'raw', 'base_price' => 9.0, 'demand' => 0.30, 'decay' => 0.03, 'rarity' => 0.06],
        ['id' => 'gold', 'name' => 'Золото', 'unit' => 'г', 'tier' => 'raw', 'base_price' => 70.0, 'demand' => 0.04, 'decay' => 0.00, 'rarity' => 0.90],
        ['id' => 'silver', 'name' => 'Серебро', 'unit' => 'кг', 'tier' => 'raw', 'base_price' => 25.0, 'demand' => 0.07, 'decay' => 0.00, 'rarity' => 0.75],
        ['id' => 'villadium', 'name' => 'Вилладиум', 'unit' => 'г', 'tier' => 'raw', 'base_price' => 50.0, 'demand' => 0.03, 'decay' => 0.00, 'rarity' => 0.98],
        ['id' => 'bread', 'name' => 'Хлеб', 'unit' => 'кг', 'tier' => 'component', 'base_price' => 1.0, 'demand' => 0.85, 'decay' => 0.04, 'rarity' => 0.05],
        ['id' => 'meat_cans', 'name' => 'Мясные консервы', 'unit' => 'кг', 'tier' => 'component', 'base_price' => 20.0, 'demand' => 0.12, 'decay' => 0.00, 'rarity' => 0.08],
        ['id' => 'steel', 'name' => 'Сталь', 'unit' => 'т', 'tier' => 'component', 'base_price' => 1000.0, 'demand' => 0.10, 'decay' => 0.00, 'rarity' => 0.30],
        ['id' => 'wood_processed', 'name' => 'Обработанная древесина', 'unit' => 'м³', 'tier' => 'component', 'base_price' => 18.0, 'demand' => 0.28, 'decay' => 0.00, 'rarity' => 0.06],
        ['id' => 'e_parts', 'name' => 'Электронные компоненты', 'unit' => 'наб.', 'tier' => 'component', 'base_price' => 85.0, 'demand' => 0.10, 'decay' => 0.00, 'rarity' => 0.70],
        ['id' => 'engine_kit', 'name' => 'Комплект движка', 'unit' => 'шт', 'tier' => 'component', 'base_price' => 260.0, 'demand' => 0.08, 'decay' => 0.00, 'rarity' => 0.55],
        ['id' => 'truck_civil', 'name' => 'Гражданский грузовик', 'unit' => 'шт', 'tier' => 'product', 'base_price' => 7000.0, 'demand' => 0.02, 'decay' => 0.00, 'rarity' => 0.95],
        ['id' => 'air_purifier_home', 'name' => 'Личный очиститель воздуха', 'unit' => 'шт', 'tier' => 'product', 'base_price' => 800.0, 'demand' => 0.05, 'decay' => 0.00, 'rarity' => 0.65],
        ['id' => 'field_cat', 'name' => 'Полевой кот', 'unit' => 'шт', 'tier' => 'animal', 'base_price' => 2560.0, 'demand' => 0.01, 'decay' => 0.00, 'rarity' => 0.15],
    ];

    /** @var array<string,array{input:array<string,float>,output:array<string,float>,base_rate:float}> */
    private array $industrialGraph = [
        'bakery' => [
            'input' => ['mutabryukva' => 1.2],
            'output' => ['bread' => 1.0],
            'base_rate' => 6.0,
        ],
        'metalworks' => [
            'input' => ['iron_ore' => 1.3, 'stone' => 0.2],
            'output' => ['steel' => 0.8],
            'base_rate' => 2.0,
        ],
        'electronics' => [
            'input' => ['steel' => 0.3, 'petrochem_raw' => 0.4],
            'output' => ['e_parts' => 0.7],
            'base_rate' => 1.2,
        ],
        'engine_factory' => [
            'input' => ['steel' => 0.5, 'e_parts' => 0.4, 'rubber_raw' => 0.3],
            'output' => ['engine_kit' => 0.6],
            'base_rate' => 0.8,
        ],
        'vehicle_factory' => [
            'input' => ['engine_kit' => 0.4, 'steel' => 0.7, 'wood_processed' => 0.2],
            'output' => ['truck_civil' => 0.18],
            'base_rate' => 0.35,
        ],
    ];

    /** @var array<int,array<int,float>>|null */
    private ?array $distanceCache = null;

    public function __construct()
    {
        $this->db = db();
    }

    public function ensureReady(): void
    {
        $this->migrate();
        $this->syncMapData();
        $this->seedEconomyIfNeeded();
    }

    private function migrate(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS provinces (
            pid INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            terrain TEXT DEFAULT "",
            centroid_x REAL DEFAULT 0,
            centroid_y REAL DEFAULT 0,
            area_px REAL DEFAULT 0,
            hex_count INTEGER DEFAULT 0,
            owner TEXT DEFAULT "",
            kingdom_id TEXT DEFAULT "",
            great_house_id TEXT DEFAULT "",
            minor_house_id TEXT DEFAULT "",
            free_city_id TEXT DEFAULT ""
        )');
        try {
            $this->db->exec('ALTER TABLE provinces ADD COLUMN minor_house_id TEXT');
        } catch (Throwable $e) {
            // column already exists
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS province_neighbors (
            pid INTEGER NOT NULL,
            neighbor_pid INTEGER NOT NULL,
            shared_sides REAL DEFAULT 1,
            PRIMARY KEY (pid, neighbor_pid)
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS hexes (
            hex_id INTEGER PRIMARY KEY,
            q INTEGER NOT NULL,
            r INTEGER NOT NULL,
            cx REAL NOT NULL,
            cy REAL NOT NULL,
            province_pid INTEGER NOT NULL
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_hexes_pid ON hexes(province_pid)');
        $this->db->exec('DROP TABLE IF EXISTS commodities');
        $this->db->exec('CREATE TABLE commodities (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            unit TEXT NOT NULL,
            tier TEXT NOT NULL,
            base_price REAL NOT NULL,
            demand REAL NOT NULL,
            decay REAL NOT NULL,
            rarity REAL NOT NULL
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS sim_state (
            pid INTEGER NOT NULL,
            commodity_id TEXT NOT NULL,
            stock REAL NOT NULL,
            price REAL NOT NULL,
            yearly_prod REAL NOT NULL,
            yearly_cons REAL NOT NULL,
            PRIMARY KEY (pid, commodity_id)
        )');

        $this->db->exec('CREATE TABLE IF NOT EXISTS sim_province_overrides (
            pid INTEGER PRIMARY KEY,
            is_city INTEGER DEFAULT NULL,
            pop INTEGER DEFAULT NULL,
            infra REAL DEFAULT NULL,
            transport_cap REAL DEFAULT NULL,
            transport_used REAL DEFAULT NULL,
            gdp_turnover REAL DEFAULT NULL
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS sim_province_buildings (
            pid INTEGER NOT NULL,
            type TEXT NOT NULL,
            count INTEGER NOT NULL,
            efficiency REAL NOT NULL,
            PRIMARY KEY (pid, type)
        )');

        $stmt = $this->db->prepare('INSERT OR REPLACE INTO commodities(id,name,unit,tier,base_price,demand,decay,rarity) VALUES(?,?,?,?,?,?,?,?)');
        foreach ($this->commodities as $c) {
            $stmt->execute([$c['id'], $c['name'], $c['unit'], $c['tier'], $c['base_price'], $c['demand'], $c['decay'], $c['rarity']]);
        }
    }

    private function syncMapData(): void
    {
        $files = data_files();
        foreach ($files as $key => $path) {
            if (!is_file($path)) {
                throw new RuntimeException("Missing map file: {$path}");
            }
        }

        $fingerprint = sha1(implode('|', array_map(static fn($f) => (string)filemtime($f), $files)));
        $stored = $this->metaGet('map_fingerprint');
        if ($stored === $fingerprint) {
            return;
        }

        $routing = json_decode((string)file_get_contents($files['routing']), true, 512, JSON_THROW_ON_ERROR);
        $mapState = json_decode((string)file_get_contents($files['map_state']), true, 512, JSON_THROW_ON_ERROR);
        $hexMap = $this->loadHexmapFromJs($files['hexmap']);

        $provStatic = $routing['provinces'] ?? [];
        $provDynamic = $mapState['provinces'] ?? [];

        $hexCountByPid = [];
        foreach (($hexMap['hexes'] ?? []) as $hex) {
            $pid = (int)($hex['p'] ?? 0);
            $hexCountByPid[$pid] = ($hexCountByPid[$pid] ?? 0) + 1;
        }

        $this->db->beginTransaction();
        $this->db->exec('DELETE FROM province_neighbors');
        $this->db->exec('DELETE FROM provinces');

        $insProv = $this->db->prepare('INSERT INTO provinces(pid,name,terrain,centroid_x,centroid_y,area_px,hex_count,owner,kingdom_id,great_house_id,minor_house_id,free_city_id)
            VALUES(:pid,:name,:terrain,:cx,:cy,:area,:hex_count,:owner,:kingdom,:house,:minor_house,:city)');
        $insNei = $this->db->prepare('INSERT INTO province_neighbors(pid,neighbor_pid,shared_sides) VALUES(?,?,?)');

        foreach ($provStatic as $pidRaw => $s) {
            $pid = (int)($s['pid'] ?? $pidRaw);
            $dyn = $provDynamic[(string)$pid] ?? [];
            $centroid = $s['centroid'] ?? [0, 0];
            $insProv->execute([
                ':pid' => $pid,
                ':name' => (string)($dyn['name'] ?? $s['name'] ?? ('Провинция ' . $pid)),
                ':terrain' => (string)($dyn['terrain'] ?? $s['terrain'] ?? ''),
                ':cx' => (float)($centroid[0] ?? 0),
                ':cy' => (float)($centroid[1] ?? 0),
                ':area' => (float)($s['area_px'] ?? 0),
                ':hex_count' => (int)($hexCountByPid[$pid] ?? ($s['hex_count'] ?? 0)),
                ':owner' => (string)($dyn['owner'] ?? ''),
                ':kingdom' => (string)($dyn['kingdom_id'] ?? ''),
                ':house' => (string)($dyn['great_house_id'] ?? ''),
                ':minor_house' => (string)($dyn['minor_house_id'] ?? ''),
                ':city' => (string)($dyn['free_city_id'] ?? ''),
            ]);

            foreach (($s['neighbors'] ?? []) as $n) {
                $insNei->execute([$pid, (int)($n['pid'] ?? 0), (float)($n['shared_sides'] ?? 1)]);
            }
        }

        $this->db->exec('DELETE FROM hexes');
        $insHex = $this->db->prepare('INSERT INTO hexes(hex_id,q,r,cx,cy,province_pid) VALUES(?,?,?,?,?,?)');
        foreach (($hexMap['hexes'] ?? []) as $hex) {
            $insHex->execute([
                (int)($hex['id'] ?? 0),
                (int)($hex['q'] ?? 0),
                (int)($hex['r'] ?? 0),
                (float)($hex['cx'] ?? 0),
                (float)($hex['cy'] ?? 0),
                (int)($hex['p'] ?? 0),
            ]);
        }

        $this->metaSet('map_fingerprint', $fingerprint);
        $this->db->commit();
    }

    private function loadHexmapFromJs(string $path): array
    {
        $raw = trim((string)file_get_contents($path));
        $prefix = 'window.HEXMAP=';
        if (!str_starts_with($raw, $prefix)) {
            throw new RuntimeException('Unexpected hexmap format');
        }

        $json = rtrim(substr($raw, strlen($prefix)), ';');
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedEconomyIfNeeded(): void
    {
        $provinces = $this->db->query('SELECT pid, hex_count, area_px FROM provinces ORDER BY pid')->fetchAll();
        $count = (int)$this->db->query('SELECT COUNT(*) FROM sim_state')->fetchColumn();
        $expected = count($provinces) * count($this->commodities);
        if ($count > 0 && $count !== $expected) {
            $this->db->exec('DELETE FROM sim_state');
            $count = 0;
        }
        if ($count > 0) {
            return;
        }

        $ins = $this->db->prepare('INSERT INTO sim_state(pid,commodity_id,stock,price,yearly_prod,yearly_cons) VALUES(?,?,?,?,?,?)');

        foreach ($provinces as $p) {
            $pid = (int)$p['pid'];
            $scale = max(1.0, ((float)$p['hex_count']) / 120.0);
            foreach ($this->commodities as $idx => $c) {
                $special = 0.8 + (($pid + $idx * 13) % 11) / 10;
                $rarityFactor = max(0.15, 1.1 - $c['rarity']);
                $prod = $scale * $special * $rarityFactor * (30 + ($idx % 7) * 9);
                $cons = $scale * $c['demand'] * 24;
                $stock = $prod * (1.4 + (($pid + $idx) % 3) * 0.3);
                $price = $c['base_price'];
                $ins->execute([$pid, $c['id'], $stock, $price, $prod, $cons]);
            }
        }

        $this->metaSet('year', '0');
    }

    public function getSummary(): array
    {
        $year = (int)$this->metaGet('year', '0');
        $stats = $this->db->query('SELECT c.id,c.name,c.base_price,c.tier, SUM(s.stock) as stock, AVG(s.price) as avg_price, SUM(s.yearly_prod) as prod, SUM(s.yearly_cons) as cons
            FROM sim_state s JOIN commodities c ON c.id=s.commodity_id GROUP BY c.id,c.name,c.base_price,c.tier ORDER BY c.id')->fetchAll();

        $scored = array_map(static function (array $r): array {
            $target = max(1.0, (float)$r['cons'] * 1.2);
            return [
                'commodity' => $r['name'],
                'ratio' => (float)$r['stock'] / $target,
                'basePrice' => (float)$r['base_price'],
            ];
        }, $stats);

        usort($scored, static fn(array $a, array $b): int => $a['ratio'] <=> $b['ratio']);
        $scarce = array_slice($scored, 0, 6);
        $cheap = array_slice(array_reverse($scored), 0, 6);

        $provinces = $this->db->query('SELECT pid,name,hex_count,free_city_id,owner FROM provinces ORDER BY pid')->fetchAll();
        $gdpRows = [];
        $totalPop = 0;
        foreach ($provinces as $prov) {
            $d = $this->provinceDerived((int)$prov['pid'], (int)$prov['hex_count'], (string)$prov['owner'], (string)$prov['free_city_id']);
            $totalPop += (int)$d['pop'];
            $gdpRows[] = [
                'pid' => (int)$prov['pid'],
                'name' => $prov['name'],
                'gdp' => (int)$d['gdpTurnover'],
                'pop' => (int)$d['pop'],
                'infra' => (float)$d['infra'],
            ];
        }
        usort($gdpRows, static fn(array $a, array $b): int => $b['gdp'] <=> $a['gdp']);

        return [
            'year' => $year,
            'day' => $year * 365,
            'popTotal' => $totalPop,
            'province_count' => count($provinces),
            'hex_count' => (int)$this->db->query('SELECT COUNT(*) FROM hexes')->fetchColumn(),
            'topGDP' => array_slice($gdpRows, 0, 8),
            'scarce' => $scarce,
            'cheap' => $cheap,
            'commodities' => array_map(static fn(array $r): array => [
                'id' => $r['id'],
                'name' => $r['name'],
                'tier' => $r['tier'],
                'stock' => round((float)$r['stock'], 2),
                'avg_price' => round((float)$r['avg_price'], 3),
                'production' => round((float)$r['prod'], 2),
                'consumption' => round((float)$r['cons'], 2),
            ], $stats),
        ];
    }

    public function getMeta(): array
    {
        $year = (int)$this->metaGet('year', '0');
        $cfg = [
            'seed' => (int)$this->metaGet('seed', '1'),
            'transportUnitCost' => (float)$this->metaGet('transportUnitCost', '0.35'),
            'tradeFriction' => (float)$this->metaGet('tradeFriction', '0.05'),
            'smoothSteps' => 1,
        ];

        $provinces = $this->db->query('SELECT pid,name,terrain,centroid_x,centroid_y,hex_count,owner,free_city_id FROM provinces ORDER BY pid')->fetchAll();
        $provinceRows = [];
        foreach ($provinces as $prov) {
            $d = $this->provinceDerived((int)$prov['pid'], (int)$prov['hex_count'], (string)$prov['owner'], (string)$prov['free_city_id']);
            $provinceRows[] = [
                'pid' => (int)$prov['pid'],
                'name' => $prov['name'],
                'terrain' => $prov['terrain'],
                'centroid' => [(float)$prov['centroid_x'], (float)$prov['centroid_y']],
                'hex_count' => (int)$prov['hex_count'],
                'isCity' => $d['isCity'],
                'pop' => $d['pop'],
                'infra' => $d['infra'],
            ];
        }

        $commodities = $this->db->query('SELECT id,name,unit,tier,base_price,decay,rarity FROM commodities ORDER BY id')->fetchAll();

        return [
            'day' => $year * 365,
            'config' => $cfg,
            'provinces' => $provinceRows,
            'commodities' => array_map(static fn(array $r): array => [
                'id' => $r['id'],
                'name' => $r['name'],
                'unit' => $r['unit'],
                'tier' => $r['tier'],
                'basePrice' => (float)$r['base_price'],
                'bulk' => 1,
                'decayPerDay' => (float)$r['decay'],
                'rarity' => (float)$r['rarity'],
            ], $commodities),
        ];
    }

    public function stepYear(int $years = 1): array
    {
        $years = max(1, min(50, $years));
        for ($i = 0; $i < $years; $i++) {
            $this->stepOneYear();
        }

        $year = (int)$this->metaGet('year', '0') + $years;
        $this->metaSet('year', (string)$year);

        return $this->getSummary();
    }

    private function stepOneYear(): void
    {
        $rows = $this->db->query('SELECT s.pid,s.commodity_id,s.stock,s.price,s.yearly_prod,s.yearly_cons,c.base_price,c.decay
            FROM sim_state s JOIN commodities c ON c.id=s.commodity_id ORDER BY s.pid,s.commodity_id')->fetchAll();

        $rows = $this->applyProvinceProductionDemand($rows);
        $rows = $this->applyIndustrialGraph($rows);
        $rows = $this->applyLogisticsByDistance($rows);

        $byCommodity = [];
        foreach ($rows as $row) {
            $cid = $row['commodity_id'];
            if (!isset($byCommodity[$cid])) {
                $byCommodity[$cid] = ['totalStock' => 0.0, 'totalProd' => 0.0, 'totalCons' => 0.0];
            }
            $byCommodity[$cid]['totalStock'] += (float)$row['stock'];
            $byCommodity[$cid]['totalProd'] += (float)$row['yearly_prod'];
            $byCommodity[$cid]['totalCons'] += (float)$row['yearly_cons'];
        }

        $upd = $this->db->prepare('UPDATE sim_state SET stock=?, price=? WHERE pid=? AND commodity_id=?');
        foreach ($rows as $row) {
            $cid = $row['commodity_id'];
            $stock = (float)$row['stock'];
            $prod = (float)$row['yearly_prod'];
            $cons = (float)$row['yearly_cons'];
            $decay = (float)$row['decay'];
            $basePrice = (float)$row['base_price'];

            $global = $byCommodity[$cid];
            $balance = ($global['totalProd'] + 1.0) / max(1.0, $global['totalCons']);
            $tradeFactor = max(0.75, min(1.25, 1.0 + (($balance - 1.0) * 0.2)));

            $newStock = max(0.0, ($stock * (1.0 - $decay)) + $prod - ($cons * $tradeFactor));
            $target = max(1.0, $cons * 1.2);
            $scarcity = $target > 0 ? $newStock / $target : 1.0;
            $price = $basePrice * max(0.5, min(3.0, 1.2 - min(1.0, $scarcity) + 0.3));

            $upd->execute([round($newStock, 6), round($price, 6), (int)$row['pid'], $cid]);
        }

        $afterRows = $this->db->query('SELECT s.pid,s.commodity_id,s.stock,s.price,s.yearly_cons FROM sim_state s ORDER BY s.pid,s.commodity_id')->fetchAll();
        $this->applyNeighborTrade($afterRows);
        $this->applyExternalTrade();
    }

    public function provinceReport(int $pid, string $tier = 'all', string $sort = 'value', int $limit = 80, bool $activeOnly = false): ?array
    {
        $provStmt = $this->db->prepare('SELECT * FROM provinces WHERE pid=?');
        $provStmt->execute([$pid]);
        $prov = $provStmt->fetch();
        if (!$prov) {
            return null;
        }

        $derived = $this->provinceDerived((int)$prov['pid'], (int)$prov['hex_count'], (string)$prov['owner'], (string)$prov['free_city_id']);

        $rowsStmt = $this->db->prepare('SELECT s.commodity_id,c.name,c.unit,c.tier,c.base_price,s.stock,s.price,s.yearly_prod,s.yearly_cons
            FROM sim_state s JOIN commodities c ON c.id=s.commodity_id WHERE s.pid=? ORDER BY s.commodity_id');
        $rowsStmt->execute([$pid]);
        $rows = $rowsStmt->fetchAll();

        $items = array_map(static function (array $r): array {
            $target = max(0.0, (float)$r['yearly_cons'] * 1.2);
            $ratio = $target > 0 ? (float)$r['stock'] / $target : 1.0;
            return [
                'id' => $r['commodity_id'],
                'name' => $r['name'],
                'unit' => $r['unit'],
                'tier' => $r['tier'],
                'stock' => round((float)$r['stock'], 2),
                'price' => round((float)$r['price'], 3),
                'basePrice' => (float)$r['base_price'],
                'target' => round($target, 2),
                'ratio' => round($ratio, 3),
                'value' => round((float)$r['stock'] * (float)$r['price'], 2),
            ];
        }, $rows);

        if ($tier !== 'all') {
            $items = array_values(array_filter($items, static fn(array $x): bool => $x['tier'] === $tier));
        }
        if ($activeOnly) {
            $items = array_values(array_filter($items, static fn(array $x): bool => $x['stock'] > 0.01 || $x['target'] > 0.01));
        }

        usort($items, static function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'ratio' => $a['ratio'] <=> $b['ratio'],
                'stock' => $b['stock'] <=> $a['stock'],
                'price' => $b['price'] <=> $a['price'],
                default => $b['value'] <=> $a['value'],
            };
        });

        $neighborsStmt = $this->db->prepare('SELECT n.neighbor_pid,p.name,n.shared_sides FROM province_neighbors n
            LEFT JOIN provinces p ON p.pid=n.neighbor_pid WHERE n.pid=? ORDER BY n.neighbor_pid');
        $neighborsStmt->execute([$pid]);

        return [
            'day' => ((int)$this->metaGet('year', '0')) * 365,
            'pid' => (int)$prov['pid'],
            'name' => $prov['name'],
            'terrain' => $prov['terrain'],
            'centroid' => [(float)$prov['centroid_x'], (float)$prov['centroid_y']],
            'isCity' => $derived['isCity'],
            'pop' => $derived['pop'],
            'infra' => $derived['infra'],
            'transportCap' => $derived['transportCap'],
            'transportUsed' => $derived['transportUsed'],
            'gdpTurnover' => $derived['gdpTurnover'],
            'buildings' => $this->provinceBuildings((int)$prov['pid']),
            'commodities' => array_slice($items, 0, max(10, min(300, $limit))),
            'neighbors' => $neighborsStmt->fetchAll(),
        ];
    }

    public function globalTradeBalance(): array
    {
        $rows = $this->db->query('SELECT c.id,c.name,c.tier,SUM(s.yearly_prod) AS produced,SUM(s.yearly_cons) AS sold,SUM(s.stock) AS stock
            FROM sim_state s JOIN commodities c ON c.id=s.commodity_id GROUP BY c.id,c.name,c.tier ORDER BY c.id')->fetchAll();

        return [
            'periodDays' => 365,
            'rows' => array_map(static fn(array $r): array => [
                'id' => $r['id'],
                'name' => $r['name'],
                'tier' => $r['tier'],
                'produced' => round((float)$r['produced'], 2),
                'sold' => round((float)$r['sold'], 2),
                'saldo' => round((float)$r['produced'] - (float)$r['sold'], 2),
                'stock' => round((float)$r['stock'], 2),
            ], $rows),
        ];
    }

    public function exportSnapshot(): array
    {
        return [
            'meta' => $this->getMeta(),
            'summary' => $this->getSummary(),
            'tradeBalance' => $this->globalTradeBalance(),
        ];
    }

    public function reset(?int $seed = null, ?float $transportUnitCost = null, ?float $tradeFriction = null): array
    {
        if ($seed !== null) {
            $this->metaSet('seed', (string)$seed);
        }
        if ($transportUnitCost !== null) {
            $this->metaSet('transportUnitCost', (string)$transportUnitCost);
        }
        if ($tradeFriction !== null) {
            $this->metaSet('tradeFriction', (string)$tradeFriction);
        }

        $this->db->exec('DELETE FROM sim_state');
        $this->metaSet('year', '0');
        $this->seedEconomyIfNeeded();
        return $this->getSummary();
    }

    private function provinceDerived(int $pid, int $hexCount, string $owner, string $freeCityId): array
    {
        $pop = max(1200, (int)round($hexCount * 42 + (($pid * 97) % 1700)));
        $infra = round(0.45 + (($pid % 9) * 0.12) + min(1.5, $hexCount / 500), 2);
        $isCity = $freeCityId !== '' || (($pid % 11) === 0);
        if ($owner !== '') {
            $infra = round($infra + 0.12, 2);
        }
        $transportCap = round($hexCount * (2.5 + $infra), 2);
        $transportUsed = round($transportCap * (0.45 + (($pid % 5) * 0.08)), 2);
        $gdpTurnover = (int)round($pop * ($infra * 0.8) + $hexCount * 120);

        $ov = $this->db->prepare('SELECT is_city,pop,infra,transport_cap,transport_used,gdp_turnover FROM sim_province_overrides WHERE pid=?');
        $ov->execute([$pid]);
        $o = $ov->fetch();
        if ($o) {
            if ($o['is_city'] !== null) $isCity = ((int)$o['is_city']) === 1;
            if ($o['pop'] !== null) $pop = max(0, (int)$o['pop']);
            if ($o['infra'] !== null) $infra = (float)$o['infra'];
            if ($o['transport_cap'] !== null) $transportCap = (float)$o['transport_cap'];
            if ($o['transport_used'] !== null) $transportUsed = (float)$o['transport_used'];
            if ($o['gdp_turnover'] !== null) $gdpTurnover = (int)$o['gdp_turnover'];
        }

        return [
            'pop' => $pop,
            'infra' => $infra,
            'isCity' => $isCity,
            'transportCap' => $transportCap,
            'transportUsed' => min($transportUsed, $transportCap),
            'gdpTurnover' => $gdpTurnover,
        ];
    }

    private function provinceBuildings(int $pid): array
    {
        $st = $this->db->prepare('SELECT type,count,efficiency FROM sim_province_buildings WHERE pid=? ORDER BY type');
        $st->execute([$pid]);
        $rows = $st->fetchAll();
        if ($rows) {
            return array_map(static fn(array $r): array => [
                'type' => $r['type'],
                'count' => (int)$r['count'],
                'efficiency' => (float)$r['efficiency'],
            ], $rows);
        }

        return [
            ['type' => 'farm_cluster', 'count' => 1 + ($pid % 3), 'efficiency' => round(0.72 + (($pid % 5) * 0.05), 2)],
            ['type' => 'artisan_workshop', 'count' => 1 + (($pid + 1) % 2), 'efficiency' => round(0.68 + (($pid % 7) * 0.04), 2)],
            ['type' => 'warehouse', 'count' => 1, 'efficiency' => round(0.75 + (($pid % 4) * 0.04), 2)],
        ];
    }

    private function hexExamples(int $pid): array
    {
        $st = $this->db->prepare('SELECT hex_id,q,r,cx,cy FROM hexes WHERE province_pid=? ORDER BY hex_id LIMIT 25');
        $st->execute([$pid]);
        return $st->fetchAll();
    }



    public function adminMapData(): array
    {
        $provinces = $this->db->query('SELECT pid,name,kingdom_id,great_house_id,minor_house_id,free_city_id,centroid_x,centroid_y,hex_count,owner FROM provinces ORDER BY pid')->fetchAll();
        $provByPid = [];
        foreach ($provinces as $p) {
            $d = $this->provinceDerived((int)$p['pid'], (int)$p['hex_count'], (string)$p['owner'], (string)$p['free_city_id']);
            $provByPid[(int)$p['pid']] = [
                'pid' => (int)$p['pid'],
                'name' => $p['name'],
                'kingdom_id' => (string)$p['kingdom_id'],
                'great_house_id' => (string)$p['great_house_id'],
                'minor_house_id' => (string)$p['minor_house_id'],
                'free_city_id' => (string)$p['free_city_id'],
                'centroid' => [(float)$p['centroid_x'], (float)$p['centroid_y']],
                'hex_count' => (int)$p['hex_count'],
                'pop' => (int)$d['pop'],
                'gdp' => (int)$d['gdpTurnover'],
                'infra' => (float)$d['infra'],
            ];
        }

        $hexRows = $this->db->query('SELECT q,r,cx,cy,province_pid FROM hexes')->fetchAll();
        $ownerByCoord = [];
        foreach ($hexRows as $h) {
            $ownerByCoord[$h['q'] . ':' . $h['r']] = (int)$h['province_pid'];
        }

        $hexes = [];
        foreach ($hexRows as $h) {
            $q = (int)$h['q'];
            $r = (int)$h['r'];
            $pid = (int)$h['province_pid'];
            $even = ($q % 2) === 0;
            $neighbors = $even
                ? [[1,0],[1,-1],[0,-1],[-1,-1],[-1,0],[0,1]]
                : [[1,1],[1,0],[0,-1],[-1,0],[-1,1],[0,1]];

            $isBorder = false;
            foreach ($neighbors as $d) {
                $np = $ownerByCoord[($q + $d[0]) . ':' . ($r + $d[1])] ?? null;
                if ($np === null || (int)$np !== $pid) {
                    $isBorder = true;
                    break;
                }
            }

            $hexes[] = [
                'cx' => (float)$h['cx'],
                'cy' => (float)$h['cy'],
                'pid' => $pid,
                'border' => $isBorder,
            ];
        }

        return [
            'provinces' => array_values($provByPid),
            'hexes' => $hexes,
        ];
    }

    public function adminProvinceList(): array
    {
        $rows = $this->db->query('SELECT p.pid,p.name,p.centroid_x,p.centroid_y,p.hex_count,p.owner,p.free_city_id, o.is_city,o.pop,o.infra
            FROM provinces p LEFT JOIN sim_province_overrides o ON o.pid=p.pid ORDER BY p.pid')->fetchAll();
        return array_map(function(array $r): array {
            $derived = $this->provinceDerived((int)$r['pid'], (int)$r['hex_count'], (string)$r['owner'], (string)$r['free_city_id']);
            return [
                'pid' => (int)$r['pid'],
                'name' => $r['name'],
                'centroid' => [(float)$r['centroid_x'], (float)$r['centroid_y']],
                'hex_count' => (int)$r['hex_count'],
                'isCity' => $derived['isCity'],
                'pop' => $derived['pop'],
                'infra' => $derived['infra'],
            ];
        }, $rows);
    }

    public function saveProvinceSettings(int $pid, array $payload): array
    {
        $st = $this->db->prepare('INSERT INTO sim_province_overrides(pid,is_city,pop,infra,transport_cap,transport_used,gdp_turnover)
            VALUES(?,?,?,?,?,?,?)
            ON CONFLICT(pid) DO UPDATE SET is_city=excluded.is_city,pop=excluded.pop,infra=excluded.infra,transport_cap=excluded.transport_cap,transport_used=excluded.transport_used,gdp_turnover=excluded.gdp_turnover');
        $st->execute([
            $pid,
            array_key_exists('isCity', $payload) ? ((int)$payload['isCity'] ? 1 : 0) : null,
            isset($payload['pop']) ? max(0, (int)$payload['pop']) : null,
            isset($payload['infra']) ? (float)$payload['infra'] : null,
            isset($payload['transportCap']) ? (float)$payload['transportCap'] : null,
            isset($payload['transportUsed']) ? (float)$payload['transportUsed'] : null,
            isset($payload['gdpTurnover']) ? (float)$payload['gdpTurnover'] : null,
        ]);

        $this->db->prepare('DELETE FROM sim_province_buildings WHERE pid=?')->execute([$pid]);
        $insB = $this->db->prepare('INSERT INTO sim_province_buildings(pid,type,count,efficiency) VALUES(?,?,?,?)');
        foreach (($payload['buildings'] ?? []) as $b) {
            $type = trim((string)($b['type'] ?? ''));
            if ($type === '') continue;
            $insB->execute([$pid, $type, max(0, (int)($b['count'] ?? 0)), max(0.0, (float)($b['efficiency'] ?? 0))]);
        }

        return $this->provinceReport($pid) ?? ['error' => 'province_not_found'];
    }

    public function loadProvinceSettings(int $pid): ?array
    {
        return $this->provinceReport($pid);
    }



    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function applyIndustrialGraph(array $rows): array
    {
        $state = [];
        foreach ($rows as $r) {
            $pid = (int)$r['pid'];
            $cid = (string)$r['commodity_id'];
            $state[$pid][$cid] = $r;
        }

        foreach ($state as $pid => $commodityRows) {
            $buildings = $this->provinceBuildings((int)$pid);
            $buildingPower = 0.0;
            foreach ($buildings as $b) {
                $buildingPower += max(0.0, (float)$b['count']) * max(0.0, (float)$b['efficiency']);
            }
            $powerFactor = max(0.2, 0.6 + min(4.0, $buildingPower) * 0.18);

            foreach ($this->industrialGraph as $recipe) {
                $maxCycles = INF;
                foreach ($recipe['input'] as $inCid => $inQty) {
                    $stock = (float)($state[$pid][$inCid]['stock'] ?? 0.0);
                    $maxCycles = min($maxCycles, $stock / max(1e-6, $inQty));
                }
                if (!is_finite($maxCycles) || $maxCycles <= 0.0) {
                    continue;
                }

                $cycles = min($recipe['base_rate'] * $powerFactor, $maxCycles * 0.22);
                if ($cycles <= 0.0) {
                    continue;
                }

                foreach ($recipe['input'] as $inCid => $inQty) {
                    if (!isset($state[$pid][$inCid])) {
                        continue;
                    }
                    $state[$pid][$inCid]['stock'] = max(0.0, (float)$state[$pid][$inCid]['stock'] - ($inQty * $cycles));
                    $state[$pid][$inCid]['yearly_cons'] = (float)$state[$pid][$inCid]['yearly_cons'] + ($inQty * $cycles * 0.65);
                }
                foreach ($recipe['output'] as $outCid => $outQty) {
                    if (!isset($state[$pid][$outCid])) {
                        continue;
                    }
                    $state[$pid][$outCid]['stock'] = (float)$state[$pid][$outCid]['stock'] + ($outQty * $cycles);
                    $state[$pid][$outCid]['yearly_prod'] = (float)$state[$pid][$outCid]['yearly_prod'] + ($outQty * $cycles * 0.8);
                }
            }
        }

        $out = [];
        foreach ($state as $rowsByCom) {
            foreach ($rowsByCom as $r) {
                $out[] = $r;
            }
        }
        usort($out, static fn(array $a, array $b): int => ((int)$a['pid'] <=> (int)$b['pid']) ?: strcmp((string)$a['commodity_id'], (string)$b['commodity_id']));
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function applyLogisticsByDistance(array $rows): array
    {
        $dist = $this->distanceMatrix();
        $state = [];
        foreach ($rows as $r) {
            $state[(int)$r['pid']][(string)$r['commodity_id']] = $r;
        }

        foreach ($state as $pid => $comRows) {
            foreach ($comRows as $cid => $row) {
                $target = max(1.0, (float)$row['yearly_cons'] * 1.2);
                $need = max(0.0, $target - (float)$row['stock']);
                if ($need < 0.01) continue;

                $bestSource = null;
                $bestScore = INF;
                foreach ($state as $spid => $srows) {
                    if ($spid === $pid || !isset($srows[$cid])) continue;
                    $s = $srows[$cid];
                    $ssurplus = max(0.0, (float)$s['stock'] - max(1.0, (float)$s['yearly_cons'] * 1.2));
                    if ($ssurplus < 0.01) continue;
                    $d = $dist[$pid][$spid] ?? INF;
                    if (!is_finite($d)) continue;
                    $score = ((float)$s['price']) + ($d * $this->transportUnitCost());
                    if ($score < $bestScore) {
                        $bestScore = $score;
                        $bestSource = $spid;
                    }
                }

                if ($bestSource !== null) {
                    $d = $dist[$pid][$bestSource] ?? 0.0;
                    $available = max(0.0, (float)$state[$bestSource][$cid]['stock'] - max(1.0, (float)$state[$bestSource][$cid]['yearly_cons'] * 1.2));
                    $cap = max(0.0, (1.0 - $this->tradeFriction()) * 8.0 / (1.0 + $d * 0.25));
                    $moved = min($need * 0.35, $available * 0.25, $cap);
                    if ($moved > 0.0) {
                        $state[$bestSource][$cid]['stock'] = (float)$state[$bestSource][$cid]['stock'] - $moved;
                        $state[$pid][$cid]['stock'] = (float)$state[$pid][$cid]['stock'] + $moved;
                    }
                }
            }
        }

        $out = [];
        foreach ($state as $rowsByCom) {
            foreach ($rowsByCom as $r) {
                $out[] = $r;
            }
        }
        usort($out, static fn(array $a, array $b): int => ((int)$a['pid'] <=> (int)$b['pid']) ?: strcmp((string)$a['commodity_id'], (string)$b['commodity_id']));
        return $out;
    }

    /** @return array<int,array<int,float>> */
    private function distanceMatrix(): array
    {
        if ($this->distanceCache !== null) {
            return $this->distanceCache;
        }

        $neighbors = $this->db->query('SELECT pid,neighbor_pid,shared_sides FROM province_neighbors')->fetchAll();
        $graph = [];
        $nodes = [];
        foreach ($neighbors as $n) {
            $a = (int)$n['pid'];
            $b = (int)$n['neighbor_pid'];
            $w = 1.0 / max(1.0, (float)$n['shared_sides']);
            $graph[$a][$b] = min($graph[$a][$b] ?? INF, $w);
            $nodes[$a] = true;
            $nodes[$b] = true;
        }

        $dAll = [];
        foreach (array_keys($nodes) as $src) {
            $dist = [$src => 0.0];
            $visited = [];
            while (true) {
                $u = null;
                $best = INF;
                foreach ($dist as $node => $d) {
                    if (isset($visited[$node])) continue;
                    if ($d < $best) { $best = $d; $u = (int)$node; }
                }
                if ($u === null) break;
                $visited[$u] = true;
                foreach (($graph[$u] ?? []) as $v => $w) {
                    $nd = $dist[$u] + $w;
                    if (!isset($dist[$v]) || $nd < $dist[$v]) {
                        $dist[$v] = $nd;
                    }
                }
            }
            $dAll[$src] = $dist;
        }

        $this->distanceCache = $dAll;
        return $this->distanceCache;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function applyProvinceProductionDemand(array $rows): array
    {
        $prov = $this->db->query('SELECT pid,hex_count,owner,free_city_id FROM provinces ORDER BY pid')->fetchAll();
        $pMeta = [];
        foreach ($prov as $p) {
            $d = $this->provinceDerived((int)$p['pid'], (int)$p['hex_count'], (string)$p['owner'], (string)$p['free_city_id']);
            $pMeta[(int)$p['pid']] = $d;
        }

        $byPidBuildings = [];
        $brows = $this->db->query('SELECT pid,type,count,efficiency FROM sim_province_buildings')->fetchAll();
        foreach ($brows as $b) {
            $byPidBuildings[(int)$b['pid']][] = $b;
        }

        foreach ($rows as &$r) {
            $pid = (int)$r['pid'];
            $cid = (string)$r['commodity_id'];
            $meta = $pMeta[$pid] ?? ['isCity'=>false,'infra'=>1.0,'pop'=>5000];
            $prod = (float)$r['yearly_prod'];
            $cons = (float)$r['yearly_cons'];

            $infraBoost = 1.0 + (max(0.0, (float)$meta['infra']) - 1.0) * 0.08;
            $cityDemand = (!empty($meta['isCity']) ? 1.12 : 1.0);
            $popDemand = 1.0 + min(0.35, ((float)$meta['pop']) / 250000.0);

            if (in_array($cid, ['mutabryukva','bread','meat_cans','mutachicken'], true)) {
                $cons *= $cityDemand * $popDemand;
            }
            if (in_array($cid, ['e_parts','engine_kit','steel','truck_civil','air_purifier_home'], true)) {
                $cons *= $cityDemand;
            }
            $prod *= $infraBoost;

            foreach (($byPidBuildings[$pid] ?? []) as $b) {
                $eff = max(0.0, (float)$b['efficiency']);
                $count = max(0, (int)$b['count']);
                $power = $count * $eff;
                $type = (string)$b['type'];

                if ($type === 'farm_cluster' && in_array($cid, ['mutabryukva','mutachicken','bread'], true)) {
                    $prod *= 1.0 + ($power * 0.025);
                }
                if ($type === 'artisan_workshop' && in_array($cid, ['wood_processed','steel','e_parts'], true)) {
                    $prod *= 1.0 + ($power * 0.02);
                }
                if ($type === 'dockyard' && in_array($cid, ['truck_civil','engine_kit','steel'], true)) {
                    $prod *= 1.0 + ($power * 0.03);
                }
                if (in_array($type, ['warehouse','test_factory'], true) && in_array($cid, ['wood_raw','stone','iron_ore','petrochem_raw'], true)) {
                    $cons *= max(0.85, 1.0 - ($power * 0.01));
                }
            }

            $r['yearly_prod'] = $prod;
            $r['yearly_cons'] = $cons;
        }
        unset($r);

        return $rows;
    }

    private function applyNeighborTrade(array $rows): void
    {
        $neighbors = $this->db->query('SELECT pid,neighbor_pid,shared_sides FROM province_neighbors')->fetchAll();
        $book = [];
        foreach ($rows as $r) {
            $pid = (int)$r['pid'];
            $cid = $r['commodity_id'];
            $target = max(1.0, (float)$r['yearly_cons'] * 1.2);
            $book[$pid][$cid] = [
                'stock' => (float)$r['stock'],
                'price' => (float)$r['price'],
                'target' => $target,
            ];
        }

        foreach ($neighbors as $n) {
            $a = (int)$n['pid'];
            $b = (int)$n['neighbor_pid'];
            $capacity = max(0.0, (float)$n['shared_sides']) * (1.0 / $this->transportUnitCost()) * (1.0 - $this->tradeFriction()) * 1.2;
            if (!isset($book[$a]) || !isset($book[$b])) continue;
            foreach ($book[$a] as $cid => $_) {
                if (!isset($book[$b][$cid])) continue;
                $aRef = &$book[$a][$cid];
                $bRef = &$book[$b][$cid];

                $aSur = max(0.0, $aRef['stock'] - $aRef['target']);
                $bNeed = max(0.0, $bRef['target'] - $bRef['stock']);
                if ($aSur > 0.01 && $bNeed > 0.01 && $aRef['price'] <= $bRef['price']) {
                    $flow = min($capacity, $aSur * (0.25 + (1.0 - $this->tradeFriction()) * 0.25), $bNeed * 0.5);
                    $aRef['stock'] -= $flow;
                    $bRef['stock'] += $flow;
                }

                $bSur = max(0.0, $bRef['stock'] - $bRef['target']);
                $aNeed = max(0.0, $aRef['target'] - $aRef['stock']);
                if ($bSur > 0.01 && $aNeed > 0.01 && $bRef['price'] <= $aRef['price']) {
                    $flow = min($capacity, $bSur * (0.25 + (1.0 - $this->tradeFriction()) * 0.25), $aNeed * 0.5);
                    $bRef['stock'] -= $flow;
                    $aRef['stock'] += $flow;
                }
                unset($aRef, $bRef);
            }
        }

        $upd = $this->db->prepare('UPDATE sim_state SET stock=? WHERE pid=? AND commodity_id=?');
        foreach ($book as $pid => $coms) {
            foreach ($coms as $cid => $vals) {
                $upd->execute([round($vals['stock'], 6), $pid, $cid]);
            }
        }
    }



    private function applyExternalTrade(): void
    {
        $rows = $this->db->query('SELECT pid,commodity_id,stock,price,yearly_cons FROM sim_state ORDER BY commodity_id,pid')->fetchAll();
        $by = [];
        foreach ($rows as $r) {
            $cid = $r['commodity_id'];
            $by[$cid][] = $r;
        }

        $upd = $this->db->prepare('UPDATE sim_state SET stock=?, price=? WHERE pid=? AND commodity_id=?');
        foreach ($by as $cid => $items) {
            $avgPrice = array_sum(array_map(static fn($x) => (float)$x['price'], $items)) / max(1, count($items));
            foreach ($items as $it) {
                $target = max(1.0, (float)$it['yearly_cons'] * 1.2);
                $stock = (float)$it['stock'];
                $price = (float)$it['price'];

                $need = max(0.0, $target - $stock);
                $surplus = max(0.0, $stock - $target);

                $inflow = $need * 0.08 * (1.0 - $this->tradeFriction());
                $outflow = $surplus * 0.05 * (1.0 - $this->tradeFriction());

                $stock = max(0.0, $stock + $inflow - $outflow);
                $price = max(0.5, min($avgPrice * 2.5, $price * (1.0 + (($need - $outflow) / $target) * 0.04)));

                $upd->execute([round($stock, 6), round($price, 6), (int)$it['pid'], $cid]);
            }
        }
    }

    private function transportUnitCost(): float
    {
        return max(0.01, (float)$this->metaGet('transportUnitCost', '0.35'));
    }

    private function tradeFriction(): float
    {
        return max(0.0, min(0.95, (float)$this->metaGet('tradeFriction', '0.05')));
    }

    private function metaGet(string $key, ?string $default = null): ?string
    {
        $st = $this->db->prepare('SELECT v FROM meta WHERE k=?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    }

    private function metaSet(string $key, string $value): void
    {
        $st = $this->db->prepare('INSERT INTO meta(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v');
        $st->execute([$key, $value]);
    }
}
