#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
if [[ "$(uname -s)" != "Darwin" && "$EUID" -ne 0 ]]; then exec sudo -E bash "$0" "$@"; fi
. "./portable-common.sh"
neptune_load

echo "1) Stop Neptune"
echo "2) Destroy Neptune portable installation"
echo "3) Cancel"
read -r -p "Choose [1-3]: " c
[[ "${c:-3}" == 3 ]] && exit 0
if [[ "$c" == 1 ]]; then exec bash "./Stop Neptune.sh"; fi
[[ "$c" == 2 ]] || exit 1

read -r -p "Create a backup first? [Y/n] " b
if [[ ! "${b:-Y}" =~ ^[Nn]$ ]]; then bash "./Backup Neptune.sh"; fi
read -r -p "Type DESTROY to remove Neptune's local files/database: " confirm
[[ "$confirm" == DESTROY ]] || { echo "Cancelled."; exit 0; }

DB_NAME="$(neptune_php_config_value db.name)"
DB_USER="$(neptune_php_config_value db.user)"

if [[ "$OS" == Darwin && "${DB_MODE:-}" == "private" ]]; then
  if [[ -S "${DB_SOCKET:-}" && -x "${DB_ADMIN_BIN:-}" ]]; then
    "$DB_ADMIN_BIN" --protocol=socket --socket="$DB_SOCKET" -uroot shutdown >/dev/null 2>&1 || true
  elif [[ -f "${DB_PID:-}" ]]; then
    kill "$(cat "$DB_PID")" >/dev/null 2>&1 || true
  fi
else
  "$DBCLI" -uroot -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; DROP USER IF EXISTS '$DB_USER'@'127.0.0.1'; FLUSH PRIVILEGES;" || true
fi

if [[ "$OS" == Darwin ]]; then
  BREW="$(command -v brew || true)"
  if [[ -n "$BREW" ]]; then
    PREFIX="$("$BREW" --prefix)"
    HTTPD_CONF="$PREFIX/etc/httpd/httpd.conf"
    CONF="$PREFIX/etc/httpd/extra/neptune-portable.conf"
    if [[ -f "$HTTPD_CONF" ]]; then
      CONF_PATH="$CONF" perl -0pi -e '$p=$ENV{CONF_PATH};s/^Include "\Q$p\E"\n?//mg' "$HTTPD_CONF" || true
    fi
    rm -f "$CONF"
    "$BREW" services restart httpd >/dev/null || true
  fi
else
  rm -f /etc/apache2/conf-enabled/neptune-portable.conf /etc/apache2/conf-available/neptune-portable.conf 2>/dev/null || true
  rm -f /etc/httpd/conf.d/neptune-portable.conf 2>/dev/null || true
  systemctl reload "$APACHE_SERVICE" 2>/dev/null || true
fi

rm -rf "$APP_ROOT" "$SECURE_ROOT" "$STATE_ROOT"
rm -f "$NEPTUNE_POINTER"
echo "Neptune portable installation destroyed."
echo "Existing Apache/PHP/MariaDB packages were left installed."
