#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
if [[ "$(uname -s)" != "Darwin" && "$EUID" -ne 0 ]]; then exec sudo -E bash "$0" "$@"; fi
. "./portable-common.sh"
neptune_load

if [[ "$OS" == "Darwin" ]]; then
  if ! command -v brew >/dev/null 2>&1; then
    if [[ -x /opt/homebrew/bin/brew ]]; then eval "$(/opt/homebrew/bin/brew shellenv)"
    elif [[ -x /usr/local/bin/brew ]]; then eval "$(/usr/local/bin/brew shellenv)"
    fi
  fi
  BREW="$(command -v brew)"

  if [[ "${DB_MODE:-}" == "private" ]]; then
    if [[ ! -S "${DB_SOCKET:-}" ]] || ! "$DBCLI" --protocol=socket --socket="$DB_SOCKET" -uroot -e 'SELECT 1' >/dev/null 2>&1; then
      rm -f "$DB_SOCKET" "$DB_PID"
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
      for _ in {1..30}; do
        [[ -S "$DB_SOCKET" ]] && "$DBCLI" --protocol=socket --socket="$DB_SOCKET" -uroot -e 'SELECT 1' >/dev/null 2>&1 && break
        sleep 1
      done
    fi
  fi

  echo "Starting Apache..."
  "$BREW" services restart httpd
else
  systemctl restart "$APACHE_SERVICE"
  [[ "$DB_SERVICE" == "external" ]] || systemctl start "$DB_SERVICE" 2>/dev/null || true
fi

IP="$(neptune_lan_ip)"
LOCAL_INDEX="$(neptune_index_url_for_ip 127.0.0.1)"
LAN_INDEX="$(neptune_index_url_for_ip "${IP:-127.0.0.1}")"
LAN_BASE="$(neptune_base_url_for_ip "${IP:-127.0.0.1}")"

HTTP_CODE=""
if ! HTTP_CODE="$(neptune_wait_for_http "$LOCAL_INDEX" 30)"; then
  echo "Neptune did not become reachable through Apache."
  echo "URL: $LOCAL_INDEX"
  echo "HTTP: ${HTTP_CODE:-000}"
  exit 1
fi

mkdir -p "$APP_ROOT/assets/portable"
if command -v qrencode >/dev/null 2>&1 && [[ -n "$IP" ]]; then
  qrencode -o "$APP_ROOT/assets/portable/neptune-lan-qr.png" -s 8 -m 2 "$LAN_INDEX"
  qrencode -o "$APP_ROOT/assets/portable/neptune-ntx-qr.png" -s 8 -m 2 "${LAN_BASE}help/ntx-start.php"
fi

echo
echo "=========================================="
echo " NEPTUNE IS RUNNING"
echo "=========================================="
echo "Local index: $LOCAL_INDEX"
echo "LAN index:   $LAN_INDEX"
echo "HTTP status: $HTTP_CODE"
echo
echo "Opening Neptune..."
sleep 1
neptune_open_url "$LOCAL_INDEX"
