#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP_PID=""
cleanup() {
  if [[ -n "${PHP_PID}" ]]; then kill "${PHP_PID}" 2>/dev/null || true; fi
}
trap cleanup EXIT

php -S 127.0.0.1:8010 -t "$ROOT" tools/php_router.php >/tmp/adminmap_ci_turn_php.log 2>&1 &
PHP_PID=$!

for _ in {1..40}; do
  if curl -fsS 'http://127.0.0.1:8010/api/turns/' >/dev/null 2>&1; then
    break
  fi
  sleep 0.25
done

curl -fsS 'http://127.0.0.1:8010/api/turns/' | python3 -c 'import sys,json;d=json.load(sys.stdin);assert isinstance(d.get("items"),list)'
curl -fsS 'http://127.0.0.1:8010/api/provinces/?offset=0&limit=5&profile=compact' | python3 -c 'import sys,json;d=json.load(sys.stdin);assert isinstance(d.get("items"),list)'

curl -fsS -X POST 'http://127.0.0.1:8010/api/turns/generate-province-baseline/' -H 'Content-Type: application/json' -d '{}' \
  | python3 -c 'import sys,json;d=json.load(sys.stdin);assert d.get("ok") is True;u=d.get("updated",{});assert int(u.get("updated",0))>0'

echo "ci_economy_strict_smoke: OK"
