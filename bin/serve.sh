#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export PATH="/opt/homebrew/opt/php@8.4/bin:/opt/homebrew/bin:$PATH"
cd "$ROOT"

# Built-in server does not use the /dataclean subdirectory path.
export APP_URL="${APP_URL:-http://127.0.0.1:8000}"
export ASSET_URL="${ASSET_URL:-http://127.0.0.1:8000}"
export SESSION_PATH="${SESSION_PATH:-/}"

echo "LeadShield on ${APP_URL}/admin"
echo "Login: admin@bytelogic.local / password"
echo "Upload limits: 64M"
# Do NOT set max_execution_time on artisan serve — it kills the long-running
# server process itself (~120s = ERR_CONNECTION_REFUSED). Request timeouts are
# fine for queue workers; cleaning never runs inside Livewire anymore.
exec php \
  -d upload_max_filesize=64M \
  -d post_max_size=64M \
  -d memory_limit=512M \
  artisan serve --host=127.0.0.1 --port=8000
