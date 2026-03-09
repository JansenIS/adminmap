# MVP «Приказы и вердикты» (strangler pattern)

## Что сделано

### Backend и домен
- Добавлен домен `/api/orders/*` на JSON-backed хранилище `data/orders/*`.
- Реализован pipeline: `draft -> submitted -> pending_review -> verdict_ready -> approved -> published`, плюс `needs_clarification|rejected`.
- Вынесена единая таблица outcome tiers (`orders_api_outcome_tiers`).
- Добавлены сущности в рабочем MVP-формате: `Order`, `OrderActionItem`, `Verdict`, `VerdictRoll`, `OrderEffect`, `OrderPublication`, `OrderAuditLog`.
- Добавлены события жизненного цикла в event-log (`order_submitted`, `order_clarification_requested`, `order_rejected`, `order_roll_locked`, `order_effect_applied`, `order_published`, `vk_wall_publish_requested`, `vk_wall_published`, `player_notified`).
- Расширено применение структурированных эффектов: `treasury_delta`, `entity_income_delta`, `province_income_delta`, `building_add/remove`, `law_add/remove/update`, `garrison_change`, `militia_change`, `army_create`, `unit_raise`, `unit_disband`, `army_merge`, `army_split`, а также registry-поддержка для `treaty_create/update`, `trade_agreement_create`, `war_declare/end`, `vassalage_change`, `province_control_change`, `entity_relation_note`, `map_event_note`.
- Денежные изменения пишутся в `turn.treasury_ledger` с привязкой к `order/effect/admin`.

### API
- Реализованы endpoint’ы:
  - `GET|POST /api/orders/index.php`
  - `GET /api/orders/my/index.php`
  - `GET /api/orders/admin-queue/index.php`
  - `GET /api/orders/feed/index.php`
  - `POST /api/orders/upload/index.php`
  - `GET /api/orders/show/index.php?id=...`
  - `POST /api/orders/patch/index.php?id=...`
  - `POST /api/orders/submit/index.php?id=...`
  - `POST /api/orders/clarification/index.php?id=...`
  - `POST /api/orders/reject/index.php?id=...`
  - `POST /api/orders/roll/index.php?id=...`
  - `POST /api/orders/verdict/index.php?id=...`
  - `POST /api/orders/apply-effects/index.php?id=...`
  - `POST /api/orders/publish/index.php?id=...`

### UI (alt)
- Добавлен alt-кабинет игрока для приказов: `player_orders_ui_alt.html` + `js/player_orders_ui_alt.js`.
- Добавлен alt-workspace модератора: `admin_orders_ui_alt.html` + `js/admin_orders_ui_alt.js`.
- Добавлена публичная alt-лента: `orders_feed_ui_alt.html` + `js/orders_feed_ui_alt.js`.
- В `player_admin_ui_alt.html` и `admin_ui_alt.html` добавлены ссылки на новые alt-экраны без переписывания оригиналов.

### VK + публикация
- Расширен текущий callback flow VK-бота: меню приказов, пошаговая подача, списки «мои/черновики/вердикты/уточнения».
- Добавлены bot-уведомления на `needs_clarification` и `published`.
- Реализован outbox-процессор публикации на стену VK с retry-safe статусами `pending_wall_post|wall_posted|wall_post_failed`.
- В `api/jobs/run-once` добавана обработка `kind=orders_outbox`.

### Feature flags
В `js/feature_flags.js`:
- `ORDERS_V1`
- `VERDICTS_V1`
- `ORDER_FEED_V1`
- `VK_ORDER_FLOW_V1`
- `VK_WALL_ORDER_PUBLISH_V1`

---

## Что НЕ сделано (честно, по ТЗ)

1. **Не реализован полный набор структурированных effect handlers**:
   - нет полноценной предметной логики для: `unit_raise`, `unit_disband`, `army_create`, `army_merge`, `army_split`, `garrison_change`, `militia_change`, `treaty_create`, `treaty_update`, `trade_agreement_create`, `war_declare`, `war_end`, `vassalage_change`, `province_control_change`, `entity_relation_note`, `map_event_note`.
   - сейчас часть покрывается fallback-записью в `order_effect_log` (без полного обновления всех доменных подсистем).

2. **Optimistic locking частично закрыт, но не до enterprise-уровня**:
   - добавлены обязательные version-check на admin mutation endpoint’ах (`roll`, `verdict`, `clarification`, `reject`, `apply-effects`, `publish`),
   - но нет полноценной блокировки с lease/session-lock и не везде используется ETag-flow.

3. **Вложения из VK**:
   - поддержана базовая фото/doc image URL обработка в callback,
   - но нет полноценной нормализации/хранения attachment metadata как отдельного реестра с rich visibility flags на каждый attachment.

4. **Player UI wizard/stepper в 7 шагов**:
   - реализован рабочий экран с action items/черновиком/autosave,
   - но нет полного пошагового мастера с отдельными экранами 1..7 в web UI.

5. **Admin UI “high-throughput workspace”**:
   - есть split-view и рабочие операции,
   - но не доведены продвинутые инструменты массовой обработки, diff/preview механических изменений «до/после» и более глубокие фильтры (`category`, `has_attachments`, province linkouts).

6. **Интеграция feed ↔ карта/карточки провинций/сущностей**:
   - реализована отдельная публичная лента,
   - но нет полного открытия `order_publication` из карточек провинций/сущностей и глубокой map embedding в существующих экранах.

7. **VK wall media attach pipeline**:
   - текстовая публикация через outbox есть,
   - но прикрепление изображений приказа к wall post пока не доведено.

8. **Migration-quality покрытие и тесты**:
   - есть smoke/manual-проверка и seed,
   - но нет формального автотестового набора (интеграционные/контрактные тесты) для всех новых endpoint’ов.

---

## RBAC (текущее состояние)
- Игрок: `X-Player-Admin-Token`.
- Админ/модератор: `X-Admin-Token` (список в `data/admin_tokens.json`, fallback `dev-admin-token`).

## Acceptance checklist (текущий MVP)
- [x] Игрок может создать приказ в web.
- [x] Игрок может создать приказ в VK.
- [x] Приказ попадает в очередь.
- [x] Админ роллит d20 по пунктам.
- [x] Админ применяет effects.
- [x] Публичный feed показывает public-часть.
- [x] Личный кабинет приказов показывает полный итог владельцу.
- [x] Бот отправляет уведомления по ключевым статусам.
- [x] VK wall publisher получает задачу в outbox.
- [x] Original-страницы не переписаны радикально.
- [~] Большая часть effect-типов из ТЗ заведена в handlers/registry, но часть остаётся registry-only без глубокой доменной проработки.
- [~] Добавлен version-conflict контроль; полный concurrency UX для 2+ модераторов ещё не завершён.
- [ ] Полная интеграция feed в map cards.
