#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

php -S 127.0.0.1:8001 -t "$ROOT" tools/php_router.php >/tmp/adminmap_contract_php.log 2>&1 &
PID=$!
cleanup(){
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

echo "contract_backend_first: OK"
