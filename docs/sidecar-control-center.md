# Sidecar Control Center

## Что это

`sidecar_admin.html` — единая панель управления sidecar-модулями, встроенная в главный UX-контур `ui_alt`.

Ключевые принципы:
- core adminmap работает без sidecar;
- sidecar-рантайм хранится отдельно в `data/module_runtime/<module_id>/`;
- старые `map_state.json` и turn-файлы не требуют миграций;
- attach-to-turn пишет только в `turn.sidecar_overlays.<module_id>`.

## Как встроено в `ui_alt`

Основной вход добавлен в:
- `admin_ui_alt.html` (topbar кнопка `Sidecar`);
- `admin_orders_ui_alt.html` (быстрый переход в header);
- `player_admin_ui_alt.html` (быстрый переход в topbar).

Таким образом, пользователь альтернативного UI открывает sidecar-панель напрямую, без перехода в legacy-админку.

## Архитектура API

Централизованный dispatcher:
- `api/sidecar/modules/index.php` — реестр модулей (`mods/*/module.json`), валидация и статус runtime;
- `api/sidecar/run/index.php` — безопасный запуск run endpoint зарегистрированного модуля;
- `api/sidecar/download/index.php` — скачивание runtime-файлов модуля;
- `api/sidecar/cleanup/index.php` — очистка runtime конкретного модуля;
- `api/sidecar/pipelines/index.php` — список/запуск orchestrator pipelines.

Общая логика в `api/sidecar/lib.php`:
- path safety (только внутри `mods/` для run-endpoint);
- чтение и нормализация `module.json`;
- runtime-метрики (наличие, размер, mtime);
- attach overlay в `data/turns/turn_<year>.json` в namespaced-блок.

## Реестр модулей

Каждый модуль объявляется через `mods/<module_id>/module.json`.

Минимально рекомендуется указывать:
- `id`, `name/title`, `version`;
- `entry_admin`, `entry_ui_alt`;
- `api.run`;
- `runtime_dir`;
- `supports` (`dry_run`, `attach_to_turn`, `download`, `cleanup`);
- `outputs`, `tags`.

## Как добавить новый модуль в unified ui_alt panel

1. Создать папку `mods/<new_module>/` и `module.json`.
2. Указать `id` и `api.run` с путем внутри `mods/<new_module>/...`.
3. Указать `entry_ui_alt` (например, `sidecar_admin.html?module=<new_module>`).
4. Указать `runtime_dir` и `outputs`.
5. (Опционально) `supports.attach_to_turn=true`, если модуль умеет attach.

После этого модуль автоматически появится в `sidecar_admin.html` через `api/sidecar/modules/`.

## Как безопасно удалить модуль

1. В панели нажать cleanup для модуля (или удалить `data/module_runtime/<module_id>/`).
2. Удалить `mods/<module_id>/`.
3. При необходимости убрать шаг из `data/sidecar_pipelines.json`.

Core-контур не ломается: sidecar остаётся полностью съёмным.

## Pipelines

Реестр pipeline хранится в `data/sidecar_pipelines.json`.

Предустановленные сценарии:
- `logistics_full`: `river_logistics -> marine_provinces -> raster_logistics`;
- `economy_full`: `enterprise_overlay -> enterprise_war_runtime`;
- `war_postprocess`: `arrierban_demography -> enterprise_war_runtime`.

Запуск из UI:
1. Открыть `sidecar_admin.html`.
2. В блоке `Pipeline registry` нажать `Запустить pipeline`.
3. Смотреть пошаговый результат в журнале.
