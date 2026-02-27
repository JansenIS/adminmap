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

## 5) Web-server compression (gzip/br)

Конфиги добавлены в репозиторий:
- Nginx: `deploy/nginx/adminmap.conf` + optional snippet `deploy/nginx/snippets/adminmap-brotli.conf`.
- Apache: `deploy/apache/adminmap.conf` (gzip + brotli под `IfModule`).

### Nginx rollout
```bash
# 1) установить конфиг
sudo cp deploy/nginx/adminmap.conf /etc/nginx/sites-available/adminmap.conf
sudo ln -sf /etc/nginx/sites-available/adminmap.conf /etc/nginx/sites-enabled/adminmap.conf

# 2) (опционально) включить brotli snippet если модуль доступен
sudo cp deploy/nginx/snippets/adminmap-brotli.conf /etc/nginx/snippets/adminmap-brotli.conf
# и раскомментировать include в /etc/nginx/sites-available/adminmap.conf

# 3) проверить и перезагрузить
sudo nginx -t
sudo systemctl reload nginx
```

### Apache rollout
```bash
# 1) активировать нужные модули
sudo a2enmod rewrite headers deflate
# brotli опционально (если доступен в дистрибутиве)
sudo a2enmod brotli || true

# 2) установить сайт
sudo cp deploy/apache/adminmap.conf /etc/apache2/sites-available/adminmap.conf
sudo a2ensite adminmap.conf

# 3) проверить и перезагрузить
sudo apachectl configtest
sudo systemctl reload apache2
```

### Smoke checks (compression)
```bash
curl -I -H 'Accept-Encoding: gzip' http://127.0.0.1/api/provinces/
curl -I -H 'Accept-Encoding: br'   http://127.0.0.1/api/provinces/
```
Ожидание: `Content-Encoding: gzip`/`br` и `Vary: Accept-Encoding`.
