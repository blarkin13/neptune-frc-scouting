#!/usr/bin/env bash
set -euo pipefail

NEPTUNE_POINTER="$HOME/.neptune-portable-current"

neptune_root() {
  if [[ -f "$NEPTUNE_POINTER" ]]; then
    cat "$NEPTUNE_POINTER"
  else
    printf '%s\n' "$HOME/NeptuneEventServer"
  fi
}

neptune_load() {
  NEPTUNE_ROOT="$(neptune_root)"
  NEPTUNE_MANIFEST="$NEPTUNE_ROOT/.neptune-portable-install"
  if [[ ! -f "$NEPTUNE_MANIFEST" ]]; then
    echo "Neptune portable installation was not found."
    echo "Run Install Neptune.command first."
    exit 1
  fi
  # shellcheck disable=SC1090
  . "$NEPTUNE_MANIFEST"
}

neptune_brew() {
  if command -v brew >/dev/null 2>&1; then
    command -v brew
    return
  fi
  if [[ -x /opt/homebrew/bin/brew ]]; then
    printf '%s\n' /opt/homebrew/bin/brew
    return
  fi
  if [[ -x /usr/local/bin/brew ]]; then
    printf '%s\n' /usr/local/bin/brew
    return
  fi
  return 1
}

neptune_php_config_value() {
  local key="$1"
  php -r '$c=require $argv[1];$p=explode(".",$argv[2]);$v=$c;foreach($p as $k){$v=is_array($v)&&array_key_exists($k,$v)?$v[$k]:null;}if(is_scalar($v))echo $v;' \
    "$NEPTUNE_ROOT/neptune_secure/config.php" "$key"
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
  local ip=""
  for iface in en0 en1 en2 en3 en4 en5; do
    ip="$(ipconfig getifaddr "$iface" 2>/dev/null || true)"
    if [[ "$ip" =~ ^10\. || "$ip" =~ ^192\.168\. || "$ip" =~ ^172\.(1[6-9]|2[0-9]|3[01])\. ]]; then
      printf '%s\n' "$ip"
      return
    fi
  done
  ip="$(ifconfig 2>/dev/null | awk '/inet /{print $2}' | grep -Ev '^(127\.|169\.254\.)' | head -1 || true)"
  printf '%s\n' "$ip"
}

neptune_url_for_ip() {
  local ip="$1"
  local port="${HTTP_PORT:-8080}"
  if [[ "$port" == "80" ]]; then
    printf 'http://%s/\n' "$ip"
  else
    printf 'http://%s:%s/\n' "$ip" "$port"
  fi
}
