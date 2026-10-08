#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
# shellcheck disable=SC1091
. "./portable-common.sh"
neptune_load

BREW="$(neptune_brew || true)"
DB_NAME="$(neptune_php_config_value db.name)"
DB_USER="$(neptune_php_config_value db.user)"

clear
echo "=========================================="
echo " Neptune Portable Server"
echo "=========================================="
echo
echo "1) Stop Neptune (keep files and data)"
echo "2) Reset Neptune database"
echo "3) Destroy Neptune portable installation"
echo "4) Cancel"
echo
read -r -p "Choose [1-4]: " choice

if [[ "${choice:-4}" == "1" ]]; then
  exec "$NEPTUNE_ROOT/commands/Stop Neptune.command"
fi
[[ "${choice:-4}" != "4" ]] || exit 0
[[ "${choice:-}" == "2" || "${choice:-}" == "3" ]] || { echo "Invalid choice."; exit 1; }

echo
read -r -p "Create a backup on the Desktop first? [Y/n] " backup
if [[ ! "${backup:-Y}" =~ ^[Nn]$ ]]; then
  "$NEPTUNE_ROOT/commands/Backup Neptune.command"
fi

ADMIN_CNF=""
cleanup_admin(){ [[ -n "$ADMIN_CNF" && -f "$ADMIN_CNF" ]] && rm -f "$ADMIN_CNF"; }
trap cleanup_admin EXIT

db_admin_mode=""
if "$DBCLI" -uroot -e 'SELECT 1' >/dev/null 2>&1; then
  db_admin_mode="root"
else
  echo
  echo "Database administrator access is required to reset/destroy Neptune."
  read -r -p "MariaDB/MySQL admin username [root]: " ADMIN_USER
  ADMIN_USER="${ADMIN_USER:-root}"
  read -r -s -p "MariaDB/MySQL admin password: " ADMIN_PASS
  echo
  ADMIN_CNF="$(mktemp -t neptune-db-admin.XXXXXX)"
  chmod 600 "$ADMIN_CNF"
  cat > "$ADMIN_CNF" <<EOF
[client]
user=$ADMIN_USER
password=$ADMIN_PASS
EOF
  unset ADMIN_PASS
  "$DBCLI" --defaults-extra-file="$ADMIN_CNF" -e 'SELECT 1' >/dev/null 2>&1 || { echo "Database administrator login failed."; exit 1; }
  db_admin_mode="cnf"
fi

db_admin(){
  if [[ "$db_admin_mode" == "root" ]]; then "$DBCLI" -uroot "$@"; else "$DBCLI" --defaults-extra-file="$ADMIN_CNF" "$@"; fi
}

if [[ "$choice" == "2" ]]; then
  echo
  read -r -p "Type RESET to rebuild Neptune's local database: " confirm
  [[ "$confirm" == "RESET" ]] || { echo "Reset cancelled."; exit 0; }

  DB_PASS="$(neptune_php_config_value db.pass)"
  db_admin <<SQL
DROP DATABASE IF EXISTS \`$DB_NAME\`;
CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
  unset DB_PASS

  CNF="$(mktemp -t neptune-db-client.XXXXXX)"
  neptune_client_cnf "$CNF"
  "$DBCLI" --defaults-extra-file="$CNF" "$DB_NAME" < "$NEPTUNE_ROOT/sql/neptune_schema.sql"
  rm -f "$CNF"

  if [[ -f "$NEPTUNE_ROOT/portable/original-event.json" ]]; then
    php "$NEPTUNE_ROOT/portable/import-event.php" "$NEPTUNE_ROOT/portable/original-event.json" "$NEPTUNE_ROOT/neptune_secure/config.php"
    echo "Event snapshot restored."
  else
    echo "Database reset to a clean Neptune install."
  fi
  exit 0
fi

echo
echo "DESTROY removes Neptune application files, its local database/user,"
echo "and its Apache include. Existing MariaDB/MySQL/Homebrew packages stay installed."
read -r -p "Type DESTROY to continue: " confirm
[[ "$confirm" == "DESTROY" ]] || { echo "Destroy cancelled."; exit 0; }

# Remove Neptune's Apache include before deleting the config file it points at.
if [[ -f "$HTTPD_CONF" ]]; then
  NEPTUNE_CONF="$NEPTUNE_APACHE_CONF" perl -0pi -e '
    $p=$ENV{NEPTUNE_CONF};
    s/^\QInclude "$p"\E\n?//mg;
    s/^\# NEPTUNE_DISABLED \QInclude "$p"\E\n?//mg;
  ' "$HTTPD_CONF"
fi

db_admin -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; DROP USER IF EXISTS '$DB_USER'@'127.0.0.1'; FLUSH PRIVILEGES;"

if [[ -n "$BREW" ]]; then
  if [[ "${HTTPD_WAS_RUNNING:-0}" == "1" ]]; then
    "$BREW" services restart httpd >/dev/null || true
  else
    "$BREW" services stop httpd >/dev/null || true
  fi
  if [[ "${PHP_WAS_RUNNING:-0}" != "1" ]]; then "$BREW" services stop php >/dev/null || true; fi
  if [[ "${DB_WAS_RUNNING:-1}" != "1" && "${DB_SERVICE:-external}" != "external" ]]; then "$BREW" services stop "$DB_SERVICE" >/dev/null || true; fi
fi

ROOT_TO_DELETE="$NEPTUNE_ROOT"
rm -rf "$ROOT_TO_DELETE"
rm -f "$NEPTUNE_POINTER"

echo
echo "Neptune portable installation destroyed."
echo "Homebrew, Apache, PHP and MariaDB/MySQL packages were left installed."
