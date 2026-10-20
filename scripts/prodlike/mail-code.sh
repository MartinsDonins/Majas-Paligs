#!/usr/bin/env bash
# Newest 6-digit login / step-up code mailed to an address, read from Mailpit's API (from inside the web container,
# which is on the stack network). UI: http://127.0.0.1:8036
#   scripts/prodlike/mail-code.sh admin@example.test   → 123456
set -euo pipefail
to="${1:?usage: mail-code.sh <email>}"
"$(dirname "$0")/dc.sh" exec -T web php -r '
  $q = http_build_query(["query" => "to:\"".$argv[1]."\"", "limit" => 1]);
  $id = json_decode((string) @file_get_contents("http://mailpit:8025/api/v1/search?".$q), true)["messages"][0]["ID"] ?? null;
  if (! $id) { fwrite(STDERR, "no mail for ".$argv[1]."\n"); exit(1); }
  $text = json_decode((string) file_get_contents("http://mailpit:8025/api/v1/message/".$id), true)["Text"] ?? "";
  if (! preg_match("/(?<!\d)(\d{3}) (\d{3})(?!\d)/", $text, $m)) { fwrite(STDERR, "no code in newest mail\n"); exit(1); }
  echo $m[1].$m[2], PHP_EOL;' "$to"
