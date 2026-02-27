#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

php -S 127.0.0.1:8002 -t "$ROOT" tools/php_router.php >/tmp/adminmap_e2e_legacy_php.log 2>&1 &
PID=$!
cleanup(){ kill "$PID" 2>/dev/null || true; }
trap cleanup EXIT
sleep 0.7

curl -fsS 'http://127.0.0.1:8002/index.html' | python3 -c 'import sys;h=sys.stdin.read();assert "js/feature_flags.js" in h;assert "js/state_loader.js" in h;assert "flagsStatus" in h'
curl -fsS 'http://127.0.0.1:8002/admin.html' | python3 -c 'import sys;h=sys.stdin.read();assert "flagsStatus" in h;assert "Скачать migrated bundle" in h'
# legacy data path should remain available
curl -fsS 'http://127.0.0.1:8002/data/map_state.json' | python3 -c 'import sys,json;d=json.load(sys.stdin);assert isinstance(d.get("provinces"),dict)'

echo "e2e_legacy_flags: OK"
