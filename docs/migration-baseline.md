# Backend-first migration baseline (Этап 0)

Дата: 2026-02-27

## Методика

1. Размеры артефактов замерены через Python (`os.path.getsize`) и breakdown JSON через `json.loads` + `json.dumps(...).encode()`.
2. Латентность первичной загрузки оценена через `curl -w` к локальному `php -S 127.0.0.1:8000`.
3. Время применения слоев в браузере в этом шаге **не инструментировалось кодом**; как baseline берём факт текущего client-side raster pipeline (`getImageData/putImageData` + `keyPerPixel`), который остаётся узким местом и будет метрикой для Этапа 4.

## Текущие размеры (до миграции)

- `data/map_state.json`: **13,712,422 bytes** (~13.1 MB).
- `hexmap/data.js`: **7,758,073 bytes** (~7.4 MB).
- `map.png`: **4,856,461 bytes** (~4.6 MB).
- `provinces_id.png`: **524,696 bytes**.
- `provinces.json`: **132,413 bytes**.

### Breakdown `map_state.json`

- `provinces`: 1,478,801 bytes.
- `kingdoms`: 2,123,984 bytes.
- `great_houses`: 8,495,706 bytes.
- `free_cities`: 1,512,085 bytes.

### Emblem-heavy fields

- `provinces[*].emblem_svg`: **1,323,311 bytes** (479 провинций).
- `kingdoms[*].emblem_svg`: **2,086,707 bytes** (14 сущностей).
- `great_houses[*].emblem_svg`: **8,371,790 bytes** (38 сущностей).
- `free_cities[*].emblem_svg`: **1,487,374 bytes** (9 сущностей).

Итого `emblem_svg` только в перечисленных блоках: **13,269,182 bytes** (почти весь вес состояния).

## Baseline latency (localhost)

Замеры `curl`:

- `GET /index.html`: ~0.0017s
- `GET /admin.html`: ~0.0016s
- `GET /data/map_state.json`: ~0.0143s

> Это локальные цифры без сетевой задержки; на VDS + WAN деградация будет заметна из-за full-state fetch.

## Наблюдения

- Архитектурный bottleneck — доставка монолитного `map_state.json` и giant inline SVG (`emblem_svg`) в realms.
- Текущий client-side pipeline рендера маски и перекраски целиком в браузере остаётся CPU/RAM-heavy.
- `save_state.php` имеет лимит `ADMINMAP_MAX_STATE_BYTES` (по умолчанию 15MB), что уже близко к текущему состоянию.

## Что будет сравнением после этапов

- Размер ответа `/api/provinces?offset&limit` vs full `map_state.json`.
- Размер `/api/assets/emblems` и число dedup assets.
- Время первого meaningful render в public/admin при `USE_CHUNKED_API=1`.
- Память/CPU клиента после переноса рендера слоев на backend.
