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
- Package A already guarantees the runtime: the image build fails without XChaCha20 or Argon2id
  (`Unit/PlatformCapabilitiesTest`), and the keys exist in `config/majas.php` / `.env.example`.

## Consequences
- Exact-match lookups only; no plate search across buildings by design.
