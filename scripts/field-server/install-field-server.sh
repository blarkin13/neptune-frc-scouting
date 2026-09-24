#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Run with sudo/root."
  exit 1
fi

BUNDLE="${1:-}"
if [[ -z "$BUNDLE" || ! -f "$BUNDLE" ]]; then
  echo "Usage: sudo $0 /path/to/Neptune_Field_Bundle_YYYYMMDD-HHMMSS.tar.gz"
  exit 1
fi

ROOT=/var/www/neptune
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "Installing required packages..."
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y \
  apache2 mariadb-server php libapache2-mod-php php-mysql php-curl php-mbstring \
  php-xml php-gd unzip curl avahi-daemon openssl

systemctl enable --now mariadb apache2 avahi-daemon

echo "Extracting field bundle..."
tar -xzf "$BUNDLE" -C "$TMP"

# shellcheck disable=SC1090
. "$TMP/field-bundle.env"
DB_NAME="${DB_NAME:-scouting}"
LOCAL_DB_USER=neptune_local
LOCAL_DB_PASS="$(openssl rand -hex 24)"

mkdir -p "$ROOT/public_html" "$ROOT/backups" /etc/scout
rm -rf "$ROOT/public_html/Neptune" "$ROOT/neptune_secure"
cp -a "$TMP/public_html/Neptune" "$ROOT/public_html/Neptune"
cp -a "$TMP/neptune_secure" "$ROOT/neptune_secure"
cp -a "$TMP/etc/scout/offline-sync.env" /etc/scout/offline-sync.env
chmod 600 /etc/scout/offline-sync.env

mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$LOCAL_DB_USER'@'127.0.0.1' IDENTIFIED BY '$LOCAL_DB_PASS';
ALTER USER '$LOCAL_DB_USER'@'127.0.0.1' IDENTIFIED BY '$LOCAL_DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$LOCAL_DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

echo "Restoring database..."
gzip -dc "$TMP/database.sql.gz" | mysql "$DB_NAME"

cat > /etc/scout/db.env <<ENV
DB_HOST=127.0.0.1
DB_NAME=$DB_NAME
DB_USER=$LOCAL_DB_USER
DB_PASSWORD=$LOCAL_DB_PASS
ENV
chmod 600 /etc/scout/db.env

cat > /etc/scout/field-mode.env <<ENV
NEPTUNE_FIELD_MODE=1
ENV
chmod 644 /etc/scout/field-mode.env

cat > /etc/apache2/sites-available/neptune-field.conf <<'APACHE'
<VirtualHost *:80>
    ServerName neptune.local
    DocumentRoot /var/www/neptune/public_html/Neptune
    DirectoryIndex index.php

    <Directory /var/www/neptune/public_html/Neptune>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    SetEnv NEPTUNE_FIELD_MODE 1
    ErrorLog ${APACHE_LOG_DIR}/neptune-field-error.log
    CustomLog ${APACHE_LOG_DIR}/neptune-field-access.log combined
</VirtualHost>
APACHE

a2enmod rewrite headers >/dev/null

a2dissite 000-default >/dev/null || true
a2ensite neptune-field >/dev/null

chown -R root:www-data "$ROOT/public_html/Neptune" "$ROOT/neptune_secure"
find "$ROOT/public_html/Neptune" "$ROOT/neptune_secure" -type d -exec chmod 775 {} +
find "$ROOT/public_html/Neptune" "$ROOT/neptune_secure" -type f -exec chmod 664 {} +
for d in "$ROOT/public_html/Neptune/uploads" "$ROOT/public_html/Neptune/uploads/pit" "$ROOT/public_html/Neptune/uploads/team-logos" "$ROOT/public_html/Neptune/games"; do
  if [[ -d "$d" ]]; then chmod 2775 "$d"; chgrp www-data "$d"; fi
done

apache2ctl configtest
systemctl restart apache2

IP="$(hostname -I | awk '{print $1}')"
echo
echo "========================================"
echo "Neptune Field Server is installed."
echo "========================================"
echo "LAN address: http://${IP:-THIS-LAPTOP-IP}/"
echo "mDNS may work at: http://$(hostname).local/"
echo
echo "For the cleanest address on a dedicated field laptop, optionally run:"
echo "  sudo hostnamectl set-hostname neptune"
echo "  sudo systemctl restart avahi-daemon"
echo "Then use: http://neptune.local/"
echo
echo "Recommended: create a DHCP reservation in the event router for this laptop."
echo "That keeps the same IP all weekend."
echo
echo "Local DB credentials are stored only in /etc/scout/db.env."
echo "Cloud sync key is stored only in /etc/scout/offline-sync.env."
echo
echo "Health check:"
curl -fsS http://127.0.0.1/api/offline-status.php || true
echo
