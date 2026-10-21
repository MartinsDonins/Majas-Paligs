#!/usr/bin/env bash
# Current 6-digit TOTP code of the prodlike platform admin (secret saved by up.sh), computed by the app's own
# google2fa library inside the web container. A code is accepted ONCE per 30 s window (replay markers in Redis).
#   scripts/prodlike/totp.sh            → 123456
set -euo pipefail
root="$(dirname "$0")/../.."
secret_file="$root/storage/app/prodlike/admin-totp.txt"
[ -s "$secret_file" ] || { echo "No $secret_file — run scripts/prodlike/up.sh" >&2; exit 1; }
secret="$(tr -d ' \r\n' < "$secret_file")"
"$(dirname "$0")/dc.sh" exec -T web php -r 'require "/var/www/html/vendor/autoload.php"; echo (new PragmaRX\Google2FA\Google2FA())->getCurrentOtp($argv[1]), PHP_EOL;' "$secret"
