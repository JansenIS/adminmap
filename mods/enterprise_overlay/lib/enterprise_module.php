<?php

declare(strict_types=1);

function eo_rr(float $v, int $p = 4): float { return round($v, $p); }
function eo_clamp(float $v, float $min, float $max): float { return max($min, min($max, $v)); }
function eo_clampi(int $v, int $min, int $max): int { return max($min, min($max, $v)); }


function eo_lc(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
}


function eo_find_repo_root(string $startDir): string
{
    $dir = realpath($startDir) ?: $startDir;
    for ($i = 0; $i < 8; $i++) {
        if (is_file($dir . '/admin.html') && is_dir($dir . '/data')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    throw new RuntimeException('Не удалось найти корень adminmap от: ' . $startDir);
}

function eo_runtime_dir(string $root): string
{
    return $root . '/data/module_runtime/enterprise_overlay';
}

function eo_trace_path(string $root): string
{
    return eo_runtime_dir($root) . '/trace.json';
}

function eo_state_overlay_path(string $root): string
{
    return eo_runtime_dir($root) . '/state_overlay.json';
}

function eo_turn_overlay_path(string $root, int $year): string
{
    return eo_runtime_dir($root) . '/turn_overlays/' . $year . '.json';
}

function eo_ensure_runtime_dirs(string $root): void
{
    $base = eo_runtime_dir($root);
    if (!is_dir($base) && !mkdir($base, 0777, true) && !is_dir($base)) {
        throw new RuntimeException('Не удалось создать директорию runtime: ' . $base);
    }
    $turns = $base . '/turn_overlays';
    if (!is_dir($turns) && !mkdir($turns, 0777, true) && !is_dir($turns)) {
        throw new RuntimeException('Не удалось создать директорию turn_overlays: ' . $turns);
    }
}

function eo_read_json_file(string $path, array $default = []): array
{
    if (!is_file($path)) return $default;
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') return $default;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function eo_write_json_file(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('Не удалось сериализовать JSON: ' . $path);
    if (file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('Не удалось записать файл: ' . $path);
    }
}

function eo_load_state(string $root): array
{
    $path = $root . '/data/map_state.json';
    if (!is_file($path)) throw new RuntimeException('Не найден data/map_state.json');
    return eo_read_json_file($path, []);
}

function eo_seed_unit(int $pid, string $salt): float
{
    $hash = hash('sha256', $pid . '|' . $salt);
    $chunk = substr($hash, 0, 12);
    $int = hexdec($chunk);
    return $int / 281474976710655.0;
}

function eo_seed_int(int $pid, string $salt, int $min, int $max): int
{
    if ($max <= $min) return $min;
    return (int)floor($min + eo_seed_unit($pid, $salt) * (($max + 1) - $min - 1e-9));
}

function eo_turn_api_terrain_key(string $terrain): string
{
    $terrain = trim(eo_lc($terrain));
    return match ($terrain) {
        'город', 'city' => 'город',
        'озёра/реки', 'озера/реки', 'река', 'озеро', 'river', 'lake' => 'озёра/реки',
        'побережье', 'coast', 'coastal' => 'побережье',
        'остров', 'island' => 'остров',
        'горы', 'mountains', 'mountain' => 'горы',
        'лес', 'forest' => 'лес',
        'холмы', 'hills', 'hill' => 'холмы',
        'болото', 'swamp', 'marsh' => 'болото',
        default => $terrain !== '' ? $terrain : 'равнины',
    };
}

function eo_entity_ref(array $province): array
{
    foreach ([
        ['kingdoms', 'kingdom_id'],
        ['great_houses', 'great_house_id'],
        ['minor_houses', 'minor_house_id'],
        ['free_cities', 'free_city_id'],
        ['special_territories', 'special_territory_id'],
    ] as [$type, $field]) {
        $id = trim((string)($province[$field] ?? ''));
        if ($id !== '') return [$type, $id];
    }
    return ['', ''];
}

function eo_catalog(): array
{
    return [
        'greenhouse_court' => ['label' => 'Крестьянский тепличный двор', 'jobs' => 40, 'prod' => ['mutabrukva_t' => 60.0, 'fodder_t' => 15.0], 'cons' => ['fertilizer_t' => 0.3, 'clean_water_u' => 18.0]],
        'seignorial_greenhouse' => ['label' => 'Сеньориальный парниковый комплекс', 'jobs' => 140, 'prod' => ['mutabrukva_t' => 240.0, 'fodder_t' => 70.0], 'cons' => ['fertilizer_t' => 1.2, 'clean_water_u' => 64.0]],
        'noble_greenhouse' => ['label' => 'Дворянский парник', 'jobs' => 28, 'prod' => ['noble_crops_t' => 1.2], 'cons' => ['fertilizer_t' => 0.1, 'clean_water_u' => 3.0]],
        'mutagoat_pasture' => ['label' => 'Пастбище мутакоз', 'jobs' => 18, 'prod' => ['meat_t' => 4.0, 'hides_u' => 100.0, 'manure_t' => 20.0], 'cons' => ['fodder_t' => 12.0]],
        'mutacur_yard' => ['label' => 'Двор мутакур', 'jobs' => 10, 'prod' => ['meat_t' => 2.0, 'manure_t' => 8.0], 'cons' => ['fodder_t' => 8.0]],
        'compost_yard' => ['label' => 'Компостный двор', 'jobs' => 8, 'prod' => ['fertilizer_t' => 1.5], 'cons' => ['manure_t' => 22.0, 'botanical_waste_t' => 5.0]],
        'bakery_mill' => ['label' => 'Мельнично-пекарный двор', 'jobs' => 14, 'prod' => ['bread_t' => 65.0], 'cons' => ['mutabrukva_t' => 95.0, 'clean_water_u' => 5.0, 'fuel_u' => 8.0]],
        'forest_artel' => ['label' => 'Лесная артель', 'jobs' => 18, 'prod' => ['timber_m3' => 140.0], 'cons' => ['tools_u' => 2.0]],
        'fiber_farm' => ['label' => 'Волокнистое хозяйство', 'jobs' => 16, 'prod' => ['fiber_t' => 1.6], 'cons' => ['fertilizer_t' => 0.1]],
        'sawmill' => ['label' => 'Деревообработка', 'jobs' => 12, 'prod' => ['processed_wood_m3' => 60.0], 'cons' => ['timber_m3' => 84.0]],
        'textile_manufactory' => ['label' => 'Текстильная мануфактура', 'jobs' => 18, 'prod' => ['cloth_simple_m' => 160.0], 'cons' => ['fiber_t' => 1.2, 'fuel_u' => 1.0]],
        'city_textile_manufactory' => ['label' => 'Городская текстильная мануфактура', 'jobs' => 22, 'prod' => ['cloth_urban_m' => 95.0], 'cons' => ['fiber_t' => 1.4, 'fuel_u' => 1.4]],
        'tannery' => ['label' => 'Кожевня', 'jobs' => 16, 'prod' => ['leather_m2' => 45.0], 'cons' => ['hides_u' => 70.0, 'clean_water_u' => 3.0]],
        'smithy' => ['label' => 'Кузница', 'jobs' => 12, 'prod' => ['forged_parts_u' => 50.0, 'tools_u' => 30.0], 'cons' => ['steel_t' => 0.9, 'fuel_u' => 2.0]],
        'repair_yard' => ['label' => 'Ремонтный двор', 'jobs' => 15, 'prod' => ['repair_capacity_u' => 80.0], 'cons' => ['forged_parts_u' => 10.0, 'processed_wood_m3' => 3.0, 'cloth_simple_m' => 20.0]],
        'iron_mine' => ['label' => 'Железорудная артель', 'jobs' => 24, 'prod' => ['iron_ore_t' => 12.0], 'cons' => ['tools_u' => 2.0]],
        'coke_yard' => ['label' => 'Коксовая артель', 'jobs' => 18, 'prod' => ['coke_t' => 8.0], 'cons' => ['timber_m3' => 16.0]],
        'metallurgy_yard' => ['label' => 'Металлургический двор', 'jobs' => 30, 'prod' => ['steel_t' => 12.0, 'rolled_steel_sheets_u' => 175.0], 'cons' => ['iron_ore_t' => 20.0, 'coke_t' => 9.0]],
        'advanced_smithy' => ['label' => 'Продвинутая кузница', 'jobs' => 10, 'prod' => ['precision_parts_u' => 12.0, 'high_precision_steel_u' => 15.0], 'cons' => ['steel_t' => 0.7, 'fuel_u' => 1.0]],
        'filters_shop' => ['label' => 'Мануфактура фильтров', 'jobs' => 10, 'prod' => ['villadium_filter_personal_u' => 90.0], 'cons' => ['cloth_simple_m' => 18.0, 'processed_wood_m3' => 1.0]],
        'battery_shop' => ['label' => 'Аккумуляторная мастерская', 'jobs' => 10, 'prod' => ['power_module_u' => 6.0], 'cons' => ['lead_t' => 0.4, 'copper_t' => 0.08, 'electrolyte_l' => 72.0]],
        'basic_electronics' => ['label' => 'Базовая электроника', 'jobs' => 8, 'prod' => ['basic_electronics_u' => 2.0], 'cons' => ['copper_t' => 0.06, 'silver_kg' => 1.0, 'polymer_cases_u' => 1.0]],
        'advanced_electronics' => ['label' => 'Продвинутая электроника', 'jobs' => 10, 'prod' => ['advanced_controller_u' => 0.8], 'cons' => ['basic_electronics_u' => 2.0, 'precision_parts_u' => 2.0]],
        'motor_assembly' => ['label' => 'Сборка движков', 'jobs' => 12, 'prod' => ['small_motor_u' => 0.5], 'cons' => ['rolled_steel_sheets_u' => 20.0, 'copper_t' => 0.05, 'power_module_u' => 1.0]],
        'pneumatic_rifles' => ['label' => 'Линия электропневматических винтовок', 'jobs' => 10, 'prod' => ['pneumatic_rifle_u' => 1.0], 'cons' => ['high_precision_steel_u' => 2.0, 'small_motor_u' => 0.2, 'basic_electronics_u' => 0.3]],
        'gauss_rifles' => ['label' => 'Линия винтовок Гаусса', 'jobs' => 8, 'prod' => ['gauss_rifle_u' => 0.2], 'cons' => ['high_precision_steel_u' => 4.0, 'advanced_controller_u' => 0.3, 'power_module_u' => 0.6]],
        'motorcycles' => ['label' => 'Линия мотоциклов', 'jobs' => 12, 'prod' => ['motorcycle_u' => 0.25], 'cons' => ['small_motor_u' => 1.0, 'power_module_u' => 2.0, 'rolled_steel_sheets_u' => 35.0]],
        'river_boats' => ['label' => 'Линия речных катеров', 'jobs' => 20, 'prod' => ['river_boat_u' => 0.2], 'cons' => ['processed_wood_m3' => 18.0, 'rolled_steel_sheets_u' => 80.0, 'power_module_u' => 6.0]],
    ];
}

function eo_add_goods(array &$dst, array $delta, float $mult = 1.0): void
{
    foreach ($delta as $k => $v) {
        $dst[$k] = eo_rr((float)($dst[$k] ?? 0) + ((float)$v * $mult));
    }
}

function eo_profile_for_province(array $province): string
{
    $pid = (int)($province['pid'] ?? 0);
    $population = max(0, (int)($province['population'] ?? 0));
    $terrain = eo_turn_api_terrain_key((string)($province['terrain'] ?? ''));
    $isCity = $terrain === 'город';
    $isRiver = $terrain === 'озёра/реки';
    $isCoast = in_array($terrain, ['побережье', 'остров'], true);
    if ($isCity && $population >= 120000) return 'great_city';
    if ($isCity) return 'trade_city';
    if ($isRiver || $isCoast) {
        return eo_seed_unit($pid, 'profile_water') > 0.55 ? 'trade_rural' : 'bread_feud';
    }
    if (in_array($terrain, ['горы', 'холмы'], true)) {
        return eo_seed_unit($pid, 'profile_metal') > 0.42 ? 'iron_march' : 'pastoral_march';
    }
    if ($terrain === 'лес') {
        return eo_seed_unit($pid, 'profile_forest') > 0.50 ? 'timber_feud' : 'bread_feud';
    }
    return eo_seed_unit($pid, 'profile_plain') > 0.60 ? 'pastoral_march' : 'bread_feud';
}

function eo_building_counts(array $province, string $profile): array
{
    $p = max(0, (int)($province['population'] ?? 0));
    $pid = (int)($province['pid'] ?? 0);
    $counts = [];

    $scale = max(1.0, $p / 25000.0);
    $g = static function (int $min, int $max) use ($scale): int {
        return max(0, (int)round((($min + $max) / 2) * $scale));
    };

    switch ($profile) {
        case 'bread_feud':
            $counts = [
                'greenhouse_court' => max(4, (int)round($p / 650)),
                'seignorial_greenhouse' => max(1, (int)round($p / 3800)),
                'noble_greenhouse' => max(0, (int)floor($p / 18000)),
                'mutagoat_pasture' => max(2, (int)round($p / 1200)),
                'mutacur_yard' => max(2, (int)round($p / 900)),
                'compost_yard' => max(1, (int)round($p / 1800)),
                'bakery_mill' => max(3, (int)round($p / 500)),
                'forest_artel' => max(1, (int)round($p / 6000)),
                'sawmill' => max(1, (int)round($p / 9000)),
                'fiber_farm' => max(1, (int)round($p / 8500)),
                'textile_manufactory' => max(1, (int)round($p / 14000)),
                'tannery' => max(1, (int)round($p / 12000)),
                'smithy' => max(1, (int)round($p / 5000)),
                'repair_yard' => max(1, (int)round($p / 18000)),
            ];
            break;
        case 'pastoral_march':
            $counts = [
                'greenhouse_court' => max(2, (int)round($p / 1200)),
                'seignorial_greenhouse' => max(1, (int)round($p / 9000)),
                'mutagoat_pasture' => max(4, (int)round($p / 650)),
                'mutacur_yard' => max(4, (int)round($p / 520)),
                'compost_yard' => max(1, (int)round($p / 1500)),
                'bakery_mill' => max(2, (int)round($p / 950)),
                'forest_artel' => max(1, (int)round($p / 5000)),
                'sawmill' => max(1, (int)round($p / 8000)),
                'fiber_farm' => max(1, (int)round($p / 8000)),
                'textile_manufactory' => max(1, (int)round($p / 11000)),
                'tannery' => max(2, (int)round($p / 6000)),
                'smithy' => max(1, (int)round($p / 4500)),
                'repair_yard' => max(1, (int)round($p / 14000)),
            ];
            break;
        case 'timber_feud':
            $counts = [
                'greenhouse_court' => max(3, (int)round($p / 900)),
                'seignorial_greenhouse' => max(1, (int)round($p / 6000)),
                'mutagoat_pasture' => max(2, (int)round($p / 1600)),
                'mutacur_yard' => max(2, (int)round($p / 1200)),
                'compost_yard' => max(1, (int)round($p / 2200)),
                'bakery_mill' => max(2, (int)round($p / 750)),
                'forest_artel' => max(3, (int)round($p / 2500)),
                'sawmill' => max(2, (int)round($p / 4000)),
                'fiber_farm' => max(1, (int)round($p / 7000)),
                'textile_manufactory' => max(1, (int)round($p / 15000)),
                'tannery' => max(1, (int)round($p / 15000)),
                'smithy' => max(1, (int)round($p / 6000)),
                'repair_yard' => max(1, (int)round($p / 12000)),
            ];
            break;
        case 'iron_march':
            $counts = [
                'greenhouse_court' => max(2, (int)round($p / 1700)),
                'seignorial_greenhouse' => max(1, (int)round($p / 12000)),
                'mutagoat_pasture' => max(2, (int)round($p / 1800)),
                'mutacur_yard' => max(2, (int)round($p / 1600)),
                'compost_yard' => max(1, (int)round($p / 3000)),
                'bakery_mill' => max(2, (int)round($p / 1250)),
                'forest_artel' => max(1, (int)round($p / 5000)),
                'sawmill' => max(1, (int)round($p / 9000)),
                'iron_mine' => max(2, (int)round($p / 2500)),
                'coke_yard' => max(1, (int)round($p / 4500)),
                'metallurgy_yard' => max(1, (int)round($p / 11000)),
                'smithy' => max(2, (int)round($p / 5000)),
                'advanced_smithy' => max(0, (int)floor($p / 24000)),
                'repair_yard' => max(1, (int)round($p / 9000)),
                'battery_shop' => max(0, (int)floor($p / 35000)),
                'pneumatic_rifles' => max(0, (int)floor($p / 42000)),
            ];
            break;
        case 'trade_rural':
            $counts = [
                'greenhouse_court' => max(3, (int)round($p / 850)),
                'seignorial_greenhouse' => max(1, (int)round($p / 5000)),
                'mutagoat_pasture' => max(2, (int)round($p / 1700)),
                'mutacur_yard' => max(2, (int)round($p / 1200)),
                'compost_yard' => max(1, (int)round($p / 2300)),
                'bakery_mill' => max(2, (int)round($p / 800)),
                'forest_artel' => max(1, (int)round($p / 6000)),
                'sawmill' => max(1, (int)round($p / 8000)),
                'fiber_farm' => max(1, (int)round($p / 9000)),
                'textile_manufactory' => max(1, (int)round($p / 9000)),
                'city_textile_manufactory' => max(0, (int)floor($p / 20000)),
                'tannery' => max(1, (int)round($p / 10000)),
                'smithy' => max(1, (int)round($p / 5000)),
                'repair_yard' => max(1, (int)round($p / 10000)),
                'river_boats' => max(0, (int)floor($p / 35000)),
            ];
            break;
        case 'trade_city':
            $counts = [
                'greenhouse_court' => max(1, (int)round($p / 7000)),
                'seignorial_greenhouse' => max(1, (int)round($p / 20000)),
                'noble_greenhouse' => max(0, (int)floor($p / 50000)),
                'mutagoat_pasture' => max(1, (int)round($p / 8000)),
                'mutacur_yard' => max(1, (int)round($p / 7000)),
                'compost_yard' => max(1, (int)round($p / 7000)),
                'bakery_mill' => max(3, (int)round($p / 3000)),
                'forest_artel' => max(1, (int)round($p / 15000)),
                'sawmill' => max(2, (int)round($p / 12000)),
                'fiber_farm' => max(1, (int)round($p / 16000)),
                'textile_manufactory' => max(2, (int)round($p / 10000)),
                'city_textile_manufactory' => max(1, (int)round($p / 13000)),
                'tannery' => max(2, (int)round($p / 12000)),
                'smithy' => max(2, (int)round($p / 9000)),
                'advanced_smithy' => max(0, (int)floor($p / 30000)),
                'repair_yard' => max(2, (int)round($p / 11000)),
                'filters_shop' => max(0, (int)floor($p / 45000)),
                'battery_shop' => max(0, (int)floor($p / 50000)),
                'basic_electronics' => max(0, (int)floor($p / 60000)),
                'motor_assembly' => max(0, (int)floor($p / 90000)),
                'pneumatic_rifles' => max(0, (int)floor($p / 70000)),
                'river_boats' => max(0, (int)floor($p / 55000)),
            ];
            break;
        case 'great_city':
            $counts = [
                'greenhouse_court' => max(1, (int)round($p / 12000)),
                'seignorial_greenhouse' => max(1, (int)round($p / 26000)),
                'noble_greenhouse' => max(1, (int)floor($p / 40000)),
                'mutagoat_pasture' => max(1, (int)round($p / 12000)),
                'mutacur_yard' => max(1, (int)round($p / 9000)),
                'compost_yard' => max(1, (int)round($p / 9000)),
                'bakery_mill' => max(5, (int)round($p / 3500)),
                'forest_artel' => max(1, (int)round($p / 20000)),
                'sawmill' => max(2, (int)round($p / 15000)),
                'fiber_farm' => max(1, (int)round($p / 22000)),
                'textile_manufactory' => max(3, (int)round($p / 14000)),
                'city_textile_manufactory' => max(2, (int)round($p / 16000)),
                'tannery' => max(2, (int)round($p / 18000)),
                'smithy' => max(3, (int)round($p / 13000)),
                'advanced_smithy' => max(1, (int)round($p / 50000)),
                'repair_yard' => max(3, (int)round($p / 14000)),
                'filters_shop' => max(1, (int)round($p / 60000)),
                'battery_shop' => max(1, (int)round($p / 70000)),
                'basic_electronics' => max(1, (int)round($p / 70000)),
                'advanced_electronics' => max(0, (int)floor($p / 180000)),
                'motor_assembly' => max(0, (int)floor($p / 120000)),
                'pneumatic_rifles' => max(0, (int)floor($p / 100000)),
                'gauss_rifles' => max(0, (int)floor($p / 220000)),
                'motorcycles' => max(0, (int)floor($p / 180000)),
                'river_boats' => max(0, (int)floor($p / 80000)),
            ];
            break;
    }

    // Rare quotas: deterministically keep true rarities rare.
    $rarities = [
        'advanced_electronics' => 0.97,
        'gauss_rifles' => 0.985,
        'motorcycles' => 0.975,
        'motor_assembly' => 0.95,
        'filters_shop' => 0.94,
    ];
    foreach ($rarities as $key => $threshold) {
        if (($counts[$key] ?? 0) > 0 && eo_seed_unit($pid, 'rare_' . $key) < $threshold) {
            $counts[$key] = 0;
        }
    }

    foreach ($counts as $key => $value) {
        if ($value <= 0) unset($counts[$key]);
    }
    ksort($counts);
    return $counts;
}

function eo_generate_baseline(array $province): array
{
    $catalog = eo_catalog();
    $profile = eo_profile_for_province($province);
    $counts = eo_building_counts($province, $profile);
    $jobs = 0;
    $prod = [];
    $cons = [];
    $summary = [];
    foreach ($counts as $key => $count) {
        if (!isset($catalog[$key])) continue;
        $row = $catalog[$key];
        $jobs += (int)$row['jobs'] * $count;
        eo_add_goods($prod, $row['prod'], (float)$count);
        eo_add_goods($cons, $row['cons'], (float)$count);
        $summary[] = $row['label'] . ' × ' . $count;
    }

    $population = max(0, (int)($province['population'] ?? 0));
    $householdFactor = 2.15;
    $dependents = (int)round($population * $householdFactor);
    $needBread = eo_rr($dependents * 0.13, 2);
    $needMeat = eo_rr($dependents * 0.009, 2);
    $needCloth = eo_rr($dependents * 0.24, 2);
    $needTools = eo_rr(max(1.0, $jobs / 9.0), 2);

    return [
        'profile' => $profile,
        'counts' => $counts,
        'jobs_planned' => $jobs,
        'production_base' => $prod,
        'consumption_internal' => $cons,
        'consumption_population' => [
            'bread_t' => $needBread,
            'meat_t' => $needMeat,
            'cloth_simple_m' => $needCloth,
            'tools_u' => $needTools,
        ],
        'summary' => $summary,
    ];
}

function eo_collect_arrierban_armies(array $state): array
{
    $sources = [];
    foreach (['army_registry', 'armies'] as $k) {
        if (isset($state[$k]) && is_array($state[$k])) $sources[] = $state[$k];
    }
    if (isset($state['army_state']) && is_array($state['army_state'])) $sources[] = is_array($state['army_state']['armies'] ?? null) ? $state['army_state']['armies'] : $state['army_state'];
    if (isset($state['war']) && is_array($state['war'])) $sources[] = is_array($state['war']['armies'] ?? null) ? $state['war']['armies'] : $state['war'];
    if (isset($state['military']) && is_array($state['military'])) $sources[] = is_array($state['military']['armies'] ?? null) ? $state['military']['armies'] : $state['military'];

    $all = [];
    foreach ($sources as $list) {
        foreach ($list as $k => $army) {
            if (!is_array($army)) continue;
            $json = eo_lc((string)json_encode($army, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $isArrierban = !empty($army['is_arrierban']) || !empty($army['arrierban']) || !empty($army['levy'])
                || str_contains($json, 'arrierban') || str_contains($json, 'арьербан') || str_contains($json, 'levy');
            if (!$isArrierban) continue;
            $active = !isset($army['active']) || !empty($army['active']);
            $status = eo_lc(trim((string)($army['status'] ?? 'active')));
            if (in_array($status, ['destroyed', 'deleted', 'disbanded', 'dismissed', 'archived'], true)) $active = false;
            $id = trim((string)($army['army_id'] ?? $army['id'] ?? $army['key'] ?? ''));
            if ($id === '') $id = 'army_' . substr(hash('sha256', json_encode($army, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), 0, 14);
            $size = 0;
            foreach (['size', 'troops', 'count', 'men', 'headcount', 'current_size', 'soldiers', 'strength'] as $field) {
                if (isset($army[$field])) { $size = max($size, (int)round((float)$army[$field])); }
            }
            if ($size <= 0 && is_array($army['units'] ?? null)) {
                foreach ($army['units'] as $unit) {
                    if (!is_array($unit)) continue;
                    $size += (int)round((float)($unit['size'] ?? $unit['count'] ?? $unit['men'] ?? $unit['strength'] ?? 0));
                }
            }
            $originPid = 0;
            foreach (['origin_pid', 'home_pid', 'source_pid', 'capital_pid', 'province_pid', 'pid'] as $field) {
                if ((int)($army[$field] ?? 0) > 0) { $originPid = (int)$army[$field]; break; }
            }
            if ($originPid <= 0 && is_array($army['origin'] ?? null)) {
                foreach (['pid', 'province_pid', 'home_pid'] as $field) {
                    if ((int)($army['origin'][$field] ?? 0) > 0) { $originPid = (int)$army['origin'][$field]; break; }
                }
            }
            $cat = 'militia';
            if (str_contains($json, 'knight') || str_contains($json, 'рыцар')) $cat = 'knights';
            elseif (str_contains($json, 'neht') || str_contains($json, 'нехт')) $cat = 'nehts';
            elseif (str_contains($json, 'sergeant') || str_contains($json, 'серж')) $cat = 'sergeants';
            $all[$id] = ['army_id' => $id, 'size' => $size, 'origin_pid' => $originPid, 'category' => $cat, 'active' => $active];
        }
    }
    return $all;
}

function eo_category_weights(string $category): array
{
    return match ($category) {
        'knights' => ['labor' => 1.45, 'property' => 1.8, 'repair' => 1.6, 'recovery' => 0.22, 'dead' => 0.20, 'disabled' => 0.10],
        'nehts' => ['labor' => 1.25, 'property' => 1.35, 'repair' => 1.3, 'recovery' => 0.20, 'dead' => 0.18, 'disabled' => 0.09],
        'sergeants' => ['labor' => 1.1, 'property' => 1.1, 'repair' => 1.0, 'recovery' => 0.18, 'dead' => 0.16, 'disabled' => 0.08],
        default => ['labor' => 1.0, 'property' => 0.8, 'repair' => 0.7, 'recovery' => 0.15, 'dead' => 0.14, 'disabled' => 0.07],
    };
}

function eo_update_trace(array $state, array $trace, string $runLabel): array
{
    $armies = eo_collect_arrierban_armies($state);
    $prev = $trace['army_trace'] ?? [];
    $provinceTrace = $trace['province_trace'] ?? [];
    $current = [];
    $events = [];

    foreach ($armies as $id => $army) {
        if (!$army['active']) continue;
        $current[$id] = $army;
    }

    foreach ($current as $id => $army) {
        $prevArmy = is_array($prev[$id] ?? null) ? $prev[$id] : null;
        if ($prevArmy === null) continue;
        $oldSize = max(0, (int)($prevArmy['size'] ?? 0));
        $newSize = max(0, (int)($army['size'] ?? 0));
        if ($newSize >= $oldSize) continue;
        $loss = $oldSize - $newSize;
        $pid = (int)($army['origin_pid'] ?: ($prevArmy['origin_pid'] ?? 0));
        if ($pid <= 0) continue;
        $w = eo_category_weights((string)($army['category'] ?? $prevArmy['category'] ?? 'militia'));
        $dead = (int)round($loss * $w['dead']);
        $disabled = (int)round($loss * $w['disabled']);
        $recovery = max(0, $loss - $dead - $disabled);
        if (!isset($provinceTrace[$pid])) $provinceTrace[$pid] = [];
        $provinceTrace[$pid]['permanent_losses'] = (int)($provinceTrace[$pid]['permanent_losses'] ?? 0) + $dead;
        $provinceTrace[$pid]['disabled_pool'] = (int)($provinceTrace[$pid]['disabled_pool'] ?? 0) + $disabled;
        $provinceTrace[$pid]['recovery_pool'] = (int)($provinceTrace[$pid]['recovery_pool'] ?? 0) + $recovery;
        $provinceTrace[$pid]['property_damage'] = eo_rr((float)($provinceTrace[$pid]['property_damage'] ?? 0) + ($loss * $w['property']), 2);
        $provinceTrace[$pid]['repair_backlog'] = eo_rr((float)($provinceTrace[$pid]['repair_backlog'] ?? 0) + ($loss * $w['repair']), 2);
        $events[] = ['type' => 'losses', 'army_id' => $id, 'pid' => $pid, 'loss' => $loss, 'dead' => $dead, 'disabled' => $disabled, 'recovery' => $recovery];
    }

    foreach ($prev as $id => $prevArmy) {
        if (isset($current[$id])) continue;
        $pid = (int)($prevArmy['origin_pid'] ?? 0);
        if ($pid <= 0) continue;
        $size = max(0, (int)($prevArmy['size'] ?? 0));
        if (!isset($provinceTrace[$pid])) $provinceTrace[$pid] = [];
        $returned = (int)round($size * 0.82);
        $delayed = max(0, $size - $returned);
        $provinceTrace[$pid]['returned_recently'] = (int)($provinceTrace[$pid]['returned_recently'] ?? 0) + $returned;
        $provinceTrace[$pid]['recovery_pool'] = (int)($provinceTrace[$pid]['recovery_pool'] ?? 0) + $delayed;
        $provinceTrace[$pid]['property_damage'] = eo_rr((float)($provinceTrace[$pid]['property_damage'] ?? 0) + ($size * 0.25), 2);
        $provinceTrace[$pid]['repair_backlog'] = eo_rr((float)($provinceTrace[$pid]['repair_backlog'] ?? 0) + ($size * 0.20), 2);
        $events[] = ['type' => 'disbanded', 'army_id' => $id, 'pid' => $pid, 'returned' => $returned, 'delayed' => $delayed];
    }

    foreach ($provinceTrace as $pid => &$pt) {
        $recoveryPool = max(0, (int)($pt['recovery_pool'] ?? 0));
        $healed = (int)floor($recoveryPool * 0.35);
        if ($healed > 0) {
            $pt['recovery_pool'] = $recoveryPool - $healed;
            $pt['returned_recently'] = (int)($pt['returned_recently'] ?? 0) + $healed;
        }
        $pt['property_damage'] = eo_rr(max(0.0, (float)($pt['property_damage'] ?? 0) * 0.94), 2);
        $pt['repair_backlog'] = eo_rr(max(0.0, (float)($pt['repair_backlog'] ?? 0) * 0.93), 2);
    }
    unset($pt);

    return [
        'army_trace' => $current,
        'province_trace' => $provinceTrace,
        'events' => $events,
        'last_run' => ['label' => $runLabel, 'ts' => date(DATE_ATOM)],
    ];
}

function eo_scale_goods(array $goods, float $factor): array
{
    $out = [];
    foreach ($goods as $k => $v) $out[$k] = eo_rr((float)$v * $factor);
    return $out;
}

function eo_goods_delta(array $prod, array $cons): array
{
    $keys = array_unique(array_merge(array_keys($prod), array_keys($cons)));
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = eo_rr((float)($prod[$k] ?? 0) - (float)($cons[$k] ?? 0));
    }
    ksort($out);
    return $out;
}

function eo_build_overlay(array $state, array $trace, string $runLabel): array
{
    $trace = eo_update_trace($state, $trace, $runLabel);
    $provinceTrace = $trace['province_trace'] ?? [];
    $activeArmies = $trace['army_trace'] ?? [];

    $activeByPid = [];
    foreach ($activeArmies as $army) {
        $pid = (int)($army['origin_pid'] ?? 0);
        if ($pid <= 0) continue;
        $w = eo_category_weights((string)($army['category'] ?? 'militia'));
        $activeByPid[$pid] = eo_rr((float)($activeByPid[$pid] ?? 0) + ((int)($army['size'] ?? 0) * $w['labor']), 2);
    }

    $provincesOverlay = [];
    $entities = [];
    $world = [
        'base_population' => 0,
        'effective_population' => 0,
        'jobs_planned' => 0,
        'jobs_active' => 0,
        'trade_turnover_est' => 0.0,
        'active_labor_removed' => 0.0,
        'permanent_losses' => 0,
        'recovery_pool' => 0,
        'alerts' => 0,
    ];

    foreach (($state['provinces'] ?? []) as $idx => $province) {
        if (!is_array($province)) continue;
        $pid = (int)($province['pid'] ?? $idx);
        if ($pid <= 0) continue;
        $population = max(0, (int)($province['population'] ?? 0));
        $baseline = eo_generate_baseline($province);
        $pt = is_array($provinceTrace[$pid] ?? null) ? $provinceTrace[$pid] : [];
        $activeRemoved = (float)($activeByPid[$pid] ?? 0.0);
        $recoveryPool = max(0, (int)($pt['recovery_pool'] ?? 0));
        $permanentLosses = max(0, (int)($pt['permanent_losses'] ?? 0));
        $disabled = max(0, (int)($pt['disabled_pool'] ?? 0));
        $effective = max(0.0, $population - $activeRemoved - $recoveryPool - ($disabled * 0.5) - $permanentLosses);
        $jobsPlanned = max(0, (int)$baseline['jobs_planned']);
        $laborFactor = $jobsPlanned > 0 ? eo_clamp($effective / max(1.0, $jobsPlanned), 0.12, 1.12) : 1.0;
        $prod = eo_scale_goods($baseline['production_base'], $laborFactor);
        $cons = $baseline['consumption_internal'];
        eo_add_goods($cons, $baseline['consumption_population']);
        $delta = eo_goods_delta($prod, $cons);
        $breadRatio = ($cons['bread_t'] ?? 0) > 0 ? eo_clamp(($prod['bread_t'] ?? 0) / max(0.01, (float)$cons['bread_t']), 0.0, 2.5) : 1.0;
        $meatRatio = ($cons['meat_t'] ?? 0) > 0 ? eo_clamp(($prod['meat_t'] ?? 0) / max(0.01, (float)$cons['meat_t']), 0.0, 2.5) : 1.0;
        $repairBacklog = (float)($pt['repair_backlog'] ?? 0.0);
        $repairCap = (float)($prod['repair_capacity_u'] ?? 0.0);
        $repairRatio = $repairCap > 0 ? eo_clamp(($repairCap - $repairBacklog) / max(1.0, $repairCap), -2.0, 1.5) : ($repairBacklog > 0 ? -1.0 : 0.0);
        $stress = eo_clamp(1.20 - (0.35 * $breadRatio) - (0.12 * $meatRatio) - (0.28 * $laborFactor) - (0.18 * max(0.0, $repairRatio)), 0.0, 2.0);
        $stability = eo_clamp(1.12 - (0.42 * $stress), 0.0, 1.2);
        $tradeTurnover = 0.0;
        foreach ($delta as $k => $v) $tradeTurnover += abs((float)$v);
        $industryScore = eo_rr(((float)($prod['rolled_steel_sheets_u'] ?? 0) / 40.0) + ((float)($prod['basic_electronics_u'] ?? 0) * 8.0) + ((float)($prod['small_motor_u'] ?? 0) * 10.0) + ((float)($prod['gauss_rifle_u'] ?? 0) * 50.0), 2);
        $supplyScore = eo_rr(max(0.0, ($breadRatio * 0.45) + ($meatRatio * 0.10) + (max(0.0, $repairRatio) * 0.15) + ($laborFactor * 0.30)), 3);
        $mobilizationCap = eo_rr(max(0.0, $effective * (0.045 + min(0.04, $industryScore / 2500.0))), 1);
        $professionalCap = eo_rr(max(0.0, $effective * (0.004 + min(0.01, $industryScore / 9000.0))), 1);
        $alerts = [];
        if ($breadRatio < 0.90) $alerts[] = 'bread_deficit';
        if ($laborFactor < 0.80) $alerts[] = 'labor_shortage';
        if ($repairRatio < 0.0) $alerts[] = 'repair_overload';
        if ($stress > 0.75) $alerts[] = 'high_stress';
        if ($activeRemoved > 0) $alerts[] = 'arrierban_active';
        if ($permanentLosses > 0 || $recoveryPool > 0) $alerts[] = 'war_demography_drag';

        $overlay = [
            'pid' => $pid,
            'profile' => $baseline['profile'],
            'summary' => $baseline['summary'],
            'population' => [
                'base' => $population,
                'effective' => (int)floor($effective),
                'active_labor_removed' => eo_rr($activeRemoved, 2),
                'permanent_losses' => $permanentLosses,
                'disabled_pool' => $disabled,
                'recovery_pool' => $recoveryPool,
                'returned_recently' => (int)($pt['returned_recently'] ?? 0),
            ],
            'employment' => [
                'jobs_planned' => $jobsPlanned,
                'jobs_active_est' => (int)floor(min($jobsPlanned, $effective)),
                'occupation_ratio' => eo_rr($jobsPlanned > 0 ? min(1.0, $effective / $jobsPlanned) : 1.0, 4),
                'idle_est' => max(0, (int)floor($effective - min($jobsPlanned, $effective))),
            ],
            'production' => $prod,
            'consumption' => $cons,
            'delta' => $delta,
            'scores' => [
                'bread_ratio' => eo_rr($breadRatio, 4),
                'meat_ratio' => eo_rr($meatRatio, 4),
                'industry_score' => $industryScore,
                'repair_ratio' => eo_rr($repairRatio, 4),
                'supply_score' => $supplyScore,
                'stress' => eo_rr($stress, 4),
                'stability' => eo_rr($stability, 4),
                'trade_turnover_est' => eo_rr($tradeTurnover, 2),
                'mobilization_cap' => $mobilizationCap,
                'professional_cap' => $professionalCap,
            ],
            'war_drag' => [
                'property_damage' => eo_rr((float)($pt['property_damage'] ?? 0), 2),
                'repair_backlog' => eo_rr($repairBacklog, 2),
            ],
            'alerts' => $alerts,
        ];
        $provincesOverlay[(string)$pid] = $overlay;

        [$entityType, $entityId] = eo_entity_ref($province);
        if ($entityType !== '' && $entityId !== '') {
            $key = $entityType . ':' . $entityId;
            if (!isset($entities[$key])) {
                $entities[$key] = [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'province_count' => 0,
                    'base_population' => 0,
                    'effective_population' => 0,
                    'jobs_planned' => 0,
                    'jobs_active_est' => 0,
                    'trade_turnover_est' => 0.0,
                    'supply_score_sum' => 0.0,
                    'industry_score' => 0.0,
                    'mobilization_cap' => 0.0,
                    'professional_cap' => 0.0,
                    'alerts' => [],
                ];
            }
            $entities[$key]['province_count']++;
            $entities[$key]['base_population'] += $population;
            $entities[$key]['effective_population'] += (int)floor($effective);
            $entities[$key]['jobs_planned'] += $jobsPlanned;
            $entities[$key]['jobs_active_est'] += (int)floor(min($jobsPlanned, $effective));
            $entities[$key]['trade_turnover_est'] = eo_rr($entities[$key]['trade_turnover_est'] + $tradeTurnover, 2);
            $entities[$key]['supply_score_sum'] = eo_rr($entities[$key]['supply_score_sum'] + $supplyScore, 4);
            $entities[$key]['industry_score'] = eo_rr($entities[$key]['industry_score'] + $industryScore, 2);
            $entities[$key]['mobilization_cap'] = eo_rr($entities[$key]['mobilization_cap'] + $mobilizationCap, 1);
            $entities[$key]['professional_cap'] = eo_rr($entities[$key]['professional_cap'] + $professionalCap, 1);
            $entities[$key]['alerts'] = array_values(array_unique(array_merge($entities[$key]['alerts'], $alerts)));
        }

        $world['base_population'] += $population;
        $world['effective_population'] += (int)floor($effective);
        $world['jobs_planned'] += $jobsPlanned;
        $world['jobs_active'] += (int)floor(min($jobsPlanned, $effective));
        $world['trade_turnover_est'] = eo_rr($world['trade_turnover_est'] + $tradeTurnover, 2);
        $world['active_labor_removed'] = eo_rr($world['active_labor_removed'] + $activeRemoved, 2);
        $world['permanent_losses'] += $permanentLosses;
        $world['recovery_pool'] += $recoveryPool;
        $world['alerts'] += count($alerts);
    }

    foreach ($entities as &$entity) {
        $entity['supply_score_avg'] = $entity['province_count'] > 0 ? eo_rr($entity['supply_score_sum'] / $entity['province_count'], 4) : 0.0;
        unset($entity['supply_score_sum']);
    }
    unset($entity);
    ksort($provincesOverlay, SORT_NATURAL);
    ksort($entities, SORT_NATURAL);

    return [
        'module' => 'enterprise_overlay',
        'version' => '1.0.0',
        'generated_at' => date(DATE_ATOM),
        'run_label' => $runLabel,
        'trace' => $trace,
        'world' => $world,
        'provinces' => $provincesOverlay,
        'entities' => $entities,
    ];
}

function eo_cleanup_runtime(string $root): array
{
    $dir = eo_runtime_dir($root);
    $removed = [];
    if (!is_dir($dir)) return ['removed' => $removed, 'message' => 'runtime already absent'];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $fileInfo) {
        $path = $fileInfo->getPathname();
        if ($fileInfo->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
            $removed[] = $path;
        }
    }
    @rmdir($dir);
    return ['removed' => $removed, 'message' => 'runtime removed'];
}

function eo_run_module(string $root, array $options = []): array
{
    $dryRun = !empty($options['dry_run']);
    $turnYear = isset($options['turn_year']) ? (int)$options['turn_year'] : 0;
    $writeTurn = !empty($options['attach_to_turn']) && $turnYear > 0;
    $runLabel = trim((string)($options['run_label'] ?? 'manual'));
    if ($runLabel === '') $runLabel = 'manual';

    eo_ensure_runtime_dirs($root);
    $state = eo_load_state($root);
    $trace = eo_read_json_file(eo_trace_path($root), []);
    $overlay = eo_build_overlay($state, $trace, $runLabel);

    $summary = [
        'module' => $overlay['module'],
        'version' => $overlay['version'],
        'generated_at' => $overlay['generated_at'],
        'run_label' => $overlay['run_label'],
        'world' => $overlay['world'],
        'province_count' => count($overlay['provinces']),
        'entity_count' => count($overlay['entities']),
        'state_overlay_path' => eo_state_overlay_path($root),
        'trace_path' => eo_trace_path($root),
        'turn_overlay_path' => $writeTurn ? eo_turn_overlay_path($root, $turnYear) : null,
        'dry_run' => $dryRun,
    ];

    if (!$dryRun) {
        eo_write_json_file(eo_state_overlay_path($root), $overlay);
        eo_write_json_file(eo_trace_path($root), $overlay['trace']);
        if ($writeTurn) {
            eo_write_json_file(eo_turn_overlay_path($root, $turnYear), $overlay);
        }
    }

    return ['ok' => true, 'summary' => $summary, 'overlay' => $overlay];
}
