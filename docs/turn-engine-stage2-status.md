# Stage 2 — Казна и TreasuryLedger: статус реализации

## Что сделано

- Финансовые коэффициенты вынесены в versioned-ruleset (`data/turn_rulesets.json`) и читаются по `turn.ruleset_version`.
- Реализована двухконтурная финансовая модель в рамках хода:
  - `entity_treasury` (казна сущности),
  - `province_treasury` (локальные фонды провинций).
- Реализован `TreasuryLedger` (детерминированный журнал транзакций) с типами:
  - `province_to_entity_tax`,
  - `entity_to_province_subsidy`,
  - `entity_to_entity_transfer`.
- Интеграция с экономическим шагом хода:
  - казна и ledger считаются внутри `processTurnEconomy`,
  - publish требует, чтобы treasury-расчет был завершен,
  - snapshot `end` содержит `entity_treasury`, `province_treasury`, `treasury_ledger`.
- `show` и `index` endpoint'ы возвращают сводные поля по казне и журналу.
- Добавлены contract/e2e скрипты проверки:
  - `tools/contract_turn_stage2.sh` (контракт + schema/meta surface),
  - `tools/e2e_turn_treasury_invariants.sh` (инварианты баланса и debit/credit).

## Что осталось

- Добавить правила treasury для будущих доменов (армия/дипломатия/контрибуции) поверх текущего ledger.
- CI checks подключены workflow `turn-stage2` (`.github/workflows/turn-stage2.yml`).
- Ужесточить инварианты ledger до account-level (per-account opening/closing) при появлении расширенного chart of accounts.
