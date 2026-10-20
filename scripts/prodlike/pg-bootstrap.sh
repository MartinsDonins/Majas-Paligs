#!/bin/sh
# Prodlike bootstrap: database/sql/roles.sql for ONE database (`majas`) with NOCREATEDB — exactly the production
# procedure (BRAIN/MANUAL-TODO.md). No majas_test, no template1 extensions (those are dev/CI only).
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
  -v dbname=majas \
  -v owner_password="$MAJAS_OWNER_PASSWORD" \
  -v app_password="$MAJAS_APP_PASSWORD" \
  -v retention_password="$MAJAS_RETENTION_PASSWORD" \
  -v owner_createdb=NOCREATEDB \
  -f /majas-sql/roles.sql
