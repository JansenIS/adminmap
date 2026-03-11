# Marine Provinces Sidecar Module

Съёмный модуль для `adminmap`, который строит крупные морские "провинции" на базе двух растров:
- `map.png`
- `provinces_id.png`

Он создаёт:
- территориальные морские зоны прибрежных провинций;
- крупные нейтральные морские провинции;
- морской граф маршрутов;
- театры морских битв;
- sidecar-runtime, который можно безопасно удалить.

## Зависимости

Для работы с PNG модулю нужен PHP GD (`php-gd` или `php8.x-gd`).

## Что НЕ делает

- не меняет `map_state.json`;
- не переписывает turn-сейвы;
- не трогает казну, армии, дипломатию или hexmap;
- не требует внедрения в core.

## Установка

Распаковать архив в корень `adminmap`.

## Запуск

CLI:

```bash
php mods/marine_provinces/tools/run.php --dry-run=1
php mods/marine_provinces/tools/run.php
```

HTTP:
- `POST mods/marine_provinces/api/run.php`
- `POST mods/marine_provinces/api/route.php`

## Runtime

Модуль пишет только сюда:

- `data/module_runtime/marine_provinces/network.json`
- `data/module_runtime/marine_provinces/summary.json`
- `data/module_runtime/marine_provinces/marine_overlay.png`

## Удаление

```bash
php mods/marine_provinces/tools/cleanup.php
rm -rf mods/marine_provinces
rm -rf data/module_runtime/marine_provinces
```

## Sidecar-настройки

### `province_color_map.json`
Ручная привязка цвета из `provinces_id.png` к ID/названию провинции.

### `marine_calibration.json`
Основные параметры:
- `downsample`
- `territorial_radius_cells`
- `target_neutral_area_cells`
- `sea_detect`

### `ports.json`
Необязательная ручная разметка портов. Если пусто, все прибрежные провинции считаются имеющими базовый морской выход.

### `sea_labels.json`
Можно переименовать нейтральные морские провинции вручную.

## Идея модели

1. Из `provinces_id.png` извлекаются обычные сухопутные провинции.
2. Из `map.png` или `sea_mask.png` извлекается морская вода.
3. Каждая прибрежная провинция получает собственную территориальную морскую зону.
4. Остальная морская вода режется на крупные нейтральные морские провинции.
5. По ним строится морской граф для логистики и морских битв.

## Ограничения

Это модуль для морской логистики. Он не пытается идеально распознавать узкие реки в 1–2 пикселя. Для речной логистики лучше использовать отдельный sidecar с якорными точками русел.
