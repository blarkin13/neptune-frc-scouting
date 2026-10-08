#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
. "./portable-common.sh"
neptune_load

echo "Repairing Neptune portable asset paths..."
HEADER="$APP_ROOT/partials_header.php"
if [[ -f "$HEADER" ]]; then
  python3 - "$HEADER" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1])
text=p.read_text()
replacements={
    'href="/images/logo.png"':'href="/Neptune/images/logo.png"',
    'href="/images/favicon.png"':'href="/Neptune/images/favicon.png"',
    'href="/images/app-icon.png"':'href="/Neptune/images/app-icon.png"',
    'href="/manifest.webmanifest"':'href="/Neptune/manifest.webmanifest"',
    'href="/assets/css/app.css':'href="/Neptune/assets/css/app.css',
    'href="/assets/css/neptune-ui.css':'href="/Neptune/assets/css/neptune-ui.css',
    'src="/assets/js/neptune-ui.js':'src="/Neptune/assets/js/neptune-ui.js',
    'src="/images/logo.png"':'src="/Neptune/images/logo.png"',
}
for old,new in replacements.items():
    text=text.replace(old,new)
p.write_text(text)
PY
fi


echo "=========================================="
echo " Neptune Portable Repair"
echo "=========================================="
echo

if [[ ! -f "$SECURE_ROOT/config.php" ]]; then
  echo "Neptune config was not found:"
  echo "  $SECURE_ROOT/config.php"
  exit 1
fi

if [[ "$OS" == "Darwin" ]]; then
  if ! command -v brew >/dev/null 2>&1; then
    if [[ -x /opt/homebrew/bin/brew ]]; then eval "$(/opt/homebrew/bin/brew shellenv)"
    elif [[ -x /usr/local/bin/brew ]]; then eval "$(/usr/local/bin/brew shellenv)"
    fi
  fi
  BREW="$(command -v brew)"
  PREFIX="$("$BREW" --prefix)"
  HTTPD_CONF="$PREFIX/etc/httpd/httpd.conf"
  APACHE_RUN_USER="$(awk '$1=="User"{print $2;exit}' "$HTTPD_CONF" 2>/dev/null || true)"
  APACHE_RUN_USER="${APACHE_RUN_USER:-_www}"

  echo "Granting Apache worker (${APACHE_RUN_USER}) access to Neptune's protected config..."
  chmod 755 "$SECURE_ROOT"
  chmod 600 "$SECURE_ROOT/config.php"
  chmod -N "$SECURE_ROOT/config.php" 2>/dev/null || true
  chmod +a "${APACHE_RUN_USER} allow read" "$SECURE_ROOT/config.php"

  CONF="$PREFIX/etc/httpd/extra/neptune-portable.conf"
  if [[ -f "$CONF" && ! $(grep -F 'Alias "/assets/"' "$CONF" || true) ]]; then
    TMP_CONF="$(mktemp)"
    awk -v app="$APP_ROOT" '
      BEGIN{done=0}
      !done && index($0,"<Directory \"" app "\">")==1 {
        print "Alias \"/assets/\" \"" app "/assets/\""
        print "Alias \"/images/\" \"" app "/images/\""
        print "Alias \"/manifest.webmanifest\" \"" app "/manifest.webmanifest\""
        print ""
        print "<Directory \"" app "/assets\">"
        print "  Require all granted"
        print "</Directory>"
        print "<Directory \"" app "/images\">"
        print "  Require all granted"
        print "</Directory>"
        print ""
        done=1
      }
      {print}
    ' "$CONF" > "$TMP_CONF"
    mv "$TMP_CONF" "$CONF"
  fi

  echo "Restarting Homebrew Apache..."
  "$BREW" services restart httpd
else
  if [[ "$EUID" -ne 0 ]]; then exec sudo -E bash "$0" "$@"; fi
  APACHE_RUN_GROUP=www-data
  APACHE_SERVICE=apache2
  if id apache >/dev/null 2>&1; then
    APACHE_RUN_GROUP=apache
    APACHE_SERVICE=httpd
  fi
  chmod 755 "$SECURE_ROOT"
  chown root:"$APACHE_RUN_GROUP" "$SECURE_ROOT/config.php" 2>/dev/null || true
  chmod 640 "$SECURE_ROOT/config.php"
  systemctl restart "$APACHE_SERVICE"
fi

IP="$(neptune_lan_ip)"
LOCAL_INDEX="$(neptune_index_url_for_ip 127.0.0.1)"
LAN_INDEX="$(neptune_index_url_for_ip "${IP:-127.0.0.1}")"

echo
echo "Checking Neptune..."
HTTP_CODE=""
if ! HTTP_CODE="$(neptune_wait_for_http "$LOCAL_INDEX" 30)"; then
  echo "Repair did not pass the Neptune HTTP health check."
  echo "URL: $LOCAL_INDEX"
  echo "Last HTTP status: ${HTTP_CODE:-000}"
  exit 1
fi

echo
echo "=========================================="
echo " NEPTUNE IS READY"
echo "=========================================="
echo "HTTP:        $HTTP_CODE"
echo "Local index: $LOCAL_INDEX"
echo "LAN index:   $LAN_INDEX"
echo
echo "Opening Neptune..."
neptune_open_url "$LOCAL_INDEX"
