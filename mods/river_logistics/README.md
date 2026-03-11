# River Logistics Sidecar Module

Съёмный sidecar-модуль для `adminmap`, который строит речной логистический слой только по `map.png` и `provinces_id.png`, без использования hexmap и без правки основных сохранений.

## Что делает

- хранит реки как **sidecar-полилинии**, а не пытается полностью угадать их по художественной карте;
- вычисляет, какие провинции имеют доступ к каждой реке;
- строит комбинированный граф:
  - сухопутное соседство провинций,
  - речные связи по ходу русла,
  - пересадки провинция ↔ речной доступ;
- считает маршруты для:
  - `cargo` — торговые обозы / баржи / речные суда,
  - `army` — армии и крупные отряды,
  - `courier` — быстрые сообщения и малые группы;
- поддерживает штапельные узлы на реке как forced-stop / penalty;
- пишет результаты только в `data/module_runtime/river_logistics/`.

## Почему так

Автоматическое распознавание узких рек по `map.png` ненадёжно: местами русло может быть шириной в 1–2 пикселя, мосты и подписи ломают маску, берега стилизованы. Поэтому модуль работает как гибрид:

1. Пользователь вручную или полуавтоматически размечает главные реки в `river_routes.json`.
2. Модуль сопоставляет эти полилинии с провинциями через `provinces_id.png`.
3. Дальше всё уже считается автоматически.

Это даёт стабильный и съёмный слой логистики.

## Структура

- `admin.html` — простая админка-редактор
- `js/admin.js` — клиентский редактор полилиний
- `api/load_routes.php` — загрузка sidecar
- `api/save_routes.php` — сохранение sidecar
- `api/run.php` — пересборка речного графа
- `api/route.php` — поиск маршрута между провинциями
- `lib/RiverLogisticsModule.php` — основная логика
- `tools/run.php` — CLI-пересборка
- `tools/cleanup.php` — очистка sidecar runtime

## Sidecar-файлы

### `data/sidecar/river_routes.json`
Главные реки и их свойства.

### `data/sidecar/province_color_map.json`
Сопоставление RGB-цветов из `provinces_id.png` с ID провинций.

### `data/sidecar/river_calibration.json`
Параметры sampling, порогов и штрафов.

### `data/sidecar/staple_rights_river.json`
Речные штапельные узлы.

## Запуск

```bash
php mods/river_logistics/tools/run.php --dry-run=1
php mods/river_logistics/tools/run.php
```

## HTTP

- `GET /mods/river_logistics/api/load_routes.php`
- `POST /mods/river_logistics/api/save_routes.php`
- `POST /mods/river_logistics/api/run.php`
- `POST /mods/river_logistics/api/route.php`

## Удаление

```bash
php mods/river_logistics/tools/cleanup.php
rm -rf mods/river_logistics
rm -rf data/module_runtime/river_logistics
```

## Выходные файлы

- `data/module_runtime/river_logistics/network.json`
- `data/module_runtime/river_logistics/summary.json`
- `data/module_runtime/river_logistics/unresolved_colors.json`
- `data/module_runtime/river_logistics/preview_routes.json`

## Ограничения

- Нужен `php-gd` для чтения PNG.
- Модуль не лезет в `map_state.json` и turn-сейвы.
- Речные русла задаются как sidecar-полилинии; это сознательное решение.
