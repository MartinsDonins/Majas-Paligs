#!/usr/bin/env bash
# Stop the prodlike stack. Postgres data, uploads and the Caddy CA are KEPT (Redis has no volume: sessions end).
#   scripts/prodlike/down.sh            stop + remove containers
#   scripts/prodlike/down.sh --wipe     also delete the volumes (database, uploads, certs) and the saved TOTP secret;
#                                       the generated secrets (prodlike.env) are kept so APP_KEY stays stable.
set -euo pipefail
here="$(dirname "$0")"
root="$here/../.."
if [ "${1:-}" = "--wipe" ]; then
  # Never touch the shared dev node_modules volume (declared with a fixed name in compose.yaml).
  "$here/dc.sh" --profile browser down --remove-orphans
  for v in private-storage pgsql pgsql-certs caddy-data caddy-config; do
    MSYS_NO_PATHCONV=1 docker volume rm -f "majas-prodlike_${v}" >/dev/null 2>&1 || true
  done
  rm -f "$root/storage/app/prodlike/admin-totp.txt" "$root/storage/app/prodlike/admin-totp.uri" "$root/storage/app/prodlike/caddy-root.crt"
  echo "Prodlike stack removed (volumes wiped)."
else
  "$here/dc.sh" --profile browser down --remove-orphans
  echo "Prodlike stack stopped (volumes kept). Start again: scripts/prodlike/up.sh"
fi
