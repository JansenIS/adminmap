#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

cp data/map_state.json /tmp/adminmap_map_state_backup.json
cleanup(){
  cp /tmp/adminmap_map_state_backup.json data/map_state.json || true
  if [[ -n "${PID:-}" ]]; then kill "$PID" 2>/dev/null || true; fi
}
trap cleanup EXIT

php -S 127.0.0.1:8000 -t "$ROOT" >/tmp/adminmap_smoke_php.log 2>&1 &
PID=$!
sleep 0.7

curl -fsS http://127.0.0.1:8000/api/map/version/ | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("map_version")'
V=$(curl -fsS http://127.0.0.1:8000/api/map/version/ | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d["map_version"])')
curl -fsS "http://127.0.0.1:8000/api/render/layer/?mode=kingdoms&version=${V}" | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("mode")=="kingdoms";assert isinstance(d.get("items"),list)'

RID=$(curl -fsS 'http://127.0.0.1:8000/api/realms/?type=kingdoms' | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d["items"][0]["id"])')
curl -fsS -X POST 'http://127.0.0.1:8000/api/changes/apply/' -H 'Content-Type: application/json' \
  --data "{\"changes\":[{\"kind\":\"province\",\"pid\":1,\"changes\":{\"terrain\":\"smoke-test\"}},{\"kind\":\"realm\",\"type\":\"kingdoms\",\"id\":\"$RID\",\"changes\":{\"capital_pid\":7}}]}" \
  | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("ok") is True;assert d.get("applied")==2'

php tools/migrate_map_state.php --dry-run >/tmp/adminmap_smoke_migrate.log

JID=$(curl -fsS -X POST 'http://127.0.0.1:8000/api/jobs/rebuild-layers/' -H 'Content-Type: application/json' --data '{"mode":"kingdoms"}' | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d["job"]["id"])')
curl -fsS -X POST 'http://127.0.0.1:8000/api/jobs/run-once/' | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("ok") is True;assert d.get("processed") is True'
curl -fsS "http://127.0.0.1:8000/api/jobs/show/?id=${JID}" | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("ok") is True;assert d.get("job",{}).get("type")=="rebuild_layers";assert d.get("job",{}).get("status") in ("succeeded","failed")'
V2=$(curl -fsS http://127.0.0.1:8000/api/map/version/ | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d["map_version"])')
curl -fsS "http://127.0.0.1:8000/api/render/layer/?mode=kingdoms&version=${V2}" | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("mode")=="kingdoms";assert d.get("from_cache") in (True,False)'

echo "smoke_backend_first: OK"
