#!/usr/bin/env bash
set -euo pipefail
ROOT="${NEPTUNE_ROOT:-/var/www/neptune}"
DB_NAME="${NEPTUNE_DB_NAME:-neptune}"
EXPECTED="${1:-$ROOT/sql/EXPECTED_TABLES.txt}"

fail=0
for bin in apache2ctl mariadb php curl; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "FAIL missing command: $bin"
    fail=1
  else
    echo "OK   command: $bin"
  fi
done

if [[ -f "$ROOT/neptune_secure/config.php" ]]; then echo "OK   secure config"; else echo "FAIL secure config"; fail=1; fi
if [[ -f "$EXPECTED" ]]; then
  while IFS= read -r table; do
    [[ -z "$table" ]] && continue
    n="$(mariadb -N -B "$DB_NAME" -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='${table}';" 2>/dev/null || echo 0)"
    if [[ "$n" == "1" ]]; then echo "OK   table $table"; else echo "FAIL table $table"; fail=1; fi
  done < "$EXPECTED"
else
  echo "FAIL expected table manifest not found: $EXPECTED"
  fail=1
fi

while IFS= read -r -d '' f; do
  if ! php -l "$f" >/dev/null; then echo "FAIL PHP $f"; fail=1; fi
 done < <(find "$ROOT/public_html/Neptune" "$ROOT/neptune_secure" -type f -name '*.php' -print0 2>/dev/null)

if curl -fsS --max-time 5 http://127.0.0.1/health.php 2>/dev/null | grep -qx ok; then
  echo "OK   HTTP health"
else
  echo "WARN HTTP health did not answer at the default vhost; retry with the configured Host header if needed."
fi

if [[ "$fail" -ne 0 ]]; then
  echo "Neptune verification FAILED"
  exit 1
fi

echo "Neptune verification PASSED"
