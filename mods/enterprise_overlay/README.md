# Enterprise Overlay Module for adminmap

Изолируемый модуль поверх `adminmap`.

## Что он делает

Модуль рассчитывает **отдельный sidecar-overlay** для:
- стартового профиля провинции и занятости;
- товарного производства/потребления;
- продовольственной устойчивости;
- арьербанного изъятия трудоспособных;
- потерь, recovery pool, ремонтного хвоста и имущественного урона;
- supply / stress / stability / mobilization capacity.

## Что он НЕ делает

- Не правит `data/map_state.json`.
- Не правит turn-сейвы основного движка.
- Не меняет казну, налоги, upkeep и формулы арьербана.
- Не требует патчить core-файлы репозитория.

## Куда пишет данные

Только сюда:

`data/module_runtime/enterprise_overlay/`

Файлы:
- `state_overlay.json`
- `trace.json`
- `turn_overlays/<YEAR>.json`

Удаление этой директории безопасно для сохранений сервера.

## Установка

1. Распаковать архив **в корень repo `adminmap`**.
2. В результате появится папка:

`mods/enterprise_overlay/`

3. Открыть:

`http://<HOST>:8080/mods/enterprise_overlay/admin.html`

или запускать через CLI из корня repo:

```bash
php mods/enterprise_overlay/tools/run.php --dry-run=1
php mods/enterprise_overlay/tools/run.php --run-label=manual
php mods/enterprise_overlay/tools/run.php --attach-to-turn=1 --turn-year=1462
```

## Удаление модуля

Сначала очистить runtime-след:

```bash
php mods/enterprise_overlay/tools/cleanup.php
```

Затем просто удалить папку модуля:

```bash
rm -rf mods/enterprise_overlay
```

Если нужно полностью убрать все следы модуля:

```bash
rm -rf data/module_runtime/enterprise_overlay
rm -rf mods/enterprise_overlay
```

Основные сейвы игры при этом не страдают, потому что модуль хранит всё отдельно.

## HTTP API

### Пересчёт / dry-run

`POST /mods/enterprise_overlay/api/run.php`

Тело:

```json
{
  "action": "run",
  "dry_run": true,
  "attach_to_turn": false,
  "turn_year": 0,
  "run_label": "manual"
}
```

### Последний overlay

`POST /mods/enterprise_overlay/api/run.php`

```json
{ "action": "latest" }
```

### Очистка runtime

`POST /mods/enterprise_overlay/api/run.php`

```json
{ "action": "cleanup" }
```

## Совместимость

Модуль написан так, чтобы терпимо читать разные варианты хранения армий:
- `army_registry`
- `armies`
- `army_state`
- `war`
- `military`

Если некоторые поля отсутствуют, модуль не падает, а просто считает overlay по доступным данным.
