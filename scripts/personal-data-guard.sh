#!/usr/bin/env sh
# Personal-data guard (CI, MVP-SPEC A.3): DOC/ is never tracked; no phone numbers in tracked files; no real-looking
# Latvian plates in docs/ or BRAIN/. Review fix 0.0.12: numbers are matched in the WhatsApp-export form too
# ("+371 NN NNN NNN", any of space . - between groups) and landlines (6xxxxxxx) are covered.
#
# Usage: sh scripts/personal-data-guard.sh            → scan tracked files (exit 1 on a hit)
#        sh scripts/personal-data-guard.sh --self-test → prove the patterns fire on synthetic fixtures
set -eu

# +371 then 8 digits in any grouping; or a bare 8-digit number starting with 2 (mobile) or 6 (landline).
PHONE='(\+|00)?371[ .-]?[0-9]{2}[ .-]?[0-9]{3}[ .-]?[0-9]{3}|(^|[^0-9A-Za-z])[26][0-9]{7}([^0-9]|$)|(^|[^0-9A-Za-z])[26][0-9][ .-][0-9]{3}[ .-][0-9]{3}([^0-9]|$)'
# Latvian plate shape (2 letters + 1–4 digits), docs/BRAIN only; synthetic examples are allow-listed below.
PLATE='(^|[^A-Za-z0-9])[A-Z]{2}[ -]?[0-9]{1,4}([^A-Za-z0-9]|$)'
# Synthetic test plates and well-known non-plate ids of the same shape (ADR 0002, WCAG SC 2.4, MK 524, EN 301, "QA 2026",
# WebAuthn / JWS algorithm ids ES256 RS256 PS256 HS256 …).
# The allow-list is matched against the MATCHED TOKEN only, never the whole line.
ALLOW_TOKENS='AB[ -]?1234|CD[ -]?5678|ZZ[ -]?0000|AB[ -]?34|(ADR|SC|MK|EU|EN|ISO|RFC|CVE|UTF|SHA|AES|TLS|IP|ID|UI|QR|PE|MP|AC|PR|LV|GT|SS|WP|OS|PG|DB|UX|HQ|QA|ES|RS|PS|HS)[ -]?[0-9]{1,4}'

scan_phones() { grep -nE "$PHONE" | grep -vE '23456789ABCDEFGHJKMNPQRSTUVWXYZ' || true; }
# input: "prefix:match" lines (git grep -o / grep -o) → only tokens that are not allow-listed
filter_plates() { sed -E 's/^(([^:]*:)*)[^A-Za-z0-9]*([A-Z]{2}[ -]?[0-9]{1,4})[^A-Za-z0-9]*$/\1\3/' | grep -vE "(^|:)(${ALLOW_TOKENS})$" || true; }
scan_plates() { grep -noE "$PLATE" | filter_plates; }

if [ "${1:-}" = "--self-test" ]; then
  fail=0
  for s in '+371 21 234 567' '+37121234567' '+371-21-234-567' '0037121234567' 'zvani 21234567 vakarā' 'tālr. 67 123 456' 'Rīga 67123456'; do
    if [ -z "$(printf '%s\n' "$s" | scan_phones)" ]; then echo "self-test: phone NOT caught: $s"; fail=1; fi
  done
  for s in 'invite 23456789ABCDEFGHJKMNPQRSTUVWXYZ' 'version 0.0.12' 'MP-2026-0001' '2026-10-18' 'ULID 01JABCDEFGHJKMNPQRSTVWXYZ0'; do
    if [ -n "$(printf '%s\n' "$s" | scan_phones)" ]; then echo "self-test: false positive (phone): $s"; fail=1; fi
  done
  for s in 'auto KX-4821 stāv pie durvīm' 'numurs HM 7310' 'ADR 0002 un auto KX4821'; do
    if [ -z "$(printf '%s\n' "$s" | scan_plates)" ]; then echo "self-test: plate NOT caught: $s"; fail=1; fi
  done
  for s in 'piemērs AB1234' 'tests ZZ0000' 'ADR 0002' 'WCAG SC 2.4.11'; do
    if [ -n "$(printf '%s\n' "$s" | scan_plates)" ]; then echo "self-test: false positive (plate): $s"; fail=1; fi
  done
  [ "$fail" -eq 0 ] && echo "personal-data guard self-test OK"
  exit "$fail"
fi

status=0

if git ls-files | grep -E '^DOC/'; then echo "DOC/ must never be committed"; status=1; fi

hits="$(git grep -nE "$PHONE" -- ':!*.lock' ':!*.svg' ':!*.woff2' ':!public/' ':!resources/data/' ':!scripts/personal-data-guard.sh'   ':!resources/views/welcome.blade.php' \
  | grep -vE '23456789ABCDEFGHJKMNPQRSTUVWXYZ' || true)"
if [ -n "$hits" ]; then echo "$hits"; echo "Possible phone number in tracked files"; status=1; fi

hits="$(git grep -noE "$PLATE" -- 'docs/' 'BRAIN/' | filter_plates)"
if [ -n "$hits" ]; then echo "$hits"; echo "Possible real car plate in docs/ or BRAIN/ (use the synthetic AB1234 / CD5678 / ZZ0000)"; status=1; fi

exit "$status"
