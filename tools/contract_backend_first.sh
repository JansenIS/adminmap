#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

cp data/map_state.json /tmp/adminmap_contract_map_state_backup.json

php -S 127.0.0.1:8001 -t "$ROOT" tools/php_router.php >/tmp/adminmap_contract_php.log 2>&1 &
PID=$!
cleanup(){
  cp /tmp/adminmap_contract_map_state_backup.json data/map_state.json 2>/dev/null || true
  kill "$PID" 2>/dev/null || true
}
trap cleanup EXIT
sleep 0.7

check_meta(){
  local url="$1"
  curl -fsS "$url" | python3 -c 'import sys,json;d=json.load(sys.stdin);m=d.get("meta",{});assert m.get("api_version");assert m.get("schema_version")==1'
}

RID=$(curl -fsS 'http://127.0.0.1:8001/api/realms/?type=kingdoms&limit=1' | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d["items"][0]["id"])')

check_meta 'http://127.0.0.1:8001/api/map/version/'
check_meta 'http://127.0.0.1:8001/api/map/bootstrap/'
check_meta 'http://127.0.0.1:8001/api/map/bootstrap/?profile=compact'
check_meta 'http://127.0.0.1:8001/api/provinces/?offset=0&limit=1'
check_meta 'http://127.0.0.1:8001/api/provinces/?offset=0&limit=1&profile=compact'
check_meta 'http://127.0.0.1:8001/api/provinces/show/?pid=1'
check_meta 'http://127.0.0.1:8001/api/provinces/1?profile=compact'
check_meta 'http://127.0.0.1:8001/api/realms/?type=kingdoms'
check_meta 'http://127.0.0.1:8001/api/realms/?type=kingdoms&profile=compact'
check_meta "http://127.0.0.1:8001/api/realms/show/?type=kingdoms&id=${RID}"
check_meta "http://127.0.0.1:8001/api/realms/kingdoms/${RID}?profile=compact"
check_meta 'http://127.0.0.1:8001/api/render/layer/?mode=kingdoms'
check_meta 'http://127.0.0.1:8001/api/jobs/list/?offset=0&limit=1'
check_meta 'http://127.0.0.1:8001/api/assets/emblems/?offset=0&limit=1'
check_meta 'http://127.0.0.1:8001/api/assets/emblems/?offset=0&limit=1&profile=compact'
AID=$(curl -fsS 'http://127.0.0.1:8001/api/assets/emblems/?offset=0&limit=1' | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d["items"][0]["id"])')
check_meta "http://127.0.0.1:8001/api/assets/emblems/show/?id=${AID}"
check_meta 'http://127.0.0.1:8001/api/migration/export/'

# write-contract edge cases for If-Match policy
curl -sS -o /tmp/adminmap_contract_ifmatch_required.json -w '%{http_code}' -X PATCH 'http://127.0.0.1:8001/api/provinces/patch/' -H 'Content-Type: application/json' --data '{"pid":1,"changes":{"terrain":"contract"}}' | python3 -c 'import sys;assert sys.stdin.read().strip()=="428"'
python3 - <<'PYC'
import json
from pathlib import Path
d=json.loads(Path('/tmp/adminmap_contract_ifmatch_required.json').read_text())
assert d.get('error')=='if_match_required'
m=d.get('meta',{})
assert m.get('api_version') and m.get('schema_version')==1
PYC

curl -sS -o /tmp/adminmap_contract_migration_ifmatch_required.json -w '%{http_code}' -X POST 'http://127.0.0.1:8001/api/migration/apply/' -H 'Content-Type: application/json' --data '{"replace_map_state":true}' | python3 -c 'import sys;assert sys.stdin.read().strip()=="428"'
python3 - <<'PYC'
import json
from pathlib import Path
d=json.loads(Path('/tmp/adminmap_contract_migration_ifmatch_required.json').read_text())
assert d.get('error')=='if_match_required'
assert d.get('meta',{}).get('schema_version')==1
PYC


V=$(curl -fsS 'http://127.0.0.1:8001/api/map/version/' | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d["map_version"])')
curl -fsS -X PATCH 'http://127.0.0.1:8001/api/provinces/patch/' -H 'Content-Type: application/json' -H "If-Match: "${V}"" --data '{"pid":1,"changes":{"terrain":"contract-quoted-if-match"}}' | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("ok") is True'

echo "contract_backend_first: OK"
