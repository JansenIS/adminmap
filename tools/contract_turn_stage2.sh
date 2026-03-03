#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

cp data/map_state.json /tmp/adminmap_contract_turn_stage2_map_state_backup.json

python - <<'PY'
import shutil, pathlib
p=pathlib.Path('data/turns')
if p.exists(): shutil.rmtree(p)
PY

php -S 127.0.0.1:8099 tools/php_router.php >/tmp/adminmap_turn_contract.log 2>&1 &
PID=$!
cleanup(){
  kill "$PID" >/dev/null 2>&1 || true
  cp /tmp/adminmap_contract_turn_stage2_map_state_backup.json data/map_state.json 2>/dev/null || true
}
trap cleanup EXIT
sleep 1

python - <<'PY'
import json
from pathlib import Path
path=Path('data/map_state.json')
state=json.loads(path.read_text())
if not isinstance(state.get('treaties'), list):
    state['treaties'] = []
existing={str(t.get('id','')) for t in state['treaties'] if isinstance(t, dict)}
if 'contract-stage3-trade-1' not in existing:
    side_names=[]
    for bucket in ('kingdoms','great_houses','minor_houses','free_cities'):
        items=state.get(bucket,{})
        if isinstance(items, dict):
            for _k,v in items.items():
                if isinstance(v,dict):
                    n=str(v.get('name','')).strip()
                    if n:
                        side_names.append(n)
                if len(side_names)>=2:
                    break
        if len(side_names)>=2:
            break
    if len(side_names)>=2:
        state['treaties'].append({
            'id':'contract-stage3-trade-1',
            'type':'trade_privileges',
            'sides':side_names[:2],
            'conditions':'test treaty for stage3 contract',
            'effective_year':1,
            'duration_years':3,
            'penalties':'fine_1200',
            'modifiers':{'tax_modifier':0.03,'trade_flow':0.05,'tariffs':0.01,'subsidies':0.02},
        })
path.write_text(json.dumps(state, ensure_ascii=False, indent=2))
PY

curl -s -X POST http://127.0.0.1:8099/api/turns/create-from-previous/ -H 'Content-Type: application/json' -d '{"source_turn_year":0,"target_turn_year":1,"ruleset_version":"v1.2"}' >/tmp/contract_create.json
VER=$(python -c 'import json; print(json.load(open("/tmp/contract_create.json"))["turn"]["version"])')
curl -s -X POST http://127.0.0.1:8099/api/turns/process-economy/ -H 'Content-Type: application/json' -H "If-Match: ${VER}" -d '{"turn_year":1}' >/tmp/contract_process.json
VER2=$(python -c 'import json; print(json.load(open("/tmp/contract_process.json"))["turn"]["version"])')
curl -s -X POST http://127.0.0.1:8099/api/turns/publish/ -H 'Content-Type: application/json' -H "If-Match: ${VER2}" -d '{"turn_year":1}' >/tmp/contract_publish.json
curl -s 'http://127.0.0.1:8099/api/turns/show/?year=1&include=economy,treasury,events,snapshot_payload&full=1' >/tmp/contract_show.json

python - <<'PY'
import json
for path in ['/tmp/contract_create.json','/tmp/contract_process.json','/tmp/contract_publish.json','/tmp/contract_show.json']:
    d=json.load(open(path))
    m=d.get('meta',{})
    assert m.get('api_version')=='v1', path
    assert m.get('schema_version')=='stage2', path

p=json.load(open('/tmp/contract_process.json'))
assert p['entity_treasury']['status'] in ('processed','published')
assert p['province_treasury']['records'] > 0
assert p['treasury_ledger']['records'] > 0
assert p['treaties']['records'] >= 1

s=json.load(open('/tmp/contract_show.json'))
snap=s['snapshot_payload']
assert isinstance(snap['entity_treasury'], list)
assert isinstance(snap['province_treasury'], list)
assert isinstance(snap['treasury_ledger'], list)
assert isinstance(s.get('events'), list)
assert any((e.get('event_type')=='treaty_status_changed') for e in s['events'])
print('contract_stage2_ok')
PY
