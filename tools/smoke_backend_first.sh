#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

cp data/map_state.json /tmp/adminmap_map_state_backup.json
if [[ -f data/jobs.json ]]; then cp data/jobs.json /tmp/adminmap_jobs_backup.json; else rm -f /tmp/adminmap_jobs_backup.json; fi
mkdir -p /tmp/adminmap_render_cache_backup
rm -f /tmp/adminmap_render_cache_backup/*.json
if [[ -d data/render_cache ]]; then cp data/render_cache/*.json /tmp/adminmap_render_cache_backup/ 2>/dev/null || true; fi
cleanup(){
  cp /tmp/adminmap_map_state_backup.json data/map_state.json || true
  if [[ -f /tmp/adminmap_jobs_backup.json ]]; then cp /tmp/adminmap_jobs_backup.json data/jobs.json || true; else rm -f data/jobs.json; fi
  mkdir -p data/render_cache
  rm -f data/render_cache/*.json
  cp /tmp/adminmap_render_cache_backup/*.json data/render_cache/ 2>/dev/null || true
  if [[ -n "${PID:-}" ]]; then kill "$PID" 2>/dev/null || true; fi
}
trap cleanup EXIT

php -S 127.0.0.1:8000 -t "$ROOT" >/tmp/adminmap_smoke_php.log 2>&1 &
PID=$!
sleep 0.7

curl -fsS http://127.0.0.1:8000/api/map/version/ | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("map_version")'
curl -fsS http://127.0.0.1:8000/api/map/version/ | python3 -c 'import sys,json;d=json.load(sys.stdin);m=d.get("meta",{});assert m.get("api_version");assert m.get("schema_version")==1'
V=$(curl -fsS http://127.0.0.1:8000/api/map/version/ | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d["map_version"])')
curl -fsS "http://127.0.0.1:8000/api/render/layer/?mode=kingdoms&version=${V}" | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("mode")=="kingdoms";assert isinstance(d.get("items"),list)'

RID=$(curl -fsS 'http://127.0.0.1:8000/api/realms/?type=kingdoms&profile=compact' | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("profile")=="compact";assert isinstance(d.get("items"),list);i=d["items"][0];assert "emblem_svg" not in i;print(i["id"])')
curl -fsS "http://127.0.0.1:8000/api/realms/show/?type=kingdoms&id=${RID}" | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("type")=="kingdoms";assert d.get("id")'
curl -fsS 'http://127.0.0.1:8000/api/provinces/show/?pid=1' | python3 -c 'import sys,json;d=json.load(sys.stdin);assert isinstance(d.get("item"),dict)'
curl -fsS 'http://127.0.0.1:8000/api/provinces/?offset=0&limit=1&profile=compact' | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("profile")=="compact";i=d["items"][0];assert "emblem_svg" not in i'
curl -fsS -X POST 'http://127.0.0.1:8000/api/changes/apply/' -H 'Content-Type: application/json' \
  --data "{\"changes\":[{\"kind\":\"province\",\"pid\":1,\"changes\":{\"terrain\":\"smoke-test\"}},{\"kind\":\"realm\",\"type\":\"kingdoms\",\"id\":\"$RID\",\"changes\":{\"capital_pid\":7}}]}" \
  | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("ok") is True;assert d.get("applied")==2'

php tools/migrate_map_state.php --dry-run >/tmp/adminmap_smoke_migrate.log

JID=$(curl -fsS -X POST 'http://127.0.0.1:8000/api/jobs/rebuild-layers/' -H 'Content-Type: application/json' --data '{"mode":"kingdoms"}' | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d["job"]["id"])')
curl -fsS -X POST 'http://127.0.0.1:8000/api/jobs/run-once/' | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("ok") is True;assert d.get("processed") is True'
curl -fsS "http://127.0.0.1:8000/api/jobs/show/?id=${JID}" | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("ok") is True;assert d.get("job",{}).get("type")=="rebuild_layers";assert d.get("job",{}).get("status") in ("succeeded","failed")'
curl -fsS 'http://127.0.0.1:8000/api/jobs/list/?offset=0&limit=5' | python3 -c 'import sys,json;d=json.load(sys.stdin);assert isinstance(d.get("items"),list)'
/bin/bash -lc "curl -fsS 'http://127.0.0.1:8000/api/tiles/?z=0&x=0&y=0&mode=kingdoms' -o /tmp/adminmap_tile_0_0_0.png"
python3 - <<'PYT'
from pathlib import Path
b=Path('/tmp/adminmap_tile_0_0_0.png').read_bytes()
assert b.startswith(b'\x89PNG\r\n\x1a\n')
PYT
/bin/bash -lc "curl -fsS 'http://127.0.0.1:8000/api/tiles/?z=1&x=0&y=0&mode=kingdoms' -o /tmp/adminmap_tile_1_0_0.png"
python3 - <<'PYT'
from pathlib import Path
b=Path('/tmp/adminmap_tile_1_0_0.png').read_bytes()
assert b.startswith(b'\x89PNG\r\n\x1a\n')
PYT
V2=$(curl -fsS http://127.0.0.1:8000/api/map/version/ | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d["map_version"])')
curl -fsS "http://127.0.0.1:8000/api/render/layer/?mode=kingdoms&version=${V2}" | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("mode")=="kingdoms";assert d.get("from_cache") in (True,False)'

echo "smoke_backend_first: OK"
