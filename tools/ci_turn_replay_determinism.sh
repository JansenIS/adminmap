#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

php -S 127.0.0.1:8099 tools/php_router.php >/tmp/adminmap_turn_ci.log 2>&1 &
PHP_PID=$!
cleanup() {
  kill "$PHP_PID" >/dev/null 2>&1 || true
}
trap cleanup EXIT
sleep 1

run_once() {
  local run_tag="$1"
  python - <<'PY'
import shutil, pathlib
p=pathlib.Path('data/turns')
if p.exists(): shutil.rmtree(p)
PY

  curl -s -X POST http://127.0.0.1:8099/api/turns/create-from-previous/ \
    -H 'Content-Type: application/json' \
    -d '{"source_turn_year":0,"target_turn_year":1,"ruleset_version":"v1.1"}' >/tmp/t_create_${run_tag}.json

  ver="$(python -c 'import json; print(json.load(open("/tmp/t_create_'"${run_tag}"'.json"))["turn"]["version"])')"

  curl -s -X POST http://127.0.0.1:8099/api/turns/process-economy/ \
    -H 'Content-Type: application/json' -H "If-Match: ${ver}" \
    -d '{"turn_year":1}' >/tmp/t_process_${run_tag}.json

  ver2="$(python -c 'import json; print(json.load(open("/tmp/t_process_'"${run_tag}"'.json"))["turn"]["version"])')"

  curl -s -X POST http://127.0.0.1:8099/api/turns/publish/ \
    -H 'Content-Type: application/json' -H "If-Match: ${ver2}" \
    -d '{"turn_year":1}' >/tmp/t_publish_${run_tag}.json

  python -c 'import json; print(json.load(open("/tmp/t_publish_'"${run_tag}"'.json"))["snapshot"]["checksum"])'
}

checksum_a="$(run_once a)"
checksum_b="$(run_once b)"

if [[ "$checksum_a" != "$checksum_b" ]]; then
  echo "determinism_failed: checksum mismatch: $checksum_a != $checksum_b"
  exit 1
fi

echo "determinism_ok: $checksum_a"
