#!/usr/bin/env bash
set -euo pipefail

NEPTUNE_USER="${SUDO_USER:-${USER:-$(id -un)}}"
NEPTUNE_USER_HOME="$(eval echo "~${NEPTUNE_USER}")"
NEPTUNE_POINTER="${NEPTUNE_USER_HOME}/.neptune-portable-current"

neptune_load() {
  if [[ ! -f "$NEPTUNE_POINTER" ]]; then
    echo "Neptune portable installation was not found."
    exit 1
  fi
  NEPTUNE_STATE="$(cat "$NEPTUNE_POINTER")"
  NEPTUNE_MANIFEST="$NEPTUNE_STATE/.neptune-portable-install"
  [[ -f "$NEPTUNE_MANIFEST" ]] || { echo "Neptune install manifest is missing."; exit 1; }
  # shellcheck disable=SC1090
  . "$NEPTUNE_MANIFEST"
}

neptune_php_config_value() {
  local key="$1"
  php -r '$c=require $argv[1];$v=$c;foreach(explode(".",$argv[2]) as $k){$v=is_array($v)&&array_key_exists($k,$v)?$v[$k]:null;}if(is_scalar($v))echo $v;' \
    "$SECURE_ROOT/config.php" "$key"
}

neptune_client_cnf() {
  local out="$1"
  local host port user pass
  host="$(neptune_php_config_value db.host)"
  port="$(neptune_php_config_value db.port)"
  user="$(neptune_php_config_value db.user)"
  pass="$(neptune_php_config_value db.pass)"
  umask 077
  cat > "$out" <<EOF
[client]
host=$host
port=${port:-3306}
user=$user
password=$pass
protocol=tcp
EOF
}

neptune_lan_ip() {
  if [[ "$(uname -s)" == "Darwin" ]]; then
    local ip=""
    for iface in en0 en1 en2 en3 en4 en5 en6 en7; do
      ip="$(ipconfig getifaddr "$iface" 2>/dev/null || true)"
      if [[ "$ip" =~ ^10\. || "$ip" =~ ^192\.168\. || "$ip" =~ ^172\.(1[6-9]|2[0-9]|3[01])\. ]]; then
        printf '%s\n' "$ip"
        return
      fi
    done
    ifconfig 2>/dev/null | awk '/inet /{print $2}' | grep -Ev '^(127\.|169\.254\.)' | head -1 || true
  else
    hostname -I 2>/dev/null | tr ' ' '\n' | grep -Ev '^(127\.|169\.254\.)' | head -1 || true
  fi
}

neptune_base_url_for_ip() {
  local ip="$1"
  local port="${HTTP_PORT:-80}"
  if [[ "$port" == "80" ]]; then
    printf 'http://%s/Neptune/\n' "$ip"
  else
    printf 'http://%s:%s/Neptune/\n' "$ip" "$port"
  fi
}

# Backward-compatible alias used by QR generation.
neptune_url_for_ip() {
  neptune_base_url_for_ip "$1"
}

neptune_index_url_for_ip() {
  printf '%sindex.php\n' "$(neptune_base_url_for_ip "$1")"
}

neptune_wait_for_http() {
  local url="$1"
  local tries="${2:-30}"
  local code=""
  local body=""
  local tmp=""
  tmp="$(mktemp)"
  trap 'rm -f "$tmp"' RETURN 2>/dev/null || true

  for ((i=1; i<=tries; i++)); do
    : > "$tmp"
    code="$(curl -sS -o "$tmp" -w '%{http_code}' --connect-timeout 2 --max-time 5 "$url" 2>/dev/null || true)"
    body="$(head -c 262144 "$tmp" 2>/dev/null || true)"

    if [[ "$code" =~ ^[23][0-9][0-9]$ ]] \
       && ! printf '%s' "$body" | grep -Eqi 'Fatal error|Uncaught (Error|Exception)|Failed opening required|Permission denied in .*\.php|Parse error'; then
      rm -f "$tmp"
      printf '%s\n' "$code"
      return 0
    fi
    sleep 1
  done

  rm -f "$tmp"
  printf '%s\n' "${code:-000}"
  return 1
}

neptune_open_url() {
  local url="$1"
  if command -v open >/dev/null 2>&1; then
    open "$url"
  elif command -v xdg-open >/dev/null 2>&1; then
    xdg-open "$url" >/dev/null 2>&1 || true
  else
    echo "$url"
  fi
}
