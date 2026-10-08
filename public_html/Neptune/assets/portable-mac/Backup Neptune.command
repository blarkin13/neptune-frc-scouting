#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
# shellcheck disable=SC1091
. "./portable-common.sh"
neptune_load

STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$HOME/Desktop/Neptune_Field_Backup_$STAMP.zip"
TMP="$(mktemp -d -t neptune-backup.XXXXXX)"
trap 'rm -rf "$TMP"' EXIT

DB_NAME="$(neptune_php_config_value db.name)"
CNF="$TMP/client.cnf"
neptune_client_cnf "$CNF"

mkdir -p "$TMP/backup"
"$DBCLI" --defaults-extra-file="$CNF" --version >/dev/null 2>&1 || true

DUMP_TOOL="$(dirname "$DBCLI")/mariadb-dump"
[[ -x "$DUMP_TOOL" ]] || DUMP_TOOL="$(dirname "$DBCLI")/mysqldump"
[[ -x "$DUMP_TOOL" ]] || DUMP_TOOL="$(command -v mariadb-dump || true)"
[[ -x "$DUMP_TOOL" ]] || DUMP_TOOL="$(command -v mysqldump || true)"
[[ -x "$DUMP_TOOL" ]] || { echo "mariadb-dump/mysqldump was not found."; exit 1; }

"$DUMP_TOOL" --defaults-extra-file="$CNF" --single-transaction --quick --triggers --no-tablespaces "$DB_NAME" | gzip -9 > "$TMP/backup/database.sql.gz"

if [[ -d "$NEPTUNE_ROOT/public_html/Neptune/uploads" ]]; then
  cp -R "$NEPTUNE_ROOT/public_html/Neptune/uploads" "$TMP/backup/uploads"
fi
if [[ -d "$NEPTUNE_ROOT/public_html/Neptune/games" ]]; then
  cp -R "$NEPTUNE_ROOT/public_html/Neptune/games" "$TMP/backup/games"
fi

cat > "$TMP/backup/INFO.txt" <<EOF
Neptune Portable Field Backup
Created: $(date -u +%Y-%m-%dT%H:%M:%SZ)
Database: $DB_NAME
Source: $NEPTUNE_ROOT
EOF

(
  cd "$TMP/backup"
  /usr/bin/zip -qry "$OUT" .
)

echo "Backup created:"
echo "  $OUT"
shasum -a 256 "$OUT"
