#!/usr/bin/env bash
set -euo pipefail

YES=0
if [[ "${1:-}" == "--yes" ]]; then YES=1; fi

OS="$(uname -s)"
USER_NAME="${SUDO_USER:-${USER:-$(id -un)}}"
USER_HOME="$(eval echo "~${USER_NAME}")"
POINTER="$USER_HOME/.neptune-portable-current"

if [[ "$OS" == "Darwin" ]]; then
  if ! command -v brew >/dev/null 2>&1; then
    if [[ -x /opt/homebrew/bin/brew ]]; then eval "$(/opt/homebrew/bin/brew shellenv)"
    elif [[ -x /usr/local/bin/brew ]]; then eval "$(/usr/local/bin/brew shellenv)"
    fi
  fi
  BREW="$(command -v brew || true)"
  if [[ -n "$BREW" ]]; then
    PREFIX="$("$BREW" --prefix)"
  elif [[ -d /opt/homebrew ]]; then
    PREFIX=/opt/homebrew
  else
    PREFIX=/usr/local
  fi

  HTTPD_CONF="$PREFIX/etc/httpd/httpd.conf"
  WEB_ROOT="$(awk '$1=="DocumentRoot"{gsub(/"/,"",$2);print $2;exit}' "$HTTPD_CONF" 2>/dev/null || true)"
  [[ -n "$WEB_ROOT" ]] || WEB_ROOT="$PREFIX/var/www"
  WEB_PARENT="$(dirname "$WEB_ROOT")"

  APP_ROOT="$WEB_ROOT/Neptune"
  SECURE_ROOT="$WEB_PARENT/neptune_secure"
  STATE_ROOT="$WEB_PARENT/neptune-portable"
  APACHE_CONF="$PREFIX/etc/httpd/extra/neptune-portable.conf"
else
  [[ "$EUID" -eq 0 ]] || exec sudo -E bash "$0" "$@"
  WEB_ROOT=/var/www/html
  APP_ROOT="$WEB_ROOT/Neptune"
  SECURE_ROOT=/var/www/neptune_secure
  STATE_ROOT=/var/lib/neptune-portable
fi

OFFLINE_ACCOUNTS="$USER_HOME/Neptune Offline Accounts.txt"

echo "=========================================="
echo " Neptune Portable Cleanup"
echo "=========================================="
echo
echo "This removes only Neptune portable/local data:"
echo "  App:         $APP_ROOT"
echo "  Secure:      $SECURE_ROOT"
echo "  Private DB:  $STATE_ROOT"
echo "  Pointer:     $POINTER"
echo "  Credentials: $OFFLINE_ACCOUNTS"
echo
echo "It does NOT uninstall Homebrew, Apache, PHP, MySQL, or MariaDB."
echo "It does NOT delete unrelated databases."
echo

if [[ "$YES" -ne 1 ]]; then
  read -r -p "Type DELETE NEPTUNE to continue: " confirm
  [[ "$confirm" == "DELETE NEPTUNE" ]] || { echo "Cancelled."; exit 0; }
fi

# Stop only a database process whose command line points at Neptune's private
# datadir. This works even if an interrupted install never wrote the manifest.
for pidfile in "$STATE_ROOT/database.pid" "$STATE_ROOT/mariadb.pid"; do
  if [[ -f "$pidfile" ]]; then
    pid="$(cat "$pidfile" 2>/dev/null || true)"
    if [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null; then
      cmd="$(ps -p "$pid" -o command= 2>/dev/null || true)"
      if [[ "$cmd" == *"$STATE_ROOT"* ]]; then
        echo "Stopping Neptune private database (PID $pid)..."
        kill "$pid" >/dev/null 2>&1 || true
        for _ in {1..10}; do
          kill -0 "$pid" 2>/dev/null || break
          sleep 1
        done
        if kill -0 "$pid" 2>/dev/null; then
          kill -9 "$pid" >/dev/null 2>&1 || true
        fi
      fi
    fi
  fi
done

# Catch a previous private server if the pidfile was lost.
while read -r pid; do
  [[ "$pid" =~ ^[0-9]+$ ]] || continue
  cmd="$(ps -p "$pid" -o command= 2>/dev/null || true)"
  if [[ "$cmd" == *"$STATE_ROOT/database-data"* || "$cmd" == *"$STATE_ROOT/mariadb-data"* ]]; then
    echo "Stopping leftover Neptune private database (PID $pid)..."
    kill "$pid" >/dev/null 2>&1 || true
  fi
done < <(pgrep -f "$STATE_ROOT/(database-data|mariadb-data)" 2>/dev/null || true)

if [[ "$OS" == "Darwin" ]]; then
  if [[ -f "$HTTPD_CONF" && -n "${APACHE_CONF:-}" ]]; then
    CONF_PATH="$APACHE_CONF" perl -0pi -e '$p=$ENV{CONF_PATH};s/^Include "\Q$p\E"\n?//mg' "$HTTPD_CONF" || true
  fi
  rm -f "${APACHE_CONF:-}"
else
  rm -f /etc/apache2/conf-enabled/neptune-portable.conf /etc/apache2/conf-available/neptune-portable.conf 2>/dev/null || true
  rm -f /etc/httpd/conf.d/neptune-portable.conf 2>/dev/null || true
fi

rm -rf "$APP_ROOT" "$SECURE_ROOT" "$STATE_ROOT"
rm -f "$POINTER" "$OFFLINE_ACCOUNTS"

if [[ "$OS" == "Darwin" && -n "${BREW:-}" ]]; then
  "$BREW" services restart httpd >/dev/null 2>&1 || true
elif [[ "$OS" != "Darwin" ]]; then
  systemctl reload apache2 >/dev/null 2>&1 || systemctl reload httpd >/dev/null 2>&1 || true
fi

echo
echo "Cleanup complete."
echo "Old Neptune portable files, private SQL data, generated DB credentials,"
echo "sync credentials, MFA key, and offline account password file were removed."
