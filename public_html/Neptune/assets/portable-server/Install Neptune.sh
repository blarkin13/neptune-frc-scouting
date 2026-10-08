#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
SCRIPT_DIR="$(pwd)"
PAYLOAD="$SCRIPT_DIR/payload"
ORG_ENV="$SCRIPT_DIR/portable/organization.env"
IMPORTER="$SCRIPT_DIR/portable-tools/import-organization.php"
ACCOUNT_PREP="$SCRIPT_DIR/portable-tools/prepare-offline-accounts.php"

OS="$(uname -s)"
INVOKING_USER="${SUDO_USER:-${USER:-$(id -un)}}"
USER_HOME="$(eval echo "~${INVOKING_USER}")"
POINTER="$USER_HOME/.neptune-portable-current"

fix_neptune_portable_asset_paths() {
  local header="$APP_ROOT/partials_header.php"
  [[ -f "$header" ]] || return 0

  python3 - "$header" <<'PY'
from pathlib import Path
import sys

p = Path(sys.argv[1])
text = p.read_text()

replacements = {
    'href="/images/logo.png"': 'href="/Neptune/images/logo.png"',
    'href="/images/favicon.png"': 'href="/Neptune/images/favicon.png"',
    'href="/images/app-icon.png"': 'href="/Neptune/images/app-icon.png"',
    'href="/manifest.webmanifest"': 'href="/Neptune/manifest.webmanifest"',
    'href="/assets/css/app.css': 'href="/Neptune/assets/css/app.css',
    'href="/assets/css/neptune-ui.css': 'href="/Neptune/assets/css/neptune-ui.css',
    'src="/assets/js/neptune-ui.js': 'src="/Neptune/assets/js/neptune-ui.js',
    'src="/images/logo.png"': 'src="/Neptune/images/logo.png"',
}
for old, new in replacements.items():
    text = text.replace(old, new)

p.write_text(text)
PY
}


clear
echo "=========================================="
echo " Neptune Portable Server Installer"
echo "=========================================="
echo
echo "Neptune will install into this machine's normal Apache www directory."
echo "Neptune uses its own private local database instance. Existing DB credentials are not required."
echo

[[ -d "$PAYLOAD/public_html/Neptune" ]] || { echo "Missing Neptune application payload."; exit 1; }
[[ -d "$PAYLOAD/neptune_secure" ]] || { echo "Missing Neptune secure payload."; exit 1; }
[[ -f "$PAYLOAD/sql/neptune_schema.sql" ]] || { echo "Missing Neptune schema."; exit 1; }

BREW=""
PKG_MGR=""
APACHE_SERVICE=""
DB_SERVICE=""
HTTP_PORT=80

if [[ "$OS" == "Darwin" ]]; then
  if ! command -v brew >/dev/null 2>&1; then
    if [[ -x /opt/homebrew/bin/brew ]]; then eval "$(/opt/homebrew/bin/brew shellenv)"
    elif [[ -x /usr/local/bin/brew ]]; then eval "$(/usr/local/bin/brew shellenv)"
    fi
  fi
  if ! command -v brew >/dev/null 2>&1; then
    echo "Homebrew is required on macOS."
    read -r -p "Install Homebrew now? Internet is required. [y/N] " a
    if [[ "${a:-}" =~ ^[Yy]$ ]]; then
      /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
      if [[ -x /opt/homebrew/bin/brew ]]; then eval "$(/opt/homebrew/bin/brew shellenv)"
      elif [[ -x /usr/local/bin/brew ]]; then eval "$(/usr/local/bin/brew shellenv)"
      fi
    fi
  fi
  command -v brew >/dev/null 2>&1 || { echo "Homebrew is unavailable."; exit 1; }
  BREW="$(command -v brew)"
  PREFIX="$("$BREW" --prefix)"
  for f in httpd php qrencode; do
    "$BREW" list --formula "$f" >/dev/null 2>&1 || "$BREW" install "$f"
  done

  HTTPD_CONF="$PREFIX/etc/httpd/httpd.conf"
  [[ -f "$HTTPD_CONF" ]] || { echo "Homebrew Apache configuration was not found: $HTTPD_CONF"; exit 1; }

  WEB_ROOT="$(awk '$1=="DocumentRoot"{gsub(/"/,"",$2);print $2;exit}' "$HTTPD_CONF" 2>/dev/null || true)"
  [[ -n "$WEB_ROOT" ]] || WEB_ROOT="$PREFIX/var/www"
  mkdir -p "$WEB_ROOT"

  APP_ROOT="$WEB_ROOT/Neptune"
  WEB_PARENT="$(dirname "$WEB_ROOT")"
  SECURE_ROOT="$WEB_PARENT/neptune_secure"
  STATE_ROOT="$WEB_PARENT/neptune-portable"

  HTTP_PORT="$(awk '$1=="Listen"{print $2;exit}' "$HTTPD_CONF" 2>/dev/null | sed -E 's/.*://')"
  [[ "$HTTP_PORT" =~ ^[0-9]+$ ]] || HTTP_PORT=8080
  APACHE_SERVICE="httpd"
else
  [[ "$EUID" -eq 0 ]] || { echo "Linux installation needs sudo."; exec sudo -E bash "$0" "$@"; }
  if command -v apt-get >/dev/null 2>&1; then
    PKG_MGR=apt
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y apache2 php libapache2-mod-php php-mysql php-curl php-mbstring php-xml php-gd php-zip mariadb-server qrencode zip curl
    APACHE_SERVICE=apache2
  elif command -v dnf >/dev/null 2>&1; then
    PKG_MGR=dnf
    dnf install -y httpd php php-mysqlnd php-curl php-mbstring php-xml php-gd php-zip mariadb-server qrencode zip curl
    APACHE_SERVICE=httpd
  else
    echo "Supported automatic Linux installers currently require apt (Debian/Ubuntu) or dnf (Fedora/RHEL family)."
    exit 1
  fi
  systemctl enable --now "$APACHE_SERVICE"
  systemctl enable --now mariadb 2>/dev/null || systemctl enable --now mysql 2>/dev/null || true
  WEB_ROOT="/var/www/html"
  APP_ROOT="$WEB_ROOT/Neptune"
  SECURE_ROOT="/var/www/neptune_secure"
  STATE_ROOT="/var/lib/neptune-portable"
  HTTP_PORT=80
fi

# Offer to clean any previous/partial portable install before creating a new
# private database. The cleaner removes Neptune's private SQL datadir and local
# credentials but leaves the user's normal MySQL/MariaDB service alone.
OLD_INSTALL_FOUND=0
for old_path in "$APP_ROOT" "$SECURE_ROOT" "$STATE_ROOT" "$POINTER" "$USER_HOME/Neptune Offline Accounts.txt"; do
  [[ -e "$old_path" ]] && OLD_INSTALL_FOUND=1
done

if [[ "$OLD_INSTALL_FOUND" -eq 1 ]]; then
  echo
  echo "Previous or incomplete Neptune portable installation detected."
  echo
  echo "1) Delete old Neptune files, private SQL database, and generated credentials; then continue"
  echo "2) Continue without running cleanup"
  echo "3) Cancel"
  read -r -p "Choose [1-3]: " cleanup_choice

  case "${cleanup_choice:-3}" in
    1)
      bash "$SCRIPT_DIR/Clean Old Neptune.sh" --yes
      ;;
    2)
      echo "Continuing without standalone cleanup. Neptune-owned app/private DB paths will still be replaced."
      ;;
    *)
      echo "Installer cancelled."
      exit 0
      ;;
  esac
fi

# Database setup
#
# macOS uses a private Neptune-owned MariaDB instance. This avoids asking for
# credentials to any existing MariaDB/MySQL installation and avoids touching its
# databases/users. Linux uses the distro MariaDB service through sudo/root.
DB_NAME=neptune_portable
DB_USER=neptune_local
DB_PASS="$(openssl rand -hex 24)"
MFA_KEY="$(openssl rand -hex 32)"
DB_MODE=""
DB_DATA=""
DB_SOCKET=""
DB_PID=""
DB_LOG=""
DB_SERVER_BIN=""
DB_ADMIN_BIN=""

if [[ "$OS" == "Darwin" ]]; then
  # Reuse Homebrew database BINARIES when possible, but never reuse the
  # existing database server/data/credentials. Neptune gets its own datadir,
  # socket and port.
  BREW_FORMULAS="$("$BREW" list --formula 2>/dev/null || true)"
  DB_ENGINE=""
  DB_FORMULA=""

  if printf '%s\n' "$BREW_FORMULAS" | grep -qx 'mysql'; then
    DB_ENGINE=mysql
    DB_FORMULA=mysql
  elif printf '%s\n' "$BREW_FORMULAS" | grep -qx 'mariadb'; then
    DB_ENGINE=mariadb
    DB_FORMULA=mariadb
  else
    # Also support versioned Homebrew formulas when present.
    DB_FORMULA="$(printf '%s\n' "$BREW_FORMULAS" | grep -E '^(mysql|mariadb)@[0-9.]+' | head -1 || true)"
    if [[ "$DB_FORMULA" == mysql@* ]]; then DB_ENGINE=mysql; fi
    if [[ "$DB_FORMULA" == mariadb@* ]]; then DB_ENGINE=mariadb; fi
  fi

  if [[ -z "$DB_ENGINE" ]]; then
    echo "No Homebrew MySQL/MariaDB binaries found. Installing MariaDB for Neptune..."
    "$BREW" install mariadb
    DB_ENGINE=mariadb
    DB_FORMULA=mariadb
  else
    echo "Using installed Homebrew ${DB_FORMULA} binaries for Neptune's private database."
    echo "Your existing ${DB_FORMULA} server, databases and credentials will not be touched."
  fi

  DB_PREFIX="$("$BREW" --prefix "$DB_FORMULA")"

  if [[ "$DB_ENGINE" == "mysql" ]]; then
    DBCLI="$DB_PREFIX/bin/mysql"
    DB_SERVER_BIN="$DB_PREFIX/bin/mysqld"
    DB_ADMIN_BIN="$DB_PREFIX/bin/mysqladmin"
  else
    DBCLI="$DB_PREFIX/bin/mariadb"
    DB_SERVER_BIN="$DB_PREFIX/bin/mariadbd"
    DB_ADMIN_BIN="$DB_PREFIX/bin/mariadb-admin"
    DB_INSTALL_BIN="$DB_PREFIX/bin/mariadb-install-db"
  fi

  [[ -x "$DBCLI" && -x "$DB_SERVER_BIN" ]] || {
    echo "Could not locate database binaries under: $DB_PREFIX"
    exit 1
  }

  DB_MODE=private
  DB_DATA="$STATE_ROOT/database-data"
  DB_SOCKET="$STATE_ROOT/database.sock"
  DB_PID="$STATE_ROOT/database.pid"
  DB_LOG="$STATE_ROOT/database.log"

  # A previous interrupted installer may have left Neptune's private database
  # process running. Stop only that PID before recreating Neptune's private
  # datadir; never touch the user's normal MySQL/MariaDB service.
  if [[ -f "$DB_PID" ]]; then
    OLD_DB_PID="$(cat "$DB_PID" 2>/dev/null || true)"
    if [[ "$OLD_DB_PID" =~ ^[0-9]+$ ]] && kill -0 "$OLD_DB_PID" 2>/dev/null; then
      echo "Stopping previous incomplete Neptune private database..."
      if [[ -S "$DB_SOCKET" && -x "$DB_ADMIN_BIN" ]]; then
        "$DB_ADMIN_BIN" --protocol=socket --socket="$DB_SOCKET" -uroot shutdown >/dev/null 2>&1 || true
      fi
      for _ in {1..10}; do
        kill -0 "$OLD_DB_PID" 2>/dev/null || break
        sleep 1
      done
      if kill -0 "$OLD_DB_PID" 2>/dev/null; then
        kill "$OLD_DB_PID" >/dev/null 2>&1 || true
        sleep 1
      fi
    fi
  fi

  DB_PORT=3307
  while [[ "$DB_PORT" -lt 3400 ]]; do
    if ! (echo >/dev/tcp/127.0.0.1/"$DB_PORT") >/dev/null 2>&1; then break; fi
    DB_PORT=$((DB_PORT+1))
  done
  [[ "$DB_PORT" -lt 3400 ]] || { echo "Could not find a free local database port."; exit 1; }

  rm -rf "$DB_DATA" "$DB_SOCKET" "$DB_PID" "$DB_LOG"
  mkdir -p "$DB_DATA"
  chmod 700 "$STATE_ROOT" "$DB_DATA"

  echo "Initializing Neptune's private ${DB_ENGINE} database..."
  if [[ "$DB_ENGINE" == "mysql" ]]; then
    "$DB_SERVER_BIN" \
      --initialize-insecure \
      --basedir="$DB_PREFIX" \
      --datadir="$DB_DATA" \
      >/dev/null 2>&1
  else
    [[ -x "$DB_INSTALL_BIN" ]] || { echo "mariadb-install-db was not found."; exit 1; }
    "$DB_INSTALL_BIN" \
      --basedir="$DB_PREFIX" \
      --datadir="$DB_DATA" \
      --auth-root-authentication-method=normal \
      --skip-test-db \
      >/dev/null
  fi

  echo "Starting Neptune's private ${DB_ENGINE} instance on 127.0.0.1:$DB_PORT..."
  nohup "$DB_SERVER_BIN" \
    --basedir="$DB_PREFIX" \
    --datadir="$DB_DATA" \
    --socket="$DB_SOCKET" \
    --port="$DB_PORT" \
    --pid-file="$DB_PID" \
    --log-error="$DB_LOG" \
    --bind-address=127.0.0.1 \
    --skip-name-resolve \
    >/dev/null 2>&1 &

  for _ in {1..45}; do
    [[ -S "$DB_SOCKET" ]] && "$DBCLI" --protocol=socket --socket="$DB_SOCKET" -uroot -e 'SELECT 1' >/dev/null 2>&1 && break
    sleep 1
  done
  "$DBCLI" --protocol=socket --socket="$DB_SOCKET" -uroot -e 'SELECT 1' >/dev/null 2>&1 || {
    echo "Neptune's private ${DB_ENGINE} database did not start."
    echo "Log: $DB_LOG"
    tail -80 "$DB_LOG" 2>/dev/null || true
    exit 1
  }

  "$DBCLI" --protocol=socket --socket="$DB_SOCKET" -uroot <<SQL
CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
  DB_SERVICE="neptune-private-$DB_ENGINE"
else
  DBCLI="$(command -v mariadb || command -v mysql || true)"
  [[ -n "$DBCLI" ]] || { echo "MariaDB/MySQL client was not found."; exit 1; }
  DB_MODE=system
  DB_PORT="$("$DBCLI" -uroot -Nse 'SELECT @@port' 2>/dev/null | head -1 || true)"
  [[ "$DB_PORT" =~ ^[0-9]+$ ]] || DB_PORT=3306

  "$DBCLI" -uroot -e 'SELECT 1' >/dev/null 2>&1 || {
    echo "Could not administer the local MariaDB service through sudo/root socket authentication."
    echo "No changes were made to existing databases."
    exit 1
  }

  if "$DBCLI" -uroot -Nse "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DB_NAME'" | grep -qx "$DB_NAME"; then
    echo
    echo "A Neptune portable database already exists."
    echo "1) Back up and replace it"
    echo "2) Cancel"
    read -r -p "Choose [1-2]: " c
    [[ "${c:-2}" == 1 ]] || exit 0
    DUMP="$(command -v mariadb-dump || command -v mysqldump || true)"
    if [[ -n "$DUMP" ]]; then
      BACK="$USER_HOME/Neptune_Preinstall_DB_$(date +%Y%m%d-%H%M%S).sql.gz"
      "$DUMP" -uroot "$DB_NAME" | gzip -9 > "$BACK"
      echo "Backup: $BACK"
    fi
    "$DBCLI" -uroot -e "DROP DATABASE \`$DB_NAME\`;"
  fi

  "$DBCLI" -uroot <<SQL
CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
  DB_SERVICE=mariadb
fi

# Replace only Neptune-owned paths.
rm -rf "$APP_ROOT" "$SECURE_ROOT"
mkdir -p "$APP_ROOT" "$SECURE_ROOT" "$STATE_ROOT/commands" "$STATE_ROOT/portable" "$STATE_ROOT/sql"
cp -R "$PAYLOAD/public_html/Neptune/." "$APP_ROOT/"
fix_neptune_portable_asset_paths
cp -R "$PAYLOAD/neptune_secure/." "$SECURE_ROOT/"
cp "$PAYLOAD/sql/neptune_schema.sql" "$STATE_ROOT/sql/neptune_schema.sql"
cp "$SCRIPT_DIR"/*.sh "$STATE_ROOT/commands/" 2>/dev/null || true
cp "$SCRIPT_DIR"/*.command "$STATE_ROOT/commands/" 2>/dev/null || true
cp "$SCRIPT_DIR/portable-common.sh" "$STATE_ROOT/commands/portable-common.sh"
cp "$IMPORTER" "$STATE_ROOT/portable/import-organization.php"
cp "$ACCOUNT_PREP" "$STATE_ROOT/portable/prepare-offline-accounts.php"
chmod +x "$STATE_ROOT/commands/"*.sh "$STATE_ROOT/commands/"*.command "$STATE_ROOT/commands/portable-common.sh" 2>/dev/null || true

if [[ ! -f "$APP_ROOT/index.php" ]]; then
  echo "ERROR: Neptune application files were not copied into Apache's web root."
  echo "Expected: $APP_ROOT/index.php"
  exit 1
fi
APP_FILE_COUNT="$(find "$APP_ROOT" -type f 2>/dev/null | wc -l | tr -d ' ')"
echo "Neptune application copied to: $APP_ROOT"
echo "Application files: ${APP_FILE_COUNT:-0}"

PACKAGE_TYPE=clean
CLOUD_URL=""
ORGANIZATION_ID=0
SYNC_TOKEN=""
PACKAGE_CREATED_AT=""
if [[ -f "$ORG_ENV" ]]; then
  # Do not "source" downloaded metadata. Read only the exact keys Neptune
  # generated. This also handles timestamps containing spaces safely.
  while IFS='=' read -r key value; do
    [[ -z "${key:-}" ]] && continue
    value="${value%$'\r'}"
    if [[ ${#value} -ge 2 ]]; then
      first="${value:0:1}"
      last="${value: -1}"
      if [[ "$first" == "'" && "$last" == "'" ]]; then
        value="${value:1:${#value}-2}"
        value="${value//\'\\\'\'/\'}"
      elif [[ "$first" == '"' && "$last" == '"' ]]; then
        value="${value:1:${#value}-2}"
      fi
    fi
    case "$key" in
      PACKAGE_TYPE) PACKAGE_TYPE="$value" ;;
      CLOUD_URL) CLOUD_URL="$value" ;;
      ORGANIZATION_ID) ORGANIZATION_ID="$value" ;;
      SYNC_TOKEN) SYNC_TOKEN="$value" ;;
      TOKEN_EXPIRES_AT) TOKEN_EXPIRES_AT="$value" ;;
      PACKAGE_CREATED_AT) PACKAGE_CREATED_AT="$value" ;;
    esac
  done < "$ORG_ENV"
fi
if [[ -f "$SCRIPT_DIR/portable/organization.json" ]]; then
  cp "$SCRIPT_DIR/portable/organization.json" "$STATE_ROOT/portable/original-organization.json"
  chmod 600 "$STATE_ROOT/portable/original-organization.json"
fi
if [[ -f "$SCRIPT_DIR/portable/google-only-users.json" ]]; then
  cp "$SCRIPT_DIR/portable/google-only-users.json" "$STATE_ROOT/portable/google-only-users.json"
  chmod 600 "$STATE_ROOT/portable/google-only-users.json"
fi
cp "$ORG_ENV" "$STATE_ROOT/portable/organization.env" 2>/dev/null || true
chmod 600 "$STATE_ROOT/portable/organization.env" 2>/dev/null || true

TZ_NAME="${TZ:-America/Chicago}"
export NP_DB_NAME="$DB_NAME" NP_DB_USER="$DB_USER" NP_DB_PASS="$DB_PASS" NP_DB_PORT="$DB_PORT"
export NP_TZ="$TZ_NAME" NP_CLOUD_URL="${CLOUD_URL:-}" NP_ORG="${ORGANIZATION_ID:-0}"
export NP_SYNC_TOKEN="${SYNC_TOKEN:-}" NP_CREATED="${PACKAGE_CREATED_AT:-}" NP_MFA="$MFA_KEY"
php -r '
$cfg=[
 "app"=>[
   "base_url"=>"/Neptune",
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
   "sync_token"=>getenv("NP_SYNC_TOKEN")?:"",
   "package_created_at"=>getenv("NP_CREATED")?:"",
 ],
];
file_put_contents($argv[1],"<?php\nreturn ".var_export($cfg,true).";\n");
' "$SECURE_ROOT/config.php"
chmod 600 "$SECURE_ROOT/config.php"

# Apache/PHP must be able to read config.php, but the file contains local DB
# credentials and a portable cloud sync token, so do not make it world-readable.
if [[ "$OS" == "Darwin" ]]; then
  APACHE_RUN_USER="$(awk '$1=="User"{print $2;exit}' "$HTTPD_CONF" 2>/dev/null || true)"
  APACHE_RUN_USER="${APACHE_RUN_USER:-_www}"

  # The Homebrew Apache worker normally runs as _www. Keep config.php mode 600
  # and grant only that worker account read access with a macOS ACL.
  chmod 755 "$SECURE_ROOT"
  chmod -N "$SECURE_ROOT/config.php" 2>/dev/null || true
  chmod +a "${APACHE_RUN_USER} allow read" "$SECURE_ROOT/config.php"
else
  APACHE_RUN_USER="www-data"
  APACHE_RUN_GROUP="www-data"
  if id apache >/dev/null 2>&1; then
    APACHE_RUN_USER="apache"
    APACHE_RUN_GROUP="apache"
  fi
  chown root:"$APACHE_RUN_GROUP" "$SECURE_ROOT/config.php" 2>/dev/null || true
  chmod 640 "$SECURE_ROOT/config.php"
  chmod 755 "$SECURE_ROOT"
fi

unset NP_DB_PASS NP_SYNC_TOKEN NP_MFA DB_PASS MFA_KEY

CLIENT_CNF="$(mktemp)"
chmod 600 "$CLIENT_CNF"
php -r '$c=require $argv[1];printf("[client]\nhost=%s\nport=%d\nuser=%s\npassword=%s\nprotocol=tcp\n",$c["db"]["host"],$c["db"]["port"],$c["db"]["user"],$c["db"]["pass"]);' "$SECURE_ROOT/config.php" > "$CLIENT_CNF"
"$DBCLI" --defaults-extra-file="$CLIENT_CNF" "$DB_NAME" < "$STATE_ROOT/sql/neptune_schema.sql"

OFFLINE_ACCOUNTS="$USER_HOME/Neptune Offline Accounts.txt"
if [[ -f "$STATE_ROOT/portable/original-organization.json" ]]; then
  SNAPSHOT_SIZE="$(du -h "$STATE_ROOT/portable/original-organization.json" 2>/dev/null | awk '{print $1}' || true)"
  echo
  echo "Importing organization snapshot${SNAPSHOT_SIZE:+ ($SNAPSHOT_SIZE)} using streaming import..."
  php -d memory_limit=192M "$STATE_ROOT/portable/import-organization.php" "$STATE_ROOT/portable/original-organization.json" "$SECURE_ROOT/config.php"

  echo "Preparing offline credentials for Google-only users..."
  php "$STATE_ROOT/portable/prepare-offline-accounts.php"     "$SECURE_ROOT/config.php"     "$OFFLINE_ACCOUNTS"     "$STATE_ROOT/portable/google-only-users.json"
  chmod 600 "$OFFLINE_ACCOUNTS"
fi
rm -f "$CLIENT_CNF"

mkdir -p "$APP_ROOT/assets/portable"
cat > "$APP_ROOT/.user.ini" <<'INI'
upload_max_filesize=64M
post_max_size=72M
memory_limit=512M
max_execution_time=300
max_input_time=300
INI

if [[ "$OS" == "Darwin" ]]; then
  APACHE_BIN="$PREFIX/opt/httpd/bin/httpd"
  [[ -x "$APACHE_BIN" ]] || { echo "Homebrew Apache binary was not found: $APACHE_BIN"; exit 1; }

  perl -0pi -e 's/^#(LoadModule rewrite_module modules\/mod_rewrite\.so)/$1/m' "$HTTPD_CONF"

  CONF="$PREFIX/etc/httpd/extra/neptune-portable.conf"
  PHP_MODULE="$PREFIX/opt/php/lib/httpd/modules/libphp.so"
  PHP_MODE=""

  if [[ -f "$PHP_MODULE" ]]; then
    PHP_MODE=module
    if ! grep -Eq '^[[:space:]]*LoadModule[[:space:]]+php_module[[:space:]]+' "$HTTPD_CONF"; then
      printf '\nLoadModule php_module "%s"\n' "$PHP_MODULE" >> "$HTTPD_CONF"
    fi
    cat > "$CONF" <<EOF
  Alias "/assets/" "$APP_ROOT/assets/"
  Alias "/images/" "$APP_ROOT/images/"
  Alias "/manifest.webmanifest" "$APP_ROOT/manifest.webmanifest"

  <Directory "$APP_ROOT/assets">
    Require all granted
  </Directory>
  <Directory "$APP_ROOT/images">
    Require all granted
  </Directory>
<Directory "$APP_ROOT">
  Options -Indexes +FollowSymLinks
  AllowOverride All
  Require all granted
  DirectoryIndex index.php
  <FilesMatch \.php$>
    SetHandler application/x-httpd-php
  </FilesMatch>
</Directory>
EOF
  else
    PHP_MODE=fpm
    perl -0pi -e 's/^#(LoadModule proxy_module modules\/mod_proxy\.so)/$1/m' "$HTTPD_CONF"
    perl -0pi -e 's/^#(LoadModule proxy_fcgi_module modules\/mod_proxy_fcgi\.so)/$1/m' "$HTTPD_CONF"
    "$BREW" services restart php
    cat > "$CONF" <<EOF
  Alias "/assets/" "$APP_ROOT/assets/"
  Alias "/images/" "$APP_ROOT/images/"
  Alias "/manifest.webmanifest" "$APP_ROOT/manifest.webmanifest"

  <Directory "$APP_ROOT/assets">
    Require all granted
  </Directory>
  <Directory "$APP_ROOT/images">
    Require all granted
  </Directory>
<Directory "$APP_ROOT">
  Options -Indexes +FollowSymLinks
  AllowOverride All
  Require all granted
  DirectoryIndex index.php
  <FilesMatch \.php$>
    SetHandler "proxy:fcgi://127.0.0.1:9000"
  </FilesMatch>
</Directory>
EOF
  fi

  grep -Fq "Include \"$CONF\"" "$HTTPD_CONF" || printf '\nInclude "%s"\n' "$CONF" >> "$HTTPD_CONF"

  echo
  echo "Checking Apache configuration..."
  "$APACHE_BIN" -t -f "$HTTPD_CONF"

  echo "Starting Homebrew Apache..."
  "$BREW" services restart httpd

  # Give launchd/httpd a moment to bind before the HTTP readiness check below.
  sleep 2
else
  if [[ "$APACHE_SERVICE" == apache2 ]]; then
    a2enmod rewrite >/dev/null 2>&1 || true
    cat > /etc/apache2/conf-available/neptune-portable.conf <<EOF
  Alias "/assets/" "$APP_ROOT/assets/"
  Alias "/images/" "$APP_ROOT/images/"
  Alias "/manifest.webmanifest" "$APP_ROOT/manifest.webmanifest"

  <Directory "$APP_ROOT/assets">
    Require all granted
  </Directory>
  <Directory "$APP_ROOT/images">
    Require all granted
  </Directory>
<Directory "$APP_ROOT">
  Options -Indexes +FollowSymLinks
  AllowOverride All
  Require all granted
  DirectoryIndex index.php
</Directory>
EOF
    a2enconf neptune-portable >/dev/null 2>&1 || true
  else
    cat > /etc/httpd/conf.d/neptune-portable.conf <<EOF
  Alias "/assets/" "$APP_ROOT/assets/"
  Alias "/images/" "$APP_ROOT/images/"
  Alias "/manifest.webmanifest" "$APP_ROOT/manifest.webmanifest"

  <Directory "$APP_ROOT/assets">
    Require all granted
  </Directory>
  <Directory "$APP_ROOT/images">
    Require all granted
  </Directory>
<Directory "$APP_ROOT">
  Options -Indexes +FollowSymLinks
  AllowOverride All
  Require all granted
  DirectoryIndex index.php
</Directory>
EOF
  fi
  systemctl restart "$APACHE_SERVICE"
fi

# Write a shell-safe local manifest. Some values (notably
# "Neptune Offline Accounts.txt") contain spaces, so raw KEY=value lines are
# not safe to source.
{
  printf 'OS=%q\n' "$OS"
  printf 'WEB_ROOT=%q\n' "$WEB_ROOT"
  printf 'APP_ROOT=%q\n' "$APP_ROOT"
  printf 'SECURE_ROOT=%q\n' "$SECURE_ROOT"
  printf 'STATE_ROOT=%q\n' "$STATE_ROOT"
  printf 'DB_SERVICE=%q\n' "$DB_SERVICE"
  printf 'DB_MODE=%q\n' "$DB_MODE"
  printf 'DB_ENGINE=%q\n' "${DB_ENGINE:-system}"
  printf 'DB_PREFIX=%q\n' "${DB_PREFIX:-}"
  printf 'DBCLI=%q\n' "$DBCLI"
  printf 'DB_SERVER_BIN=%q\n' "$DB_SERVER_BIN"
  printf 'DB_ADMIN_BIN=%q\n' "$DB_ADMIN_BIN"
  printf 'DB_DATA=%q\n' "$DB_DATA"
  printf 'DB_SOCKET=%q\n' "$DB_SOCKET"
  printf 'DB_PID=%q\n' "$DB_PID"
  printf 'DB_LOG=%q\n' "$DB_LOG"
  printf 'APACHE_SERVICE=%q\n' "$APACHE_SERVICE"
  printf 'HTTP_PORT=%q\n' "$HTTP_PORT"
  printf 'OFFLINE_ACCOUNTS=%q\n' "$OFFLINE_ACCOUNTS"
} > "$STATE_ROOT/.neptune-portable-install"
chmod 600 "$STATE_ROOT/.neptune-portable-install"
printf '%s\n' "$STATE_ROOT" > "$POINTER"
chown "$INVOKING_USER" "$POINTER" 2>/dev/null || true
if [[ "$OS" != "Darwin" ]]; then
  chown -R www-data:www-data "$APP_ROOT" 2>/dev/null || chown -R apache:apache "$APP_ROOT" 2>/dev/null || true
fi

# shellcheck disable=SC1090
. "$STATE_ROOT/commands/portable-common.sh"
neptune_load

IP="$(neptune_lan_ip)"
LOCAL_INDEX="$(neptune_index_url_for_ip 127.0.0.1)"
LAN_INDEX="$(neptune_index_url_for_ip "${IP:-127.0.0.1}")"
LAN_BASE="$(neptune_base_url_for_ip "${IP:-127.0.0.1}")"

echo
echo "Waiting for Neptune through Apache..."
HTTP_CODE=""
if ! HTTP_CODE="$(neptune_wait_for_http "$LOCAL_INDEX" 30)"; then
  echo
  echo "ERROR: Apache did not serve Neptune successfully."
  echo "URL checked: $LOCAL_INDEX"
  echo "Last HTTP status: ${HTTP_CODE:-000}"
  if [[ "$OS" == "Darwin" ]]; then
    echo
    echo "Homebrew service status:"
    "$BREW" services list | grep -E '^(httpd|php)[[:space:]]' || true
    echo
    echo "Apache error log:"
    tail -80 "$PREFIX/var/log/httpd/error_log" 2>/dev/null || true
  else
    systemctl --no-pager --full status "$APACHE_SERVICE" 2>/dev/null || true
  fi
  exit 1
fi

mkdir -p "$APP_ROOT/assets/portable"
if command -v qrencode >/dev/null 2>&1 && [[ -n "$IP" ]]; then
  qrencode -o "$APP_ROOT/assets/portable/neptune-lan-qr.png" -s 8 -m 2 "$LAN_INDEX"
  qrencode -o "$APP_ROOT/assets/portable/neptune-ntx-qr.png" -s 8 -m 2 "${LAN_BASE}help/ntx-start.php"
fi

echo
echo "=========================================="
echo " NEPTUNE IS READY"
echo "=========================================="
echo "Apache:      RUNNING (HTTP $HTTP_CODE)"
echo "Installed:   $APP_ROOT"
echo "Local index: $LOCAL_INDEX"
echo "LAN index:   $LAN_INDEX"
echo "Secure:      $SECURE_ROOT"
if [[ -s "$OFFLINE_ACCOUNTS" ]]; then
  echo
  echo "Offline passwords for Google-linked accounts:"
  echo "  $OFFLINE_ACCOUNTS"
fi
echo
echo "Internet is not required for local field operation."
echo "Opening Neptune in your browser..."
sleep 2
neptune_open_url "$LOCAL_INDEX"
