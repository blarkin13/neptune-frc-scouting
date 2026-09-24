#!/usr/bin/env bash
set -euo pipefail

ROOT=/var/www/neptune
WEB="$ROOT/public_html/Neptune"
SECURE="$ROOT/neptune_secure"
DB_ENV=/etc/scout/db.env
SYNC_ENV=/etc/scout/offline-sync.env
BACKUPS="$ROOT/backups"
STAMP="$(date +%Y%m%d-%H%M%S)"
WORK="$(mktemp -d)"
BUNDLE="$BACKUPS/Neptune_Field_Bundle_${STAMP}.tar.gz"
trap 'rm -rf "$WORK"' EXIT

if [[ $EUID -ne 0 ]]; then
  echo "Run with sudo/root."
  exit 1
fi
for p in "$WEB" "$SECURE" "$DB_ENV" "$SYNC_ENV"; do
  [[ -e "$p" ]] || { echo "Missing required path: $p"; exit 1; }
done

set -a
# shellcheck disable=SC1090
. "$DB_ENV"
set +a

mkdir -p "$BACKUPS" "$WORK/payload/public_html" "$WORK/payload/etc/scout"

echo "Copying Neptune application..."
cp -a "$WEB" "$WORK/payload/public_html/Neptune"
cp -a "$SECURE" "$WORK/payload/neptune_secure"
cp -a "$SYNC_ENV" "$WORK/payload/etc/scout/offline-sync.env"

# Do not ship the AWS DB credentials. The field installer creates a local DB user.
printf 'DB_NAME=%q\n' "$DB_NAME" > "$WORK/payload/field-bundle.env"

# Remove transient cache/session files if present.
find "$WORK/payload" -name '.DS_Store' -delete 2>/dev/null || true
find "$WORK/payload" -name '__MACOSX' -type d -prune -exec rm -rf {} + 2>/dev/null || true

echo "Dumping database $DB_NAME..."
mysqldump \
  --single-transaction \
  --quick \
  --triggers \
  --no-tablespaces \
  -h "${DB_HOST:-127.0.0.1}" \
  -u "$DB_USER" \
  -p"$DB_PASSWORD" \
  "$DB_NAME" | gzip -9 > "$WORK/payload/database.sql.gz"

cat > "$WORK/payload/BUNDLE_INFO.txt" <<INFO
Neptune Field Bundle
Created UTC: $(date -u +%Y-%m-%dT%H:%M:%SZ)
Source host: $(hostname -f 2>/dev/null || hostname)
Database: $DB_NAME
Web root source: $WEB
INFO

mkdir -p "$BACKUPS"
tar -C "$WORK/payload" -czf "$BUNDLE" .
sha256sum "$BUNDLE" > "$BUNDLE.sha256"
chmod 600 "$BUNDLE" "$BUNDLE.sha256"

echo
echo "Created:"
echo "  $BUNDLE"
echo "  $BUNDLE.sha256"
echo
echo "Copy both files to the field laptop."
