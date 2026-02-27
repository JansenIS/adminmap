#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

php -S 127.0.0.1:8003 -t "$ROOT" tools/php_router.php >/tmp/adminmap_e2e_backend_php.log 2>&1 &
PID=$!
cleanup(){ kill "$PID" 2>/dev/null || true; }
trap cleanup EXIT
sleep 0.7

curl -fsS 'http://127.0.0.1:8003/index.html?use_chunked_api=1&use_emblem_assets=1&use_server_render=1' | python3 -c 'import sys;h=sys.stdin.read();assert "js/state_loader.js" in h'
curl -fsS 'http://127.0.0.1:8003/admin.html?use_chunked_api=1&use_emblem_assets=1&use_partial_save=1' | python3 -c 'import sys;h=sys.stdin.read();assert "js/admin.js" in h'
# backend-first endpoints reachable
curl -fsS 'http://127.0.0.1:8003/api/provinces/?offset=0&limit=1&profile=compact' | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("profile")=="compact";assert isinstance(d.get("items"),list)'
curl -fsS 'http://127.0.0.1:8003/api/render/layer/?mode=kingdoms' | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("mode")=="kingdoms"'

echo "e2e_backend_first_flags: OK"
