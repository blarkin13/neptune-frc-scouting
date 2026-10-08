#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
# shellcheck disable=SC1091
. "./portable-common.sh"
neptune_load

BREW="$(neptune_brew)"
[[ -n "$BREW" ]] || { echo "Homebrew is unavailable."; exit 1; }

# Re-enable Neptune's Apache include if Stop Neptune disabled it.
if [[ -f "$HTTPD_CONF" ]]; then
  NEPTUNE_CONF="$NEPTUNE_APACHE_CONF" perl -0pi -e '
    $p=$ENV{NEPTUNE_CONF};
    s/^\# NEPTUNE_DISABLED \QInclude "$p"\E$/Include "$p"/mg
  ' "$HTTPD_CONF"
fi

"$BREW" services start php >/dev/null
if [[ "${DB_SERVICE:-external}" != "external" ]]; then "$BREW" services start "$DB_SERVICE" >/dev/null || true; fi
"$BREW" services start httpd >/dev/null
"$BREW_PREFIX/bin/httpd" -t >/dev/null
"$BREW" services restart httpd >/dev/null

LAN_IP="$(neptune_lan_ip)"
LAN_URL="$(neptune_url_for_ip "${LAN_IP:-localhost}")"
LOCAL_URL="$(neptune_url_for_ip localhost)"

mkdir -p "$NEPTUNE_ROOT/public_html/Neptune/assets/portable"
if command -v qrencode >/dev/null 2>&1 && [[ -n "$LAN_IP" ]]; then
  qrencode -o "$NEPTUNE_ROOT/public_html/Neptune/assets/portable/neptune-lan-qr.png" -s 8 -m 2 "$LAN_URL"
  qrencode -o "$NEPTUNE_ROOT/public_html/Neptune/assets/portable/neptune-ntx-qr.png" -s 8 -m 2 "${LAN_URL}help/ntx-start.php"
fi

echo "Neptune is running."
echo "Local: $LOCAL_URL"
echo "LAN:   $LAN_URL"
echo
open "$LOCAL_URL"
