#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if rg -n "data/map_state\.json|toggleLegacyMode|legacy-режим|save_state\.php"   index.html admin.html micro.html js/public.js js/admin.js js/micro.js js/state_loader.js; then
  echo "legacy frontend/runtime references found" >&2
  exit 1
fi

echo "ci_no_legacy_frontend_refs: OK"
