# Migration next steps (итеративный backlog)

## Уже сделано (кратко)
- Baseline и первые API чтения (`map/version`, `map/bootstrap`, `provinces`, `realms`).
- Черновая миграция гербов в `emblem_assets`/`emblem_refs`.
- Экспорт migrated bundle из админки.
- Apply-миграция (`/api/migration/apply/`) и CLI `tools/migrate_map_state.php`.
- Частичный write-path для провинции (`PATCH /api/provinces/patch/`).

## Что осталось сделать

### Этап 1 (закрыть API-контракты чтения)
- [~] Добавить canonical path-паттерны вида `/api/provinces/{pid}` и `/api/realms/{type}/{id}` (добавлены Apache rewrite-алиасы + `php -S` router; требуется унификация production-роутинга).
- [~] Ввести schema/version metadata в каждом ответе API. (базовый `meta` добавлен в core response; добавлены contract-tests; требуется расширить покрытие на edge-cases/ошибки)
- [~] Добавить компактные DTO-ответы (без тяжёлых полей по умолчанию), selectable через query-профили. (добавлен `profile=compact` для `/api/provinces`, `/api/provinces/show`, `/api/realms`, `/api/realms/show`, `/api/map/bootstrap`, `/api/assets/emblems`; нужно расширить на остальные endpoint'ы)

### Этап 2 (гербы/assets)
- [ ] Перевести выдачу гербов на постоянное хранилище с индексом по `asset_id` (без on-the-fly сканирования state).
- [ ] Добавить проверку/нормализацию legacy SVG на этапе записи (`PATCH`, import, migration apply).
- [ ] Поддержать cleanup orphan refs/assets и dry-run отчёт.

### Этап 3 (фронт-флаги и переход)
- [~] Перевести сохранение админки на patch-first сценарий (провинции/realm), оставить full-save как fallback. (провинции+save realm уже частично на PATCH, требуется покрыть остальные операции realm)
- [ ] Добавить UI-индикатор активных флагов (`USE_CHUNKED_API`, `USE_EMBLEM_ASSETS`, `USE_PARTIAL_SAVE`).
- [ ] Добавить smoke e2e для legacy и API-режимов.

### Этап 4 (серверный рендер)
- [~] `GET /api/render/layer?mode=&version=` с кешированием по версии. (базовый endpoint добавлен, нужен PNG/tile рендер и stronger cache strategy)
- [ ] Начать tiles path (`/api/tiles/{z}/{x}/{y}`) с базового zoom.
- [ ] Переключить public/admin на приоритет серверных слоёв.

### Этап 5 (запись без giant POST)
- [~] PATCH для realms и batched operations. (PATCH realms + base batch endpoint уже добавлены; осталось покрыть остальной UI/операции)
- [~] Schema validation и строгая ошибка по invalid payload. (расширена для patch/changes apply + migration/jobs payload shape checks; требуется полное покрытие всех write endpoint'ов и nested схем)
- [~] Конфликт-детекция через `If-Match`/version checks. (унифицирована policy `428 if_match_required` / `412 version_conflict` для ключевых write endpoint'ов; требуется edge-case coverage и распространение на все write пути)

### Этап 6 (фоновые задачи)
- [ ] Очередь задач для предрендера и массовой миграции эмблем.
- [~] API jobs (`POST /api/jobs/*`, `GET /api/jobs/:id`). (минимальные endpoints добавлены, нужен worker/executor и переход статусов)

### Этап 7 (эксплуатация)
- [ ] Runbook rollback (включая `map_state.backup.*`).
- [ ] Мониторинг latency/size/memory KPI до/после флагов.
- [ ] Обновление production-инструкций запуска.


## Что не сделано (текущее состояние)
- [~] Отдельный worker-процесс для jobs (добавлен `tools/job_worker.php`; требуется запуск как постоянный сервис/systemd и health-monitoring).
- [~] Статусы/прогресс jobs по шагам и retry policy. (добавлены базовые `attempts/max_attempts/progress`; требуется production retry policy, backoff и observability)
- [~] Реальные tiles `/api/tiles/{z}/{x}/{y}` (PNG) и кеш на файловом/объектном хранилище. (добавлен PNG endpoint + file cache; требуется production-grade pipeline и object storage)
- [ ] Полный server-render для `minor_houses` и совместимость с текущей визуализацией.
- [~] Строгая schema validation для всех PATCH/batch payloads. (добавлены strict checks для province/realm PATCH, changes/apply, migration/* и assets/jobs write payloads + nested checks; требуется formal schema-spec, semantic-rules и cross-entity validation)
- [~] Concurrency control (`If-Match` / optimistic locking) для write API. (для ключевых write endpoint'ов policy уже strict-required (включая migration/apply replace-map-state и assets/emblems persist); нужно расширить edge-case coverage и распространить policy на весь write surface)
- [~] e2e сценарии для двух режимов: legacy и backend-first flags (включая проверку canonical path aliases). (добавлены базовые HTTP-level e2e smoke scripts; требуется browser-level end-to-end)
- [~] Production runbook: деплой, rollback, мониторинг latency/size/error-rate. (добавлен draft runbook; требуется инфраструктурная детализация и автоалерты)
