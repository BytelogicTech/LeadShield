#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export PATH="/opt/homebrew/opt/php@8.4/bin:/opt/homebrew/bin:$PATH"
cd "$ROOT"

echo "LeadShield queue worker (database driver). Keep this terminal open."
# --timeout must stay below DB_QUEUE_RETRY_AFTER. Avoid 0: PHP 8.4 may treat it as "0 seconds exceeded".
# Restart this script after code changes — a long-running worker keeps old PHP classes in memory.
exec php artisan queue:work --sleep=1 --tries=3 --timeout=86400 --max-time=3600
