#!/usr/bin/env bash
set -euo pipefail

# Bootstraps a local (git-ignored) Tauri workspace for Adminmap Windows app.
# Result path is intentionally outside tracked source tree conventions:
#   /workspace/adminmap/local/windows-adminmap-tauri

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET_DIR="${1:-${ROOT_DIR}/local/windows-adminmap-tauri}"

if ! command -v npm >/dev/null 2>&1; then
  echo "[bootstrap] npm is required (Node.js 18+)." >&2
  exit 1
fi

mkdir -p "$(dirname "${TARGET_DIR}")"

if [ -d "${TARGET_DIR}" ] && [ -n "$(find "${TARGET_DIR}" -mindepth 1 -maxdepth 1 2>/dev/null)" ]; then
  echo "[bootstrap] target already exists and is not empty: ${TARGET_DIR}" >&2
  echo "[bootstrap] choose another directory or clear the existing one." >&2
  exit 1
fi

echo "[bootstrap] creating Tauri app in: ${TARGET_DIR}"
npm create tauri-app@latest "${TARGET_DIR}" -- --manager npm

echo "[bootstrap] done. Next steps:"
echo "  cd ${TARGET_DIR}"
echo "  npm install"
echo "  npm run tauri dev"
