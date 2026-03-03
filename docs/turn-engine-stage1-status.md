# Stage 1 — Snapshot/Turn Engine: статус реализации

## Что сделано

- Реализованы сущности в runtime-модели хода:
  - `Turn` (`draft|processing|published|rolled_back`),
  - `WorldSnapshot` (`start/end`) с checksum и ссылкой на snapshot-файл,
  - `MapArtifacts` (classic + hex + `overlay_economy`),
  - `EntityState` и `EconomyState` на конкретный `turn_year`.
- Реализованы операции:
  - `createTurnFromPrevious` — `POST /api/turns/create-from-previous/`.
  - `processTurnEconomy` — `POST /api/turns/process-economy/` (sync + deterministic).
  - `publishTurn` — `POST /api/turns/publish/`.
  - `rollbackTurn` — `POST /api/turns/rollback/` (policy: только published turn и без published successors).
  - Загрузка состояния published хода:
    - `GET /api/turns/show/` (published-only по умолчанию),
    - `GET /api/turns/load/` (полный `WorldSnapshot` end).
  - Опциональный restore runtime state:
    - `POST /api/turns/restore-state/` (восстановление `data/map_state.json` из `snapshot_end`, с `If-Match`).
- Добавлено snapshot-хранилище:
  - `data/turns/turn_<year>.json`
  - `data/turns/snapshots/turn_<year>_start.json`
  - `data/turns/snapshots/turn_<year>_end.json`
  - `data/turns/overlays/turn_<year>_economy_overlay.json`
- Экономика привязана к дате хода (`turn_year`) и считается детерминированно из `snapshot_start`.
- Добавлен CI-скрипт replay-проверки детерминизма checksum:
  - `tools/ci_turn_replay_determinism.sh`.
- Для viewer симуляции отключен пользовательский reset-флоу (UI), параметры seed/transport/friction помечены как turn-managed.

## Что осталось

- Встроить `tools/ci_turn_replay_determinism.sh` в основной CI pipeline проекта.
- При необходимости добавить rollback-cascade policy для зависимых доменов (когда появятся армии/дипломатия/бои).
- Расширить overlay-артефакты под боевые и дипломатические слои на следующих этапах.
