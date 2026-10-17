<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Append-only, hash-chained audit log (PLANNING §5 A09, MVP-SPEC A.2a/A.4, SEC-003).
 *
 * - One chain per building (chain_key = building_id) + the platform chain (chain_key = 0).
 * - BEFORE INSERT trigger (SECURITY DEFINER, owner majas_owner, fixed search_path) takes a per-chain
 *   advisory lock, assigns the id AFTER the lock (so id order = chain order), sets created_at = now(),
 *   prev_hash from audit_chain_heads, hash = sha256(prev_hash || canonical bytes) and moves the head.
 * - BEFORE UPDATE: always rejected. BEFORE DELETE: separate SECURITY INVOKER guard — only
 *   majas_retention, only rows older than the retention window, only rows covered by an anchor.
 * - audit_chain_heads: written only by the definer trigger; SELECT-only for app/retention.
 * - audit_chain_anchors: append-only, INSERT only by majas_retention, anchor_hash must equal the hash
 *   of the row it anchors (checked at insert).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE SEQUENCE audit_entries_id_seq AS bigint;

CREATE TABLE audit_entries (
    id               bigint PRIMARY KEY,
    chain_key        bigint      NOT NULL,
    building_id      bigint      NULL,
    actor_user_id    bigint      NULL,
    actor_role       text        NULL,
    action           text        NOT NULL,
    subject_type     text        NULL,
    subject_id       text        NULL,
    subject_user_ids bigint[]    NOT NULL DEFAULT '{}',
    reason_code      text        NULL,
    reason_text      text        NULL,
    data             jsonb       NOT NULL DEFAULT '{}'::jsonb,
    ip_hmac          text        NULL,
    ua_hash          text        NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    prev_hash        bytea       NULL,
    hash             bytea       NULL,
    CONSTRAINT audit_entries_chain_check CHECK (chain_key >= 0 AND (chain_key = 0) = (building_id IS NULL) AND (building_id IS NULL OR chain_key = building_id)),
    CONSTRAINT audit_entries_action_check CHECK (action ~ '^[a-z_]+(\.[a-z_]+)+$')
);
ALTER SEQUENCE audit_entries_id_seq OWNED BY audit_entries.id;
CREATE INDEX audit_entries_chain_idx ON audit_entries (chain_key, id);
CREATE INDEX audit_entries_building_created_idx ON audit_entries (building_id, created_at);
CREATE INDEX audit_entries_subjects_gin ON audit_entries USING gin (subject_user_ids);

CREATE TABLE audit_chain_heads (
    chain_key bigint PRIMARY KEY,
    last_id   bigint NOT NULL,
    last_hash bytea  NOT NULL
);

CREATE TABLE audit_chain_anchors (
    id                bigserial PRIMARY KEY,
    chain_key         bigint      NOT NULL,
    pruned_through_id bigint      NOT NULL,
    anchor_hash       bytea       NOT NULL,
    pruned_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX audit_chain_anchors_chain_idx ON audit_chain_anchors (chain_key, pruned_through_id);

-- Canonical bytes of an audit row (used by the trigger AND by AuditChainVerifier).
CREATE OR REPLACE FUNCTION audit_entry_canonical(e audit_entries) RETURNS bytea
LANGUAGE sql IMMUTABLE SET search_path = pg_catalog, public AS $fn$
    SELECT convert_to(
        concat_ws(chr(31),
            e.id::text,
            e.chain_key::text,
            coalesce(e.building_id::text, ''),
            coalesce(e.actor_user_id::text, ''),
            coalesce(e.actor_role, ''),
            e.action,
            coalesce(e.subject_type, ''),
            coalesce(e.subject_id, ''),
            coalesce(array_to_string(e.subject_user_ids, ','), ''),
            coalesce(e.reason_code, ''),
            coalesce(e.reason_text, ''),
            coalesce(e.data::text, '{}'),
            coalesce(e.ip_hmac, ''),
            coalesce(e.ua_hash, ''),
            to_char(e.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"')
        ),
        'UTF8'
    )
$fn$;

CREATE OR REPLACE FUNCTION audit_entries_chain_insert() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $fn$
DECLARE
    v_prev bytea;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('audit:' || NEW.chain_key::text, 0));
    NEW.id := nextval('public.audit_entries_id_seq');
    NEW.created_at := now();
    SELECT last_hash INTO v_prev FROM public.audit_chain_heads WHERE chain_key = NEW.chain_key;
    IF NOT FOUND THEN
        v_prev := decode(repeat('00', 32), 'hex');
    END IF;
    NEW.prev_hash := v_prev;
    NEW.hash := sha256(v_prev || public.audit_entry_canonical(NEW));
    INSERT INTO public.audit_chain_heads (chain_key, last_id, last_hash)
         VALUES (NEW.chain_key, NEW.id, NEW.hash)
    ON CONFLICT (chain_key) DO UPDATE SET last_id = EXCLUDED.last_id, last_hash = EXCLUDED.last_hash;
    RETURN NEW;
END
$fn$;

CREATE OR REPLACE FUNCTION audit_reject_update() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER AS $fn$
BEGIN
    RAISE EXCEPTION 'audit tables are append-only (% on %)', TG_OP, TG_TABLE_NAME USING ERRCODE = '42501';
END
$fn$;

-- SECURITY INVOKER on purpose: inside a DEFINER function current_user would be majas_owner.
CREATE OR REPLACE FUNCTION audit_entries_delete_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER AS $fn$
BEGIN
    IF current_user <> 'majas_retention' OR session_user <> 'majas_retention' THEN
        RAISE EXCEPTION 'audit_entries: DELETE allowed only for majas_retention' USING ERRCODE = '42501';
    END IF;
    IF OLD.created_at >= now() - interval '24 months' THEN
        RAISE EXCEPTION 'audit_entries: row % is inside the retention window', OLD.id USING ERRCODE = '42501';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM audit_chain_anchors a
         WHERE a.chain_key = OLD.chain_key AND a.pruned_through_id >= OLD.id
    ) THEN
        RAISE EXCEPTION 'audit_entries: write an anchor before pruning row %', OLD.id USING ERRCODE = '42501';
    END IF;
    RETURN OLD;
END
$fn$;

CREATE OR REPLACE FUNCTION audit_chain_anchors_insert_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER AS $fn$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM audit_entries e
         WHERE e.id = NEW.pruned_through_id AND e.chain_key = NEW.chain_key AND e.hash = NEW.anchor_hash
    ) THEN
        RAISE EXCEPTION 'audit_chain_anchors: anchor does not match an existing audit row' USING ERRCODE = '42501';
    END IF;
    NEW.pruned_at := now();
    RETURN NEW;
END
$fn$;

CREATE TRIGGER audit_entries_chain BEFORE INSERT ON audit_entries
    FOR EACH ROW EXECUTE FUNCTION audit_entries_chain_insert();
CREATE TRIGGER audit_entries_no_update BEFORE UPDATE ON audit_entries
    FOR EACH ROW EXECUTE FUNCTION audit_reject_update();
CREATE TRIGGER audit_entries_delete BEFORE DELETE ON audit_entries
    FOR EACH ROW EXECUTE FUNCTION audit_entries_delete_guard();
CREATE TRIGGER audit_chain_anchors_insert BEFORE INSERT ON audit_chain_anchors
    FOR EACH ROW EXECUTE FUNCTION audit_chain_anchors_insert_guard();
CREATE TRIGGER audit_chain_anchors_no_update BEFORE UPDATE OR DELETE ON audit_chain_anchors
    FOR EACH ROW EXECUTE FUNCTION audit_reject_update();

-- Grants (default privileges gave majas_app full DML; narrow them).
REVOKE UPDATE, DELETE, TRUNCATE ON audit_entries FROM majas_app;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON audit_chain_heads FROM majas_app;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON audit_chain_anchors FROM majas_app;
REVOKE ALL ON SEQUENCE audit_entries_id_seq FROM majas_app;
REVOKE ALL ON FUNCTION audit_entries_chain_insert() FROM PUBLIC;
GRANT SELECT, INSERT, DELETE ON audit_entries TO majas_retention;
GRANT SELECT ON audit_chain_heads TO majas_retention;
GRANT SELECT, INSERT ON audit_chain_anchors TO majas_retention;
GRANT USAGE ON SEQUENCE audit_chain_anchors_id_seq TO majas_retention;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS audit_chain_anchors;
DROP TABLE IF EXISTS audit_chain_heads;
DROP TABLE IF EXISTS audit_entries;
DROP FUNCTION IF EXISTS audit_chain_anchors_insert_guard();
DROP FUNCTION IF EXISTS audit_entries_delete_guard();
DROP FUNCTION IF EXISTS audit_reject_update();
DROP FUNCTION IF EXISTS audit_entries_chain_insert();
DROP FUNCTION IF EXISTS audit_entry_canonical(audit_entries);
SQL);
    }
};
