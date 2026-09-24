#!/usr/bin/env bash
set -euo pipefail

ROOT=/var/www/neptune
ENV_DIR=/etc/scout
KEY_FILE="$ENV_DIR/offline-sync.env"

if [[ $EUID -ne 0 ]]; then
  echo "Run with sudo/root."
  exit 1
fi

mkdir -p "$ENV_DIR" "$ROOT/backups"
chmod 700 "$ENV_DIR"

if [[ ! -f "$KEY_FILE" ]]; then
  KEY="$(openssl rand -hex 32)"
  printf 'NEPTUNE_OFFLINE_SYNC_KEY=%s\n' "$KEY" > "$KEY_FILE"
  chmod 600 "$KEY_FILE"
  echo "Created $KEY_FILE"
else
  echo "Using existing $KEY_FILE"
fi

# Make the key visible to Apache/PHP without exposing it in public_html.
if ! grep -q '^SetEnvIf Request_URI' /etc/apache2/conf-available/neptune-offline-sync.conf 2>/dev/null; then
  cat > /etc/apache2/conf-available/neptune-offline-sync.conf <<APACHE
# Neptune offline synchronization secret is loaded by PHP directly from
# /etc/scout/offline-sync.env. This file only documents the feature.
APACHE
fi

a2enconf neptune-offline-sync >/dev/null || true
systemctl reload apache2

DB_ENV=/etc/scout/db.env
if [[ -f "$DB_ENV" ]]; then
  set -a
  # shellcheck disable=SC1090
  . "$DB_ENV"
  set +a
  mysql -h "${DB_HOST:-127.0.0.1}" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < "$ROOT/sql/2026-09-19_offline-sync.sql"
  echo "Database sync log table installed."
else
  echo "WARNING: $DB_ENV was not found; SQL migration was not run."
fi

php -l "$ROOT/public_html/Neptune/api/offline-sync.php"
php -l "$ROOT/public_html/Neptune/api/offline-status.php"

echo
echo "Cloud sync endpoint installed."
echo "Test status: https://YOUR-NEPTUNE-HOST/api/offline-status.php"
