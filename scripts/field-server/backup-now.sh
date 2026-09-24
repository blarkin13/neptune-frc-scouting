#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Run with sudo/root."
  exit 1
fi

ROOT=/var/www/neptune
DB_ENV=/etc/scout/db.env
BACKUPS="$ROOT/backups"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$BACKUPS/Neptune_Field_Backup_${STAMP}.tar.gz"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

set -a
# shellcheck disable=SC1090
. "$DB_ENV"
set +a

mkdir -p "$BACKUPS" "$TMP/backup"
mysqldump --single-transaction --quick --triggers --no-tablespaces \
  -h "${DB_HOST:-127.0.0.1}" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" | gzip -9 > "$TMP/backup/database.sql.gz"

if [[ -d "$ROOT/public_html/Neptune/uploads" ]]; then
  cp -a "$ROOT/public_html/Neptune/uploads" "$TMP/backup/uploads"
fi

cat > "$TMP/backup/INFO.txt" <<INFO
Neptune Field Backup
Created UTC: $(date -u +%Y-%m-%dT%H:%M:%SZ)
Host: $(hostname)
Database: $DB_NAME
INFO

tar -C "$TMP/backup" -czf "$OUT" .
sha256sum "$OUT" > "$OUT.sha256"
chmod 600 "$OUT" "$OUT.sha256"
echo "Backup created: $OUT"
echo "Checksum: $OUT.sha256"
