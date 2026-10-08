#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
. "./portable-common.sh"
neptune_load
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="${HOME}/Neptune_Portable_Backup_${STAMP}.zip"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
DB_NAME="$(neptune_php_config_value db.name)"
CNF="$TMP/client.cnf"
neptune_client_cnf "$CNF"
DUMP="$(command -v mariadb-dump || command -v mysqldump || true)"
[[ -n "$DUMP" ]] || { echo "mariadb-dump/mysqldump not found."; exit 1; }
mkdir -p "$TMP/backup"
"$DUMP" --defaults-extra-file="$CNF" --single-transaction --quick --triggers --no-tablespaces "$DB_NAME" | gzip -9 > "$TMP/backup/database.sql.gz"
[[ -d "$APP_ROOT/uploads" ]] && cp -R "$APP_ROOT/uploads" "$TMP/backup/uploads"
[[ -d "$APP_ROOT/games" ]] && cp -R "$APP_ROOT/games" "$TMP/backup/games"
(cd "$TMP/backup" && zip -qry "$OUT" .)
echo "Backup created: $OUT"
