# Stage 0 — архитектурный baseline для ходовой модели

Этот документ запускает **нулевой этап** из `docs/turn-roadmap.md`: фиксирует каноническую доменную модель, порядок обработки хода и API-контракты для безопасной интеграции последующих модулей.

## 1) Каноническая модель данных

> Принцип: один ход (`turn`) — единая ось времени для всех подсистем.

### 1.1 Сущности

- `Turn`
  - `id`: UUID
  - `year`: integer (уникальный)
  - `status`: `draft | processing | published | rolled_back`
  - `ruleset_version`: string
  - `source_snapshot_id`: UUID (опционально)
  - `created_at`, `published_at`

- `WorldSnapshot`
  - `id`: UUID
  - `turn_id`: UUID
  - `phase`: `start | end`
  - `checksum`: string (SHA-256 от нормализованного payload)
  - `schema_version`: string
  - `payload_ref`: string (путь/ключ хранилища)

- `MapArtifacts`
  - `id`: UUID
  - `turn_id`: UUID
  - `kind`: `classic_map | hex_map | overlay`
  - `content_type`: `image/png | image/webp | application/json`
  - `artifact_ref`: string
  - `checksum`: string

- `EntityState`
  - `id`: UUID
  - `turn_id`: UUID
  - `entity_id`: string
  - `treasury_main`: number
  - `treasury_reserve`: number
  - `diplomacy_statuses`: object

- `EconomyState`
  - `id`: UUID
  - `turn_id`: UUID
  - `province_pid`: integer
  - `income`: number
  - `expense`: number
  - `balance_delta`: number
  - `modifiers`: object

- `Order`
  - `id`: UUID
  - `turn_id`: UUID
  - `entity_id`: string
  - `type`: `economy | diplomacy | military | roleplay`
  - `status`: `pending | approved | rejected | applied`
  - `payload`: object

- `DomainEvent`
  - `id`: UUID
  - `turn_id`: UUID
  - `category`: `economy | diplomacy | military | combat | moderation | system`
  - `event_type`: string
  - `payload`: object
  - `occurred_at`

### 1.2 Инварианты

1. Для каждого `year` существует не более одного `Turn` в `published`.
2. `WorldSnapshot(phase=end)` существует только после завершения всех шагов обработки.
3. Любой write в рамках хода обязан содержать `If-Match` (или body fallback `if_match`) с актуальной версией хода.
4. Перерасчет одного и того же хода на одинаковых входах дает тот же `checksum` итогового snapshot.

---

## 2) Порядок обработки хода (deterministic pipeline)

```text
start_turn
  -> load_previous_snapshot
  -> validate_inputs
  -> apply_approved_orders
  -> run_economy_step
  -> persist_states
  -> build_map_artifacts
  -> generate_end_snapshot
  -> publish_turn
```

### 2.1 Контрольные точки

- `checkpoint:inputs_validated`
- `checkpoint:economy_applied`
- `checkpoint:states_persisted`
- `checkpoint:snapshot_generated`
- `checkpoint:published`

Каждая контрольная точка должна быть idempotent и повторяемой.

---

## 3) Матрица доменных зависимостей

| Домен | Зависит от | Использует | Пишет в |
|---|---|---|---|
| Turn Engine | WorldSnapshot(prev), Orders(approved) | ruleset_version | Turn, WorldSnapshot, DomainEvent |
| Economy | Turn Engine, EntityState, treaties(modifiers) | province routing, taxes | EconomyState, Treasury Ledger |
| Treasury | EconomyState, military spend | transfer rules | EntityState, Province funds, Ledger |
| Diplomacy | Turn, Orders | treaty registry | DomainEvent, treaty states |
| Military | Treasury, Diplomacy | mobilization limits | troop states, spending events |
| Combat | Military, map control | battle rules | losses, control changes, DomainEvent |
| Chronicle | DomainEvent (all) | templates | Turn chronicle artifact |

---

## 4) API-контракты Stage 0 (черновой стандарт)

### 4.1 Создать ход из предыдущего

`POST /api/turns/create-from-previous/`

Request:
```json
{
  "source_turn_year": 301,
  "target_turn_year": 302,
  "ruleset_version": "v1.0"
}
```

Response:
```json
{
  "meta": { "api_version": "v1", "schema_version": "stage0" },
  "turn": { "id": "...", "year": 302, "status": "draft" }
}
```

### 4.2 Синхронно обработать экономику хода

`POST /api/turns/process-economy/`

Headers:
- `If-Match: <turn_version>`

Request:
```json
{ "turn_year": 302 }
```

Response:
```json
{
  "meta": { "api_version": "v1", "schema_version": "stage0" },
  "result": {
    "turn_year": 302,
    "status": "processing",
    "economy_checkpoint": "checkpoint:economy_applied"
  }
}
```

### 4.3 Опубликовать ход

`POST /api/turns/publish/`

Headers:
- `If-Match: <turn_version>`

Request:
```json
{ "turn_year": 302 }
```

Response:
```json
{
  "meta": { "api_version": "v1", "schema_version": "stage0" },
  "turn": { "year": 302, "status": "published" },
  "snapshot": { "phase": "end", "checksum": "sha256:..." }
}
```

### 4.4 Загрузить состояние по номеру хода

`GET /api/turns/show/?year=302&include=state,map_artifacts,economy`

Response:
```json
{
  "meta": { "api_version": "v1", "schema_version": "stage0" },
  "turn": { "year": 302, "status": "published" },
  "state": { "snapshot_ref": "..." },
  "map_artifacts": ["classic_map", "hex_map"],
  "economy": { "records": 812 }
}
```

---

## 5) План внедрения Stage 0 в репозиторий

1. **Контрактный слой**: зафиксировать JSON-схему (`docs/contracts/turn-engine-stage0.schema.json`).
2. **Серверный baseline**: добавить skeleton роуты `/api/turns/*` с `501 not_implemented` + валидный `meta`.
3. **Версионность и optimistic locking**: применить существующую `If-Match` policy к новым write endpoint'ам.
4. **Replay smoke**: скрипт для проверки детерминизма `turn N -> N+1` на одинаковых входных данных.

---

## 6) Definition of Done для Stage 0

- Согласованы и зафиксированы канонические сущности и инварианты.
- Зафиксирован deterministic pipeline хода.
- Зафиксирована матрица доменных зависимостей.
- Подготовлены контракты базовых endpoint'ов `/api/turns/*`.
- Подготовлена схема для дальнейшей машинной валидации payload.


---

## 7) Статус реализации Stage 0 (в репозитории)

### Сделано

- Добавлен backend-модуль `api/lib/turn_api.php`:
  - хранение ходов в `data/turns/` (index + per-turn JSON),
  - `meta` формата `api_version=v1`, `schema_version=stage0`,
  - версионирование хода и проверка `If-Match`/`if_match`,
  - детерминированный шаг расчета экономики (`turn_api_compute_economy`),
  - построение артефактов карты (`map.png`, `provinces_id.png`).
- Добавлены endpoint'ы Stage 0:
  - `POST /api/turns/create-from-previous/`
  - `POST /api/turns/process-economy/`
  - `POST /api/turns/publish/`
  - `GET /api/turns/show/?year=...&include=...`
  - `GET /api/turns/` (list)
- Добавлена JSON-схема Stage 0: `docs/contracts/turn-engine-stage0.schema.json`.

### Что осталось (для полного закрытия этапа в production-смысле)

- Подключить контрактную валидацию schema на CI/e2e для новых `/api/turns/*`.
- Добавить replay-smoke скрипт `turn N -> N+1` с автоматической сверкой checksum.
- Расширить persistence с файловой модели до штатного production-хранилища (если потребуется многопользовательский режим/блокировки).
- Добавить миграцию/совместимость для загрузки исторических legacy-слепков в `WorldSnapshot`.
- Подготовить первые skeleton hooks для следующих доменов (`treasury`, `diplomacy`) как no-op шаги pipeline.
