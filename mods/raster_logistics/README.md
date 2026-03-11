# Raster Logistics Unified Overlay

Это новая версия `mods/raster_logistics`, которая умеет объединять:

- сухопутное соседство провинций по `provinces_id.png`;
- sidecar-runtime речного модуля `mods/river_logistics`;
- sidecar-runtime морского модуля `mods/marine_provinces`.

Итогом становится единый логистический граф `land + river + marine`, пригодный для:

- общей торговли;
- сухопутных, речных и морских маршрутов;
- передвижения армий;
- курьерских путей;
- будущих надстроек вроде блокад, контроля проливов и штапельных узлов.

## Принцип интеграции

Модуль не переписывает core-сейвы и не лезет в `hexmap`.

Он читает:

- `provinces_id.png`
- `provinces.json` и/или `data/map_state.json`
- `data/module_runtime/river_logistics/network.json`
- `data/module_runtime/marine_provinces/network.json`

Если включён `auto_run_submodules`, модуль сам пытается вызвать:

- `RiverLogisticsModule`
- `MarineProvincesModule`

и только потом собирает unified graph.

## Что пишет

Только sidecar runtime:

- `data/module_runtime/raster_logistics/network.json`
- `data/module_runtime/raster_logistics/summary.json`
- `data/module_runtime/raster_logistics/unresolved_colors.json`

Удаление безопасно для основного state.

## Узлы графа

- `P:<pid>` — сухопутная провинция
- `RA:<route>:<pid>` — речной access-узел
- `RP:<route>:<port>` — речной порт
- `M:<marine_id>` — морская провинция
- `MLP:<pid>` — морской land-port провинции

## Классы рёбер

- `land`
- `river`
- `river_embark`
- `river_disembark`
- `river_port_transfer`
- `river_junction`
- `sea_neutral`
- `sea_territorial`
- `sea_strait`
- `sea_port_exit`
- `land_to_marine_port`

## Запуск

CLI:

```bash
php mods/raster_logistics/tools/run.php
php mods/raster_logistics/tools/run.php --auto_run_submodules=1
```

HTTP:

- `POST /mods/raster_logistics/api/run.php`
- `POST /mods/raster_logistics/api/route.php`
- `GET  /mods/raster_logistics/api/download.php`

## Пример маршрута

```json
{
  "from": "12",
  "to": "48",
  "mode": "cargo"
}
```

## Режимы

Поддерживаются:

- `cargo`
- `army`
- `courier`
- `naval`

Их веса можно править в `data/sidecar/unified_mode_rules.json`.

## Что важно

Этот модуль **не заменяет** речной и морской модули. Он стоит поверх них как агрегатор.

Лучшая практика такая:

1. держать `river_logistics` как источник русел и речного графа;
2. держать `marine_provinces` как источник морских зон и морского графа;
3. использовать этот `raster_logistics` как единый оркестратор маршрутов.

## Удаление

```bash
php mods/raster_logistics/tools/cleanup.php
rm -rf mods/raster_logistics
rm -rf data/module_runtime/raster_logistics
```

## Зависимости

- `php-gd`
- если нужен единый граф с водой, должны быть установлены `mods/river_logistics` и/или `mods/marine_provinces`
