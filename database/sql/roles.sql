-- Mājas Palīgs — PostgreSQL cluster bootstrap (MVP-SPEC A.2a, PLANNING §4.1).
-- Run ONCE per cluster and database AS SUPERUSER. Idempotent. The same file is used by dev
-- (docker/pgsql/00-bootstrap.sh), CI and production (platform admin, see BRAIN/MANUAL-TODO.md).
--
--   psql -v ON_ERROR_STOP=1 \
--        -v dbname=majas \
--        -v owner_password=... -v app_password=... -v retention_password=... \
--        -v owner_createdb=NOCREATEDB \
--        -f database/sql/roles.sql
--
-- owner_createdb: CREATEDB only in dev/CI (parallel test databases), NOCREATEDB in production.
\set ON_ERROR_STOP on

\if :{?owner_createdb}
\else
\set owner_createdb NOCREATEDB
\endif

-- 1. Roles --------------------------------------------------------------------------------------
SELECT 'CREATE ROLE majas_owner LOGIN NOSUPERUSER NOCREATEROLE NOREPLICATION'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'majas_owner') \gexec
SELECT 'CREATE ROLE majas_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'majas_app') \gexec
SELECT 'CREATE ROLE majas_retention LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'majas_retention') \gexec

ALTER ROLE majas_owner WITH LOGIN NOSUPERUSER NOCREATEROLE :owner_createdb PASSWORD :'owner_password';
ALTER ROLE majas_app WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD :'app_password';
ALTER ROLE majas_retention WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD :'retention_password';

-- 2. Database owned by majas_owner ----------------------------------------------------------------
SELECT format('CREATE DATABASE %I OWNER majas_owner ENCODING ''UTF8'' TEMPLATE template0', :'dbname')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'dbname') \gexec

ALTER DATABASE :"dbname" OWNER TO majas_owner;
REVOKE ALL ON DATABASE :"dbname" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"dbname" TO majas_app, majas_retention;

-- 3. Schema + extensions (inside the database) ------------------------------------------------------
\c :"dbname"

ALTER SCHEMA public OWNER TO majas_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO majas_app, majas_retention;

CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
