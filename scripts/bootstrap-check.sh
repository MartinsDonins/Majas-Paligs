#!/usr/bin/env sh
# AC 20 — fresh-cluster bootstrap check (MVP-SPEC A.2a, Feature/Db/BootstrapTest end-to-end part).
# Creates a brand-new database with ONLY database/sql/roles.sql (as superuser), then migrates it from zero as
# majas_owner and drops it again. Needs: psql, a superuser (PGHOST/PGPORT/PGUSER/PGPASSWORD) and php artisan.
#   PGHOST=pgsql PGUSER=postgres PGPASSWORD=... DB_OWNER_PASSWORD=... sh scripts/bootstrap-check.sh
set -eu
DB="majas_bootstrap_check_$$"
cd "$(dirname "$0")/.."

psql -v ON_ERROR_STOP=1 -d postgres \
  -v dbname="$DB" \
  -v owner_password="${DB_OWNER_PASSWORD:-owner-dev-only}" \
  -v app_password="${DB_PASSWORD:-app-dev-only}" \
  -v retention_password="${DB_RETENTION_PASSWORD:-retention-dev-only}" \
  -v owner_createdb="${OWNER_CREATEDB:-CREATEDB}" \
  -f database/sql/roles.sql >/dev/null

cleanup() { psql -d postgres -c "DROP DATABASE IF EXISTS \"$DB\"" >/dev/null; }
trap cleanup EXIT

DB_DATABASE="$DB" DB_HOST="${PGHOST}" php artisan migrate --database=pgsql_owner --force --no-interaction
echo "bootstrap-check OK: $DB migrated from zero as majas_owner"
