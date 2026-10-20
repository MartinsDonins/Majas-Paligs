#!/usr/bin/env bash
# docker compose for the prodlike stack (BRAIN/docs/PRODLIKE.md): scripts/prodlike/dc.sh ps | logs -f web | exec web sh …
set -euo pipefail
export MSYS_NO_PATHCONV=1
ROOT="$(cd "$(dirname "$0")/../.." && (pwd -W 2>/dev/null || pwd))"
ENV_FILE="$ROOT/storage/app/prodlike/prodlike.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "No $ENV_FILE yet — run scripts/prodlike/up.sh first." >&2
  exit 1
fi
exec docker compose --env-file "$ENV_FILE" -f "$ROOT/scripts/prodlike/compose.yaml" "$@"
