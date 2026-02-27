# Аудит backend: доступ к данным провинций и влияние большого `map_state`

Дата: 2026-02-27

## Текущее состояние (что уже оптимизировано)

1. **Есть API-слой с профилями ответа**: для `/api/provinces` и `/api/provinces/show` доступен `profile=compact`, который убирает тяжёлые поля (`emblem_svg`, `province_card_image`).
2. **Есть ETag/Last-Modified и short-cache** в ответах (`Cache-Control: public, max-age=5, stale-while-revalidate=30`), что снижает повторные загрузки при стабильном состоянии.
3. **Есть попытка вынести эмблемы**: используется `emblem_refs.json`/`emblem_assets.json`, а в выдаче провинций добавляется `emblem_asset_id`.
4. **Есть кэш для render payload** (`data/render_cache`) и фоновые job'ы на прогрев.

## Главный bottleneck сейчас

Несмотря на вышеописанные улучшения, backend по-прежнему архитектурно завязан на **монолитный `data/map_state.json`**:

- Почти каждый endpoint начинает с `api_load_state()`, который делает `file_get_contents + json_decode` всего файла в память.
- Пагинация провинций выполняется **после** полной загрузки/парсинга состояния.
- PATCH любой провинции тоже читает и записывает весь state-файл целиком.

Практически это означает, что время ответа и расход памяти имеют O(size(map_state)), даже когда запрошена одна провинция или одна страница.

## Влияние размера `map_state`

По текущему baseline:

- `data/map_state.json` ≈ **13.1 MB**.
- `emblem_svg`-поля суммарно ≈ **13.27 MB**, то есть почти весь вес состояния.

Следствие: при росте числа провинций/realm-данных деградируют:

- latency чтения API,
- CPU на JSON decode/encode,
- время и риск конфликтов записи (полнофайловый rewrite),
- p95 под конкурентной нагрузкой.

## Рекомендации (по приоритету)

### P0 — быстрый выигрыш без полной смены хранилища

1. **Сделать `compact` профилем по умолчанию** для листингов (особенно `/api/provinces`).
2. **Жёстко вывести тяжёлые поля из hot-path**:
   - не отдавать `emblem_svg` в `full` для коллекций;
   - держать SVG только в `/api/assets/emblems/*`.
3. **Предрасчёт и хранение `provinces_index.json`**:
   - массив `{pid, name, owner, terrain, ...}` без тяжёлых полей;
   - `/api/provinces` читает индекс, а не весь `map_state`.
4. **HTTP-сжатие (gzip/br) на уровне веб-сервера** для JSON-эндпоинтов.

Ожидаемый эффект: заметное снижение payload и CPU без рефакторинга write-path.

### P1 — снижение latency и нагрузки CPU

1. **Разделить state на доменные файлы**:
   - `data/provinces.json`, `data/realms/*.json`, `data/meta.json`.
2. **Ввести read-through cache в памяти процесса** (APCu/OPcache-preload-структуры) с invalidation по mtime/version.
3. **Стабильные materialized views**:
   - `provinces_compact.json`,
   - `realms_compact_{type}.json`,
   - versioned snapshots.

Ожидаемый эффект: O(size(provinces_compact)) вместо O(size(map_state)) для частых чтений.

### P2 — стратегически правильный шаг

1. Перейти на **SQLite/PostgreSQL** как source of truth для provinces/realms.
2. `map_state.json` оставить как export/snapshot, а не рабочее OLTP-хранилище.
3. PATCH по провинции делать row-level update (транзакционно), а фоновые job'ы строят derived JSON/cache.

Ожидаемый эффект: предсказуемая производительность под конкурентной записью + проще индексация/фильтрация.

## Отдельные технические замечания

1. Текущая версия state (`hash(mtime:size)`) быстрая, но возможны edge-case коллизии при редких сценариях одинакового размера и timestamp granularity. Для критичных write-конфликтов надёжнее хранить реальный revision-id.
2. `api_atomic_write_json` использует `rename` без file lock на чтение; при высокой конкурентности имеет смысл добавить lock-файл и политику retry.

## Предложенный план внедрения (2 итерации)

### Итерация 1 (1–2 дня)

- Включить compact-by-default.
- Добавить и использовать `provinces_index.json`.
- Перенести SVG-выдачу полностью в assets endpoint.
- Настроить gzip/br в web-server config.

### Итерация 2 (3–5 дней)

- Декомпозировать map_state на доменные файлы.
- Добавить APCu read cache + инвалидацию.
- Подготовить миграционный слой под SQLite (минимум для provinces + realms).


## Статус Итерации 1 (факт)

### ✅ Сделано

- `compact` включён по умолчанию для листинга провинций (`/api/provinces`).
- Добавлен и используется `data/provinces_index.json` для index-backed чтения `/api/provinces` (без full parse `map_state` на тёплом индексе).
- Выдача `emblem_svg` убрана из `/api/provinces` и `/api/provinces/show`; SVG должен забираться через `/api/assets/emblems/*`.

### ⏳ Осталось после этой итерации

- Выполнить инфраструктурный rollout на конкретном production-стенде (применить и активировать конфиги из `deploy/nginx/*` или `deploy/apache/*`, проверить `Content-Encoding` на живом трафике).

### ✅ Закрыто в коде репозитория

- Добавлены production-шаблоны конфигов с gzip/br для Nginx и Apache (`deploy/nginx/adminmap.conf`, `deploy/nginx/snippets/adminmap-brotli.conf`, `deploy/apache/adminmap.conf`).

## Критерии успеха

- p95 `/api/provinces?offset&limit=100` уменьшается минимум в 2–3 раза.
- Размер ответа листинга провинций (default) < 200–300 KB.
- PATCH одной провинции не требует полного rewrite 13+ MB файла.
- Стабильный p95 под конкурентной нагрузкой (RPS 20–50 на чтение + периодические PATCH).
