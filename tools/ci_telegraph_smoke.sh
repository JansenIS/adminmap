#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

PORT="${TELEGRAPH_SMOKE_PORT:-8099}"
BASE="http://127.0.0.1:${PORT}"

php -S 127.0.0.1:${PORT} tools/php_router.php >/tmp/telegraph_smoke_server.log 2>&1 &
PID=$!
trap 'kill ${PID} >/dev/null 2>&1 || true' EXIT
sleep 1

ADMIN_TOKEN="${ADMIN_TOKEN:-dev-admin-token}"

curl -fsS "${BASE}/api/telegraph/list/?per_page=3" >/tmp/tg_list.json
curl -fsS -H "X-Admin-Token: ${ADMIN_TOKEN}" "${BASE}/api/telegraph/orders_summary/" >/tmp/tg_summary.json
curl -fsS -H "X-Admin-Token: ${ADMIN_TOKEN}" "${BASE}/api/telegraph/relay/" >/tmp/tg_relay.json
curl -fsS -H "X-Admin-Token: ${ADMIN_TOKEN}" "${BASE}/api/telegraph/settings/" >/tmp/tg_settings.json
php tools/telegraph_relay_daemon.php --once --limit=1 >/tmp/tg_daemon.log

python3 - <<'PY'
import json
for path,key in [('/tmp/tg_list.json','ok'),('/tmp/tg_summary.json','ok'),('/tmp/tg_relay.json','ok'),('/tmp/tg_settings.json','ok')]:
    with open(path,'r',encoding='utf-8') as f:
        d=json.load(f)
    if key not in d:
        raise SystemExit(f'missing key {key} in {path}')
print('telegraph smoke ok')
PY

