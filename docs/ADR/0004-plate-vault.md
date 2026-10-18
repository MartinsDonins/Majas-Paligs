# ADR 0004 — PlateVault: encrypted plates with a per-building blind index

- Status: accepted (design); implementation in Package C (PARK-001)

## Context
The parking registry must answer "is this plate registered in this house?" without exposing plates or the
plate → apartment link, and a database leak must not reveal plates. CipherSweet indexes are per field, not per
tenant, so equal plates would correlate across buildings.

## Decision
- Ciphertext: libsodium **XChaCha20-Poly1305** with the dedicated `PLATE_ENC_KEY` (never `APP_KEY`); AEAD
  associated data binds `building_id`, table, row id and key version (no ciphertext swapping).
- Lookup index: `HMAC-SHA256(HKDF(PLATE_INDEX_KEY, building_id), normalized_plate)` + key version.
- Rotation re-keys one building per transaction (ciphertext + index together). Keys escrowed offline.
- Implementation (Package C, 2026-10-18): per key version *v* the encryption key is
  `HKDF-SHA256(PLATE_ENC_KEY, info "mp:plate-enc:v{v}")` and the index key is
  `HKDF-SHA256(PLATE_INDEX_KEY, info "plate-bidx:v{v}", salt building_id)`; the ciphertext is
  `nonce(24) ‖ AEAD` with associated data `mp:plate:v{v}|b:{building}|t:{table}|r:{ULID}`. `plates:rekey {slug?}` moves a
  building to *v*+1 under `pg_advisory_xact_lock('plates:'||id)`. A **master** rotation sets the new `PLATE_ENC_KEY`
  and the old one as `PLATE_ENC_KEY_PREVIOUS` (open() falls back to it), runs `plates:rekey` for every building and
  then removes `PLATE_ENC_KEY_PREVIOUS`. Rotating `PLATE_INDEX_KEY` needs no previous key (re-key recomputes every
  index from the decrypted plate); old `plate_lookups` rows keep their old index and age out after 6 months.
- Package A already guarantees the runtime: the image build fails without XChaCha20 or Argon2id
  (`Unit/PlatformCapabilitiesTest`), and the keys exist in `config/majas.php` / `.env.example`.

## Consequences
- Exact-match lookups only; no plate search across buildings by design.
