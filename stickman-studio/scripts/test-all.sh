#!/usr/bin/env bash
# Runs every check: modules → parser → server → UI → API → 1080p export.
set -e
cd "$(dirname "$0")/.."
node scripts/check-browser-env.js
node scripts/test-parser.js
node scripts/contact-sheet.js

PORT=${PORT:-4173}
node server/index.js & SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for i in $(seq 1 30); do
  curl -sf "http://127.0.0.1:$PORT/api/health" >/dev/null && break
  sleep 1
done
node scripts/test-ui.js "http://127.0.0.1:$PORT"
node scripts/test-api.js "http://127.0.0.1:$PORT"
kill $SERVER 2>/dev/null || true
node scripts/test-render.js 1920 1080 final
echo
echo "ALL SUITES PASSED"
