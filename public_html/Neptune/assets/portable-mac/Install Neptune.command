#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
SCRIPT_DIR="$(pwd)"
PAYLOAD="$SCRIPT_DIR/payload"
EVENT_ENV="$SCRIPT_DIR/portable/event.env"
IMPORTER="$SCRIPT_DIR/portable-tools/import-event.php"
POINTER="$HOME/.neptune-portable-current"

clear
echo "=========================================="
echo " Neptune Portable Mac Installer"
echo "=========================================="
echo
echo "This installs Neptune for Apache/PHP with a local MariaDB/MySQL database."
echo "Existing compatible MariaDB/MySQL is reused; production AWS credentials are never used."
echo

[[ -d "$PAYLOAD/public_html/Neptune" ]] || { echo "Missing Neptune application payload."; exit 1; }
[[ -d "$PAYLOAD/neptune_secure" ]] || { echo "Missing Neptune secure payload."; exit 1; }
[[ -f "$PAYLOAD/sql/neptune_schema.sql" ]] || { echo "Missing Neptune schema."; exit 1; }

if ! command -v brew >/dev/null 2>&1; then
  if [[ -x /opt/homebrew/bin/brew ]]; then
    eval "$(/opt/homebrew/bin/brew shellenv)"
  elif [[ -x /usr/local/bin/brew ]]; then
    eval "$(/usr/local/bin/brew shellenv)"
  fi
fi

if ! command -v brew >/dev/null 2>&1; then
  echo "Homebrew is required for the supported portable Apache/PHP stack."
  echo "If this Mac will be used offline, install dependencies before going to the event."
  echo
  read -r -p "Install Homebrew now from brew.sh? Internet is required. [y/N] " ans
  if [[ "${ans:-}" =~ ^[Yy]$ ]]; then
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
    if [[ -x /opt/homebrew/bin/brew ]]; then
      eval "$(/opt/homebrew/bin/brew shellenv)"
    elif [[ -x /usr/local/bin/brew ]]; then
      eval "$(/usr/local/bin/brew shellenv)"
    fi
  fi
fi
command -v brew >/dev/null 2>&1 || { echo "Homebrew is still unavailable. Installation stopped."; exit 1; }

BREW="$(command -v brew)"
BREW_PREFIX="$("$BREW" --prefix)"

service_started() {
  "$BREW" services list 2>/dev/null | awk -v n="$1" '$1==n && $2=="started"{found=1} END{exit !found}'
}

HTTPD_WAS_RUNNING=0
PHP_WAS_RUNNING=0
service_started httpd && HTTPD_WAS_RUNNING=1
service_started php && PHP_WAS_RUNNING=1

echo
echo "Checking Apache/PHP tools..."
for formula in httpd php qrencode; do
  if ! "$BREW" list --formula "$formula" >/dev/null 2>&1; then
    echo "Installing $formula..."
    "$BREW" install "$formula"
  else
    echo "$formula already installed."
  fi
done

"$BREW" services start php >/dev/null
"$BREW" services start httpd >/dev/null

# Database detection: reuse a running compatible MariaDB/MySQL before installing one.
DBCLI=""
DB_SERVICE="external"
DB_WAS_RUNNING=1
for c in mariadb mysql; do
  if command -v "$c" >/dev/null 2>&1; then DBCLI="$(command -v "$c")"; break; fi
done

if [[ -z "$DBCLI" ]]; then
  if "$BREW" list --formula mariadb >/dev/null 2>&1; then
    DBCLI="$BREW_PREFIX/bin/mariadb"
    DB_SERVICE="mariadb"
  elif "$BREW" list --formula mysql >/dev/null 2>&1; then
    DBCLI="$BREW_PREFIX/bin/mysql"
    DB_SERVICE="mysql"
  fi
fi

if [[ -n "$DBCLI" ]]; then
  if "$DBCLI" -uroot -e 'SELECT 1' >/dev/null 2>&1; then
    :
  else
    # A client exists but the service may be stopped.
    if "$BREW" list --formula mariadb >/dev/null 2>&1; then
      service_started mariadb || { DB_WAS_RUNNING=0; "$BREW" services start mariadb >/dev/null; }
      DB_SERVICE="mariadb"
    elif "$BREW" list --formula mysql >/dev/null 2>&1; then
      service_started mysql || { DB_WAS_RUNNING=0; "$BREW" services start mysql >/dev/null; }
      DB_SERVICE="mysql"
    fi
  fi
fi

if [[ -z "$DBCLI" ]]; then
  echo
  echo "No MariaDB/MySQL client was found. Installing MariaDB..."
  "$BREW" install mariadb
  DB_WAS_RUNNING=0
  "$BREW" services start mariadb >/dev/null
  DBCLI="$BREW_PREFIX/bin/mariadb"
  DB_SERVICE="mariadb"
fi

# Give a just-started database a moment to accept connections.
for _ in {1..20}; do
  "$DBCLI" -uroot -e 'SELECT 1' >/dev/null 2>&1 && break
  sleep 1
done

ADMIN_CNF=""
cleanup_admin() { [[ -n "$ADMIN_CNF" && -f "$ADMIN_CNF" ]] && rm -f "$ADMIN_CNF"; }
trap cleanup_admin EXIT

db_admin_ready=0
if "$DBCLI" -uroot -e 'SELECT 1' >/dev/null 2>&1; then
  db_admin_ready=1
  DB_ADMIN_MODE="root-nopass"
else
  echo
  echo "The existing database requires an administrative login once so Neptune can create its own database/user."
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
  if "$DBCLI" --defaults-extra-file="$ADMIN_CNF" -e 'SELECT 1' >/dev/null 2>&1; then
    db_admin_ready=1
    DB_ADMIN_MODE="credentials"
  fi
fi
[[ "$db_admin_ready" == "1" ]] || { echo "Could not authenticate to MariaDB/MySQL as an administrator."; exit 1; }

db_admin() {
  if [[ "$DB_ADMIN_MODE" == "root-nopass" ]]; then
    "$DBCLI" -uroot "$@"
  else
    "$DBCLI" --defaults-extra-file="$ADMIN_CNF" "$@"
  fi
}

DB_PORT="$(db_admin -Nse 'SELECT @@port' 2>/dev/null | head -1)"
[[ "$DB_PORT" =~ ^[0-9]+$ ]] || DB_PORT=3306

DEFAULT_ROOT="$HOME/NeptuneEventServer"
read -r -p "Install directory [$DEFAULT_ROOT]: " ROOT
ROOT="${ROOT:-$DEFAULT_ROOT}"

if [[ -e "$ROOT" && -n "$(ls -A "$ROOT" 2>/dev/null || true)" ]]; then
  echo
  echo "The install directory already contains files:"
  echo "  $ROOT"
  read -r -p "Back it up and replace the application files? [y/N] " replace
  [[ "${replace:-}" =~ ^[Yy]$ ]] || { echo "Installation cancelled."; exit 0; }
  BACKUP_ROOT="${ROOT}.preinstall-$(date +%Y%m%d-%H%M%S)"
  mv "$ROOT" "$BACKUP_ROOT"
  echo "Existing install moved to: $BACKUP_ROOT"
fi

DB_NAME="neptune_portable"
DB_USER="neptune_local"
if db_admin -Nse "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DB_NAME'" | grep -qx "$DB_NAME"; then
  echo
  echo "Database '$DB_NAME' already exists."
  echo "  1) Replace it"
  echo "  2) Use a different database name"
  echo "  3) Cancel"
  read -r -p "Choose [1-3]: " dbchoice
  case "${dbchoice:-3}" in
    1) db_admin -e "DROP DATABASE \`$DB_NAME\`;" ;;
    2)
      read -r -p "New database name: " DB_NAME
      [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Database name may use only letters, numbers and underscore."; exit 1; }
      ;;
    *) echo "Installation cancelled."; exit 0 ;;
  esac
fi

DB_PASS="$(openssl rand -hex 24)"
MFA_KEY="$(openssl rand -base64 32 | tr -d '\n')"

db_admin <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

mkdir -p "$ROOT/public_html" "$ROOT/neptune_secure" "$ROOT/sql" "$ROOT/portable" "$ROOT/commands" "$ROOT/apache"
cp -R "$PAYLOAD/public_html/Neptune" "$ROOT/public_html/"
cp -R "$PAYLOAD/neptune_secure/." "$ROOT/neptune_secure/"
cp "$PAYLOAD/sql/neptune_schema.sql" "$ROOT/sql/neptune_schema.sql"
cat > "$ROOT/public_html/Neptune/.user.ini" <<'INI'
upload_max_filesize=64M
post_max_size=72M
memory_limit=256M
max_execution_time=180
max_input_time=180
INI
cp "$SCRIPT_DIR"/*.command "$ROOT/commands/" 2>/dev/null || true
cp "$SCRIPT_DIR/portable-common.sh" "$ROOT/commands/portable-common.sh"
cp "$IMPORTER" "$ROOT/portable/import-event.php"
chmod +x "$ROOT/commands/"*.command "$ROOT/commands/portable-common.sh" 2>/dev/null || true

PACKAGE_TYPE="clean"
CLOUD_URL=""
ORGANIZATION_ID="0"
EVENT_ID="0"
SYNC_TOKEN=""
PACKAGE_CREATED_AT=""
if [[ -f "$EVENT_ENV" ]]; then
  # shellcheck disable=SC1090
  . "$EVENT_ENV"
fi

if [[ -f "$SCRIPT_DIR/portable/event.json" ]]; then
  cp "$SCRIPT_DIR/portable/event.json" "$ROOT/portable/original-event.json"
  chmod 600 "$ROOT/portable/original-event.json"
fi
cp "$EVENT_ENV" "$ROOT/portable/event.env"
chmod 600 "$ROOT/portable/event.env"

read -r -p "Neptune timezone [America/Chicago]: " TZ_NAME
TZ_NAME="${TZ_NAME:-America/Chicago}"

export NP_DB_NAME="$DB_NAME" NP_DB_USER="$DB_USER" NP_DB_PASS="$DB_PASS" NP_DB_PORT="$DB_PORT" NP_TZ="$TZ_NAME"
export NP_CLOUD_URL="${CLOUD_URL:-}" NP_ORG="${ORGANIZATION_ID:-0}" NP_EVENT="${EVENT_ID:-0}"
export NP_SYNC_TOKEN="${SYNC_TOKEN:-}" NP_CREATED="${PACKAGE_CREATED_AT:-}" NP_MFA="$MFA_KEY"

php -r '
$cfg=[
 "app"=>[
   "base_url"=>"",
   "session_name"=>"NEPTUNEPORTABLE",
   "timezone"=>getenv("NP_TZ")?:"UTC",
   "platform_organization_id"=>999999999,
   "allow_public_registration"=>false,
   "platform_owner_auth"=>"strong",
   "mfa_encryption_key"=>getenv("NP_MFA")?:"",
   "portable_mode"=>true,
 ],
 "db"=>[
   "host"=>"127.0.0.1","port"=>(int)(getenv("NP_DB_PORT")?:3306),"name"=>getenv("NP_DB_NAME"),
   "user"=>getenv("NP_DB_USER"),"pass"=>getenv("NP_DB_PASS"),"charset"=>"utf8mb4",
 ],
 "legacy_db"=>["host"=>"127.0.0.1","port"=>3306,"name"=>"XXX","user"=>"XXX","pass"=>"XXX","charset"=>"utf8mb4"],
 "tba"=>["auth_key"=>"","base_url"=>"https://www.thebluealliance.com/api/v3"],
 "statbotics"=>["base_url"=>"https://api.statbotics.io/v3"],
 "portable"=>[
   "mode"=>"field",
   "cloud_url"=>getenv("NP_CLOUD_URL")?:"",
   "organization_id"=>(int)(getenv("NP_ORG")?:0),
   "event_id"=>(int)(getenv("NP_EVENT")?:0),
   "sync_token"=>getenv("NP_SYNC_TOKEN")?:"",
   "package_created_at"=>getenv("NP_CREATED")?:"",
 ],
];
file_put_contents($argv[1],"<?php\nreturn ".var_export($cfg,true).";\n");
' "$ROOT/neptune_secure/config.php"
chmod 600 "$ROOT/neptune_secure/config.php"
unset NP_DB_PASS NP_SYNC_TOKEN NP_MFA DB_PASS MFA_KEY

CLIENT_CNF="$(mktemp -t neptune-db-client.XXXXXX)"
chmod 600 "$CLIENT_CNF"
php -r '$c=require $argv[1];printf("[client]\nhost=%s\nuser=%s\npassword=%s\nprotocol=tcp\n",$c["db"]["host"],$c["db"]["user"],$c["db"]["pass"]);' \
  "$ROOT/neptune_secure/config.php" > "$CLIENT_CNF"

echo
echo "Importing Neptune schema..."
"$DBCLI" --defaults-extra-file="$CLIENT_CNF" "$DB_NAME" < "$ROOT/sql/neptune_schema.sql"

if [[ -f "$ROOT/portable/original-event.json" ]]; then
  echo "Importing event snapshot..."
  php "$ROOT/portable/import-event.php" "$ROOT/portable/original-event.json" "$ROOT/neptune_secure/config.php"
fi
rm -f "$CLIENT_CNF"

HTTPD_CONF="$BREW_PREFIX/etc/httpd/httpd.conf"
[[ -f "$HTTPD_CONF" ]] || { echo "Homebrew Apache configuration was not found."; exit 1; }

# Homebrew Apache + PHP-FPM. Reuse the existing Listen port (normally 8080).
perl -0pi -e 's/^#(LoadModule rewrite_module modules\/mod_rewrite\.so)/$1/m' "$HTTPD_CONF"
perl -0pi -e 's/^#(LoadModule proxy_module modules\/mod_proxy\.so)/$1/m' "$HTTPD_CONF"
perl -0pi -e 's/^#(LoadModule proxy_fcgi_module modules\/mod_proxy_fcgi\.so)/$1/m' "$HTTPD_CONF"

HTTP_PORT="$(awk '$1=="Listen"{print $2;exit}' "$HTTPD_CONF" | sed -E 's/.*://')"
[[ "$HTTP_PORT" =~ ^[0-9]+$ ]] || HTTP_PORT=8080

NEPTUNE_APACHE_CONF="$ROOT/apache/neptune.conf"
cat > "$NEPTUNE_APACHE_CONF" <<EOF
DocumentRoot "$ROOT/public_html/Neptune"
<Directory "$ROOT/public_html/Neptune">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
    DirectoryIndex index.php
    <FilesMatch \.php$>
        SetHandler "proxy:fcgi://127.0.0.1:9000"
    </FilesMatch>
</Directory>
EOF

INCLUDE_LINE="Include \"$NEPTUNE_APACHE_CONF\""
grep -Fqx "$INCLUDE_LINE" "$HTTPD_CONF" || printf '\n%s\n' "$INCLUDE_LINE" >> "$HTTPD_CONF"

"$BREW_PREFIX/bin/httpd" -t
"$BREW" services restart php >/dev/null
"$BREW" services restart httpd >/dev/null

cat > "$ROOT/.neptune-portable-install" <<EOF
NEPTUNE_ROOT='$ROOT'
BREW_PREFIX='$BREW_PREFIX'
DBCLI='$DBCLI'
DB_SERVICE='$DB_SERVICE'
DB_WAS_RUNNING='$DB_WAS_RUNNING'
HTTPD_WAS_RUNNING='$HTTPD_WAS_RUNNING'
PHP_WAS_RUNNING='$PHP_WAS_RUNNING'
HTTP_PORT='$HTTP_PORT'
HTTPD_CONF='$HTTPD_CONF'
NEPTUNE_APACHE_CONF='$NEPTUNE_APACHE_CONF'
PACKAGE_TYPE='${PACKAGE_TYPE:-clean}'
EOF
chmod 600 "$ROOT/.neptune-portable-install"
printf '%s\n' "$ROOT" > "$POINTER"
chmod 600 "$POINTER"

mkdir -p "$ROOT/public_html/Neptune/assets/portable"
LAN_IP=""
for iface in en0 en1 en2 en3 en4 en5; do
  candidate="$(ipconfig getifaddr "$iface" 2>/dev/null || true)"
  if [[ "$candidate" =~ ^10\. || "$candidate" =~ ^192\.168\. || "$candidate" =~ ^172\.(1[6-9]|2[0-9]|3[01])\. ]]; then LAN_IP="$candidate"; break; fi
done
if [[ -z "$LAN_IP" ]]; then
  LAN_IP="$(ifconfig 2>/dev/null | awk '/inet /{print $2}' | grep -Ev '^(127\.|169\.254\.)' | head -1 || true)"
fi

if [[ "$HTTP_PORT" == "80" ]]; then
  LOCAL_URL="http://localhost/"
  LAN_URL="http://${LAN_IP:-localhost}/"
else
  LOCAL_URL="http://localhost:${HTTP_PORT}/"
  LAN_URL="http://${LAN_IP:-localhost}:${HTTP_PORT}/"
fi

if command -v qrencode >/dev/null 2>&1 && [[ -n "$LAN_IP" ]]; then
  qrencode -o "$ROOT/public_html/Neptune/assets/portable/neptune-lan-qr.png" -s 8 -m 2 "$LAN_URL"
  qrencode -o "$ROOT/public_html/Neptune/assets/portable/neptune-ntx-qr.png" -s 8 -m 2 "${LAN_URL}help/ntx-start.php"
fi

xattr -dr com.apple.quarantine "$ROOT" 2>/dev/null || true

echo
echo "=========================================="
echo " Neptune Portable Server Installed"
echo "=========================================="
echo "Local URL: $LOCAL_URL"
echo "LAN URL:   $LAN_URL"
echo
echo "Use a router/switch with no WAN connection if desired."
echo "All scout devices only need to reach this Mac over the local LAN."
echo
echo "Commands copied to:"
echo "  $ROOT/commands"
echo
echo "Database credentials are local-only and stored in:"
echo "  $ROOT/neptune_secure/config.php"
echo
read -r -p "Press Return to open Neptune..." _
open "$LOCAL_URL"
