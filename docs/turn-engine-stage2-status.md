# Stage 2 — Казна и TreasuryLedger: статус реализации

## Что сделано

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

## Что осталось

- Добавить правила treasury для будущих доменов (армия/дипломатия/контрибуции) поверх текущего ledger.
- Вынести финансовые коэффициенты (ставка налога, дотации, трансферы) в versioned ruleset-конфиг.
- Добавить отдельные contract/e2e тесты на инварианты баланса (дебет/кредит) для ledger.
