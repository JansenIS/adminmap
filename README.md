# adminmap — проект карты и turn-based экономики

Этот репозиторий ставится **целиком**, а не по отдельным кускам:
- `adminmap` (основная карта/админка): `admin.html`, `index.html`, `data/`, `map.png`, `provinces_id.png`;

## Быстрая установка на Linux (Ubuntu/Debian)

```bash
# 1) системные пакеты
sudo apt update
sudo apt install -y git curl php-cli php-mbstring

# 2) клонируем ВЕСЬ проект
cd /opt
sudo git clone <URL_ВАШЕГО_РЕПО> adminmap
sudo chown -R $USER:$USER /opt/adminmap
cd /opt/adminmap
```

## Запуск всего проекта

Открой два терминала.

### Терминал A: основная карта/adminmap

```bash
cd /opt/adminmap
php -S 0.0.0.0:8080
```


## Что где доступно

- Основная админка карты: `http://<SERVER_IP>:8080/admin.html`
- Ограниченная админка сущности по токену: `http://<SERVER_IP>:8080/player_admin.html?token=...`
- Публичная карта: `http://<SERVER_IP>:8080/index.html`
- Админка ходов: `http://<SERVER_IP>:8080/turn_admin.html`

## Проверка после установки

```bash
# основная карта
curl -I http://127.0.0.1:8080/admin.html

```

## Применение production-конфига веб-сервера (gzip/br + canonical API routes)

Ниже команды, чтобы применить конфиги из репозитория (`deploy/`) на сервере.

### Вариант A: Nginx + PHP-FPM

```bash
cd /opt/adminmap

# 1) установить конфиг сайта
sudo cp deploy/nginx/adminmap.conf /etc/nginx/sites-available/adminmap.conf
sudo ln -sf /etc/nginx/sites-available/adminmap.conf /etc/nginx/sites-enabled/adminmap.conf

# 2) (опционально) включить brotli snippet, если модуль ngx_brotli доступен
sudo mkdir -p /etc/nginx/snippets
sudo cp deploy/nginx/snippets/adminmap-brotli.conf /etc/nginx/snippets/adminmap-brotli.conf
# затем раскомментировать строку include в /etc/nginx/sites-available/adminmap.conf:
# include /etc/nginx/snippets/adminmap-brotli.conf;

# 3) проверить и перезагрузить
sudo nginx -t
sudo systemctl reload nginx
```

### Вариант B: Apache

```bash
cd /opt/adminmap

# 1) включить модули
sudo a2enmod rewrite headers deflate
# brotli опционально (если доступен)
sudo a2enmod brotli || true

# 2) установить сайт
sudo cp deploy/apache/adminmap.conf /etc/apache2/sites-available/adminmap.conf
sudo a2ensite adminmap.conf

# 3) проверить и перезагрузить
sudo apachectl configtest
sudo systemctl reload apache2
```

### Проверка, что сжатие реально работает

```bash
curl -I -H 'Accept-Encoding: gzip' http://127.0.0.1/api/provinces/
curl -I -H 'Accept-Encoding: br'   http://127.0.0.1/api/provinces/
```

Ожидаемо в ответах: `Content-Encoding: gzip`/`br` и `Vary: Accept-Encoding`.

## Migration flags и новые API (черновой этап)

Добавлены первые backend-first endpoint'ы (без отключения legacy):

- `GET /api/map/version/`
- `GET /api/map/bootstrap/?profile=full|compact`
- `GET /api/provinces/?offset=0&limit=100&profile=full|compact`
- `GET /api/provinces/show/?pid=123&profile=full|compact`
- `GET /api/provinces/{pid}` (canonical path alias через Apache rewrite)
- `GET /api/realms/?type=kingdoms|great_houses|minor_houses|free_cities|special_territories&profile=full|compact`
- `GET /api/realms/{type}/{id}` (canonical path alias)
- `PATCH /api/realms/patch/`
- `POST /api/changes/apply/`
- `GET /api/assets/emblems/?profile=full|compact` (draft, legacy `emblem_svg` -> dedup assets)
- `GET /api/assets/emblems/show/?id=<asset_id>`
- `GET /api/render/layer/?mode=provinces|kingdoms|great_houses|free_cities&version=`
- `POST /api/jobs/rebuild-layers/`
- `GET /api/jobs/show/?id=<job_id>`
- `GET /api/jobs/list/?offset=&limit=`
- `POST /api/jobs/run-once/`

Stage 1 (snapshot/turn engine) endpoints:
- `GET /api/turns/`
- `POST /api/turns/create-from-previous/`
- `POST /api/turns/process-economy/`
- `POST /api/turns/publish/`
- `GET /api/turns/show/?year=302&include=state,map_artifacts,economy,events,treasury,snapshot_payload`
- `GET /api/turns/load/?year=302` (только published: отдает полный `WorldSnapshot`)
- `POST /api/turns/rollback/` (policy: только published turn и без published successors)
- `POST /api/turns/restore-state/` (опционально: восстановить `data/map_state.json` из published snapshot, требует `If-Match` текущего state)

Примечание: для первого хода допустим bootstrap-вызов `source_turn_year=0`, `target_turn_year=1`.
Переход к Stage 1: ручной reset/настройки симуляции в ходе заменяются обработкой через `/api/turns/process-economy/` и публикацией хода.

Stage 2 treasury:
- `process-economy` теперь синхронно считает `entity_treasury`, `province_treasury`, `treasury_ledger` в привязке к `turn_year`.
- `publish` сохраняет treasury-данные в `snapshot_end` для воспроизводимого восстановления.
- Финансовые коэффициенты берутся из versioned ruleset: `data/turn_rulesets.json` (`default` + `by_version`).
- Проверки: `bash tools/contract_turn_stage2.sh` и `bash tools/e2e_turn_treasury_invariants.sh`.

Stage 3 diplomacy & trade treaties:
- `data/map_state.json` поддерживает `treaties[]` (реестр: тип, стороны, условия, `effective_year`, `duration_years`, `penalties`, `modifiers`).
- `modifiers` договора автоматически учитываются в `process-economy` для активных (`active`) договоров: `tax_modifier`, `trade_flow`, `tariffs`, `subsidies`.
- Жизненный цикл договора (`draft -> active -> breached/expired`) формирует события `treaty_status_changed` в `turn.events`.
- Сводка по договорам сохраняется в turn (`treaties`) и доступна через `GET /api/turns/show/` и список `GET /api/turns/`.

Каждый JSON-ответ новых API теперь включает `meta` (`api_version`, `schema_version`) для договорённости контракта на период миграции.

If-Match policy (current): write endpoint'ы (`PATCH /api/provinces/patch/`, `PATCH /api/realms/patch/`, `POST /api/changes/apply/`) требуют актуальный `If-Match` (или `if_match` в body fallback). При отсутствии — `428 if_match_required`, при рассинхроне — `412 version_conflict`.

Feature flags для фронта:

- `USE_CHUNKED_API` — подгрузка провинций чанками из `/api/provinces`.
- `USE_EMBLEM_ASSETS` — резолв гербов через `/api/assets/emblems` и `emblem_asset_id`.
- `USE_PARTIAL_SAVE` — сохранять выбранную провинцию через `PATCH /api/provinces/patch/`.
- `USE_SERVER_RENDER` — применять precomputed слой от `/api/render/layer/`.

Включение на переходный период через query params:

- `index.html?use_chunked_api=1`
- `index.html?use_chunked_api=1&use_emblem_assets=1`
- `admin.html?use_chunked_api=1&use_emblem_assets=1`
- `admin.html?use_partial_save=1`
- `index.html?use_server_render=1`

- В `admin.html` доступна кнопка **"Скачать migrated bundle"**: она отправляет текущий загруженный legacy state на `/api/migration/export/` и получает полный мигрированный bundle (`migrated_state` + `emblem_assets` + `emblem_refs`) для перехода в новый формат.


### Миграция map_state в новый формат (assets + refs)

API:
- `POST /api/migration/apply/` c телом:
  - `state` (опционально)
  - `replace_map_state` (опционально, bool)
  - `include_legacy_svg` (опционально, bool)

CLI:
```bash
php tools/migrate_map_state.php
# с заменой data/map_state.json на migrated_state:
php tools/migrate_map_state.php --replace-map-state
# оставить legacy emblem_svg в migrated_state:
php tools/migrate_map_state.php --keep-legacy-svg
# прогон без записи файлов:
php tools/migrate_map_state.php --dry-run
# миграция произвольного snapshot JSON:
php tools/migrate_map_state.php --from-file=/path/to/map_state.json --dry-run
```

По умолчанию результат записывается в:
- `data/map_state.migrated.json`
- `data/emblem_assets.json`
- `data/emblem_refs.json`


Подробный backlog следующих шагов: `docs/migration-next-steps.md`.
Черновой runbook: `docs/production-runbook-draft.md`.


## Что ещё не сделано (backend-first migration)

- Canonical path aliases теперь работают и в `php -S` через `tools/php_router.php`, но нужна унификация на уровне production-роутера/веб-сервера конфигураций.
- `If-Match` policy унифицирована для ключевых write endpoint'ов (включая `migration/apply` при `replace_map_state=true` и `assets/emblems` persist), но остаётся незакрытым расширенное edge-case покрытие и консистентное применение policy на всех будущих write путях.
- Schema-validation write API усилена почти на весь текущий write-surface (patch/changes apply + migration/jobs + assets persist), включая nested checks (`fill_rgba`/`emblem_box`/`province_pids`/state shape); остаются незакрыты semantic-rules (cross-entity refs, strict domain constraints) и формальная schema-spec/contract generation.
- Worker `tools/job_worker.php` пока transitional: добавлены базовые attempts/progress поля в jobs, но всё ещё нет process supervisor/systemd, health checks и полноценной retry orchestration.
- Tiles pipeline `/api/tiles/` работает в transitional виде (file-cache), но без production CDN/object-storage стратегии и eviction policy.
- Contract-tests для `meta` добавлены, но полноценные e2e сценарии для двух режимов (legacy/backend-first flags), а также production runbook/метрики всё ещё не закрыты.


`POST /api/changes/apply/` поддерживает atomic batch changeset (`province`/`realm`) и используется как следующий шаг к server-side write без giant full-state POST.


Smoke-проверка backend-first API:
```bash
bash tools/smoke_backend_first.sh
```

Contract-проверка метаданных (`meta.api_version`/`meta.schema_version`) по основным endpoint'ам:
```bash
bash tools/contract_backend_first.sh
```

Базовые e2e smoke сценарии (без headless browser, HTTP-level):
```bash
bash tools/e2e_backend_first_flags.sh
```

CI smoke для turn-based economy backend-only режима:
```bash
bash tools/ci_economy_strict_smoke.sh
bash tools/ci_no_legacy_frontend_refs.sh
```

Для локального `php -S` добавлен роутер `tools/php_router.php`, чтобы canonical path aliases (`/api/provinces/{pid}`, `/api/realms/{type}/{id}`, `/api/tiles/{z}/{x}/{y}`) работали и без `.htaccess`.


Текущая минимальная очередь jobs хранится в `data/jobs.json` (transitional режим до выделенного worker сервиса).


PNG tiles endpoint: `GET /api/tiles/?z=0&x=0&y=0&mode=kingdoms` (also supports `z>0` via scale/crop cache; transitional quality before production tile pipeline).


Минимальный worker для очереди jobs:
```bash
php tools/job_worker.php --once
# или как long-running loop:
php tools/job_worker.php --interval-ms=1500
```


### Разделение «Вольных Городов» и «Особых Территорий» (аккуратный перенос)

UI вариант: в `admin.html` есть блок «Перенос: Вольные Города → Особые Территории» со списком и чекбоксами (кнопки dry-run/перенос).

API вариант:
- `POST /api/migration/split-free-cities/` (`ids[]`, `dry_run`, `if_match`)


Если у вас в текущем `map_state.json` всё лежит в `free_cities`, используйте CLI-миграцию:

```bash
# dry-run: только отчёт, без записи
php tools/split_free_cities.php --ids="ID_1,ID_2" --dry-run

# записать результат в data/map_state.split.out.json
php tools/split_free_cities.php --ids="ID_1,ID_2"

# сразу заменить data/map_state.json
php tools/split_free_cities.php --ids="ID_1,ID_2" --replace-map-state
```

Что делает скрипт:
- переносит указанные ID из `free_cities` в `special_territories`;
- для всех провинций с этими ID переносит связь `free_city_id -> special_territory_id`;
- возвращает JSON-отчёт (`moved_ids`, `missing_ids`, `relinked_provinces`).
