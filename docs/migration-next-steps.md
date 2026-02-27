# Migration next steps (итеративный backlog)

## Уже сделано (кратко)
- Baseline и первые API чтения (`map/version`, `map/bootstrap`, `provinces`, `realms`).
- Черновая миграция гербов в `emblem_assets`/`emblem_refs`.
- Экспорт migrated bundle из админки.
- Apply-миграция (`/api/migration/apply/`) и CLI `tools/migrate_map_state.php`.
- Частичный write-path для провинции (`PATCH /api/provinces/patch/`).

## Что осталось сделать

### Этап 1 (закрыть API-контракты чтения)
- [ ] Добавить canonical path-паттерны вида `/api/provinces/{pid}` и `/api/realms/{type}/{id}` (сохранить текущие как совместимые алиасы).
- [ ] Ввести schema/version metadata в каждом ответе API.
- [ ] Добавить компактные DTO-ответы (без тяжёлых полей по умолчанию), selectable через query-профили.

### Этап 2 (гербы/assets)
- [ ] Перевести выдачу гербов на постоянное хранилище с индексом по `asset_id` (без on-the-fly сканирования state).
- [ ] Добавить проверку/нормализацию legacy SVG на этапе записи (`PATCH`, import, migration apply).
- [ ] Поддержать cleanup orphan refs/assets и dry-run отчёт.

### Этап 3 (фронт-флаги и переход)
- [~] Перевести сохранение админки на patch-first сценарий (провинции/realm), оставить full-save как fallback. (провинции+save realm уже частично на PATCH, требуется покрыть остальные операции realm)
- [ ] Добавить UI-индикатор активных флагов (`USE_CHUNKED_API`, `USE_EMBLEM_ASSETS`, `USE_PARTIAL_SAVE`).
- [ ] Добавить smoke e2e для legacy и API-режимов.

### Этап 4 (серверный рендер)
- [ ] `GET /api/render/layer?mode=&version=` с кешированием по версии.
- [ ] Начать tiles path (`/api/tiles/{z}/{x}/{y}`) с базового zoom.
- [ ] Переключить public/admin на приоритет серверных слоёв.

### Этап 5 (запись без giant POST)
- [ ] PATCH для realms и batched operations.
- [ ] Schema validation и строгая ошибка по invalid payload.
- [ ] Конфликт-детекция через `If-Match`/version checks.

### Этап 6 (фоновые задачи)
- [ ] Очередь задач для предрендера и массовой миграции эмблем.
- [ ] API jobs (`POST /api/jobs/*`, `GET /api/jobs/:id`).

### Этап 7 (эксплуатация)
- [ ] Runbook rollback (включая `map_state.backup.*`).
- [ ] Мониторинг latency/size/memory KPI до/после флагов.
- [ ] Обновление production-инструкций запуска.
