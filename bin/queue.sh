#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export PATH="/opt/homebrew/opt/php@8.4/bin:/opt/homebrew/bin:$PATH"
cd "$ROOT"

echo "LeadShield queue worker (database driver). Keep this terminal open."
exec php artisan queue:work --sleep=1 --tries=3 --timeout=0
