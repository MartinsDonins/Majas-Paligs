#!/bin/sh
# Dev bootstrap: runs the SAME database/sql/roles.sql as production for `majas` and `majas_test`
# (MVP-SPEC A.2). Dev/CI only: majas_owner gets CREATEDB for parallel test databases.
set -e
for db in majas majas_test; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
    -v dbname="$db" \
    -v owner_password="$MAJAS_OWNER_PASSWORD" \
    -v app_password="$MAJAS_APP_PASSWORD" \
    -v retention_password="$MAJAS_RETENTION_PASSWORD" \
    -v owner_createdb=CREATEDB \
    -f /majas-sql/roles.sql
done
# Parallel test databases are cloned from template1 by majas_owner: give them the extensions too.
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname template1 <<'SQL'
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
SQL
