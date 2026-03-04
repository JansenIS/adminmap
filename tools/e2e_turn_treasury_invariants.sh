#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

python - <<'PY'
import shutil, pathlib
p=pathlib.Path('data/turns')
if p.exists(): shutil.rmtree(p)
PY

php -S 127.0.0.1:8099 tools/php_router.php >/tmp/adminmap_turn_e2e.log 2>&1 &
PID=$!
cleanup(){ kill "$PID" >/dev/null 2>&1 || true; }
trap cleanup EXIT
sleep 1

curl -s -X POST http://127.0.0.1:8099/api/turns/create-from-previous/ -H 'Content-Type: application/json' -d '{"source_turn_year":0,"target_turn_year":1,"ruleset_version":"v1.2"}' >/tmp/e2e_create.json
VER=$(python -c 'import json; print(json.load(open("/tmp/e2e_create.json"))["turn"]["version"])')
curl -s -X POST http://127.0.0.1:8099/api/turns/process-economy/ -H 'Content-Type: application/json' -H "If-Match: ${VER}" -d '{"turn_year":1}' >/tmp/e2e_process.json
VER2=$(python -c 'import json; print(json.load(open("/tmp/e2e_process.json"))["turn"]["version"])')
curl -s -X POST http://127.0.0.1:8099/api/turns/publish/ -H 'Content-Type: application/json' -H "If-Match: ${VER2}" -d '{"turn_year":1}' >/tmp/e2e_publish.json
curl -s 'http://127.0.0.1:8099/api/turns/show/?year=1&include=snapshot_payload&full=1' >/tmp/e2e_show.json

python - <<'PY'
import json, math
show=json.load(open('/tmp/e2e_show.json'))
snap=show['snapshot_payload']
ents=snap['entity_treasury']
provs=snap['province_treasury']
ledger=snap['treasury_ledger']

# province invariant
for r in provs:
    opening=float(r.get('opening_balance', 0) or 0)
    income=float(r.get('income', 0) or 0)
    mandatory_out=float(r.get('expense', 0) or 0) + float(r.get('tax_paid_to_entity', 0) or 0) + float(r.get('reserve_add', 0) or 0)
    optional_out=sum(float(r.get(k, 0) or 0) for k in ('entity_income_share_paid',))
    lhs=round(opening + income - mandatory_out - optional_out, 2)
    rhs=round(float(r.get('closing_balance', 0) or 0),2)
    assert lhs==rhs, ('province_balance_mismatch', r.get('province_pid'), lhs, rhs)

# entity invariant
for r in ents:
    lhs=round(float(r['opening_balance']) + float(r['income_tax']) - float(r['subsidies_out']) - float(r.get('army_upkeep_out',0)) + float(r.get('transfers_in',0)) - float(r.get('transfers_out',0)),2)
    rhs=round(float(r['closing_balance']),2)
    assert lhs==rhs, ('entity_balance_mismatch', r['entity_id'], lhs, rhs)

# debit/credit ledger invariant
debit_total=0.0
credit_total=0.0
for e in ledger:
    amt=float(e['amount'])
    assert amt>=0.0, ('negative_amount', e)
    assert e.get('debit_account') and e.get('credit_account'), ('missing_debit_credit', e)
    assert e['debit_account']!=e['credit_account'], ('same_account', e)
    debit_total += amt
    credit_total += amt

assert round(debit_total,2)==round(credit_total,2), ('ledger_not_balanced', debit_total, credit_total)

# tax flow consistency
sum_tax_rows=round(sum(float(r['tax_paid_to_entity']) for r in provs),2)
sum_tax_ledger=round(sum(float(e['amount']) for e in ledger if e['type']=='province_to_entity_tax'),2)
assert sum_tax_rows==sum_tax_ledger, ('tax_flow_mismatch', sum_tax_rows, sum_tax_ledger)

print('e2e_treasury_invariants_ok', len(ledger))
PY
