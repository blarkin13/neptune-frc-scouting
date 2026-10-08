#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
if [[ "$(uname -s)" != "Darwin" && "$EUID" -ne 0 ]]; then exec sudo -E bash "$0" "$@"; fi
. "./portable-common.sh"
neptune_load
if [[ "$OS" == "Darwin" ]]; then
  BREW="$(command -v brew || echo /opt/homebrew/bin/brew)"
  "$BREW" services stop httpd >/dev/null || true
  if [[ "${DB_MODE:-}" == "private" && -S "${DB_SOCKET:-}" && -x "${DB_ADMIN_BIN:-}" ]]; then
    "$DB_ADMIN_BIN" --protocol=socket --socket="$DB_SOCKET" -uroot shutdown >/dev/null 2>&1 || true
  fi
else
  systemctl stop "$APACHE_SERVICE"
fi
echo "Neptune web service stopped. Database/data were not removed."
