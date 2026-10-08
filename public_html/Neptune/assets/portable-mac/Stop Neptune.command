#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
# shellcheck disable=SC1091
. "./portable-common.sh"
neptune_load

BREW="$(neptune_brew)"
[[ -n "$BREW" ]] || { echo "Homebrew is unavailable."; exit 1; }

if [[ "${HTTPD_WAS_RUNNING:-0}" == "1" ]]; then
  # Apache existed before Neptune: disable only Neptune's include, then restart.
  if [[ -f "$HTTPD_CONF" ]]; then
    NEPTUNE_CONF="$NEPTUNE_APACHE_CONF" perl -0pi -e '
      $p=$ENV{NEPTUNE_CONF};
      s/^\QInclude "$p"\E$/# NEPTUNE_DISABLED Include "$p"/mg
    ' "$HTTPD_CONF"
  fi
  "$BREW" services restart httpd >/dev/null || true
else
  "$BREW" services stop httpd >/dev/null || true
fi

if [[ "${PHP_WAS_RUNNING:-0}" != "1" ]]; then
  "$BREW" services stop php >/dev/null || true
fi
if [[ "${DB_WAS_RUNNING:-1}" != "1" && "${DB_SERVICE:-external}" != "external" ]]; then
  "$BREW" services stop "$DB_SERVICE" >/dev/null || true
fi

echo "Neptune portable services are stopped."
echo "No Neptune data was deleted."
