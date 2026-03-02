#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP_PID=""
NODE_PID=""
cleanup() {
  if [[ -n "${NODE_PID}" ]]; then kill "${NODE_PID}" 2>/dev/null || true; fi
  if [[ -n "${PHP_PID}" ]]; then kill "${PHP_PID}" 2>/dev/null || true; fi
}
trap cleanup EXIT

# Positive: strict mode with reachable backend API must start and serve requests.
php -S 127.0.0.1:8010 -t "$ROOT" tools/php_router.php >/tmp/adminmap_ci_econ_php.log 2>&1 &
PHP_PID=$!

for _ in {1..40}; do
  if curl -fsS 'http://127.0.0.1:8010/api/provinces/?offset=0&limit=1&profile=compact' >/dev/null 2>&1; then
    break
  fi
  sleep 0.25
done

node isotope/economy_sim_ui/server.js isotope/province_routing_data.json \
  --port 9810 \
  --adminApiBase http://127.0.0.1:8010 \
  --requireAdminApi true >/tmp/adminmap_ci_econ_node_pos.log 2>&1 &
NODE_PID=$!

sleep 2
curl -fsS 'http://127.0.0.1:9810/api/summary' | python3 -c 'import sys,json;d=json.load(sys.stdin);assert "day" in d'
curl -fsS 'http://127.0.0.1:9810/api/admin/map-sync' | python3 -c 'import sys,json;d=json.load(sys.stdin);assert isinstance(d.get("provinces"),list);assert isinstance(d.get("realms"),dict)'

grep -q 'admin province source: api:http://127.0.0.1:8010' /tmp/adminmap_ci_econ_node_pos.log

kill "$NODE_PID" 2>/dev/null || true
wait "$NODE_PID" 2>/dev/null || true
NODE_PID=""

# Negative: strict mode without API base must fail fast.
set +e
node isotope/economy_sim_ui/server.js isotope/province_routing_data.json \
  --port 9811 \
  --requireAdminApi true >/tmp/adminmap_ci_econ_node_neg.log 2>&1
NEG_CODE=$?
set -e

if [[ "$NEG_CODE" -eq 0 ]]; then
  echo "Expected strict-mode startup to fail without adminApiBase" >&2
  exit 1
fi

grep -q 'adminApiBase is required, but not configured' /tmp/adminmap_ci_econ_node_neg.log

echo "ci_economy_strict_smoke: OK"
