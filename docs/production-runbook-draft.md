# Production runbook draft (backend-first migration)

> Черновик. Нужна детализация под конкретную инфраструктуру.

## 1) Deploy checklist
- Проверить backup `data/map_state.json` и `data/jobs.json`.
- Прогнать `bash tools/smoke_backend_first.sh` и `bash tools/contract_backend_first.sh` на staging.
- Проверить `php -l` для изменённых endpoint-файлов.

## 2) Rollback checklist
- Откатить релиз к предыдущему commit/tag.
- Восстановить `data/map_state.json` из backup (`map_state.backup.*`).
- При необходимости восстановить `data/jobs.json` и очистить `data/render_cache`/`data/tile_cache`.

## 3) Monitoring baseline (минимум)
- API latency p50/p95: `/api/map/version/`, `/api/provinces/`, `/api/render/layer/`, `/api/tiles/*`.
- Error-rate по кодам (`4xx`, `5xx`) и отдельно `412/428` для write API.
- Размеры ответов: legacy `data/map_state.json` vs chunked `/api/provinces`.
- Worker метрики: jobs queued/running/failed/succeeded.

## 4) Open gaps before production-grade
- Jobs: retry policy, step-progress, dead-letter handling.
- Tiles: object storage/CDN, TTL/eviction policy, pre-warm strategy.
- E2E: браузерные сценарии для legacy/backend-first flags.
