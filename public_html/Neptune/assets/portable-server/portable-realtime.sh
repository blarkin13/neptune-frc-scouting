#!/usr/bin/env bash
# Local Neptune WebSocket relay manager. Source this from installer/launcher scripts.
# No internet, external account or AWS sync action is involved.

neptune_realtime_install() {
  local supplied_relay="$1"
  [[ -f "$supplied_relay" ]] || { echo "Missing bundled portable realtime relay: $supplied_relay" >&2; return 1; }
  command -v python3 >/dev/null 2>&1 || { echo 'Python 3 required for portable realtime.' >&2; return 1; }
  mkdir -p "$STATE_ROOT/realtime"
  cp "$supplied_relay" "$STATE_ROOT/realtime/robot-recall-realtime.py"
  chmod 644 "$STATE_ROOT/realtime/robot-recall-realtime.py"
  python3 -m py_compile "$STATE_ROOT/realtime/robot-recall-realtime.py"
  # Generate per-install secret locally; never import the production secret.
  if [[ ! -s "$SECURE_ROOT/realtime.secret" ]]; then
    (umask 077; openssl rand -hex 32 > "$SECURE_ROOT/realtime.secret")
  fi
  if [[ "$(uname -s)" == Darwin ]]; then
    chmod 600 "$SECURE_ROOT/realtime.secret"
    chmod -N "$SECURE_ROOT/realtime.secret" 2>/dev/null || true
    chmod +a "${APACHE_RUN_USER} allow read" "$SECURE_ROOT/realtime.secret"
  else
    chown root:"${APACHE_RUN_GROUP}" "$SECURE_ROOT/realtime.secret"
    chmod 640 "$SECURE_ROOT/realtime.secret"
    # The Linux relay runs as the unprivileged Apache user.
    cat > /etc/systemd/system/neptune-portable-realtime.service <<EOF
[Unit]
Description=Neptune Portable Local WebSocket Relay
After=network.target

[Service]
Type=simple
User=${APACHE_RUN_USER}
Group=${APACHE_RUN_GROUP}
ExecStart=/usr/bin/python3 ${STATE_ROOT}/realtime/robot-recall-realtime.py
Environment=NEPTUNE_REALTIME_SECRET_FILE=${SECURE_ROOT}/realtime.secret
Environment=NEPTUNE_RR_WS_HOST=127.0.0.1
Environment=NEPTUNE_RR_WS_PORT=8765
Restart=on-failure
RestartSec=2
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable neptune-portable-realtime.service >/dev/null
  fi
}

neptune_realtime_start() {
  local relay="$STATE_ROOT/realtime/robot-recall-realtime.py"
  [[ -f "$relay" && -s "$SECURE_ROOT/realtime.secret" ]] || {
    echo 'Neptune realtime is not installed or its secret is missing.' >&2; return 1;
  }
  if [[ "$(uname -s)" != Darwin ]]; then
    systemctl start neptune-portable-realtime.service
    systemctl is-active --quiet neptune-portable-realtime.service
    return
  fi
  local pidfile="$STATE_ROOT/realtime/relay.pid" pid="" cmd=""
  if [[ -f "$pidfile" ]]; then
    pid="$(cat "$pidfile" 2>/dev/null || true)"
    if [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null; then
      cmd="$(ps -p "$pid" -o command= 2>/dev/null || true)"
      if [[ "$cmd" == *"$relay"* ]]; then return 0; fi
    fi
    rm -f "$pidfile"
  fi
  # Do not take over another service's port; fail visibly.
  if python3 -c 'import socket; s=socket.socket(); r=s.connect_ex(("127.0.0.1",8765)); s.close(); raise SystemExit(0 if r == 0 else 1)' ; then
    echo 'Port 8765 is already used by another process; portable realtime cannot start.' >&2
    return 1
  fi
  (umask 077; : > "$STATE_ROOT/realtime/relay.log")
  NEPTUNE_REALTIME_SECRET_FILE="$SECURE_ROOT/realtime.secret" \
  NEPTUNE_RR_WS_HOST=127.0.0.1 NEPTUNE_RR_WS_PORT=8765 \
    nohup python3 "$relay" >> "$STATE_ROOT/realtime/relay.log" 2>&1 < /dev/null &
  pid=$!
  printf '%s\n' "$pid" > "$pidfile"
  for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
    if ! kill -0 "$pid" 2>/dev/null; then
      echo 'Portable realtime relay exited unexpectedly:' >&2
      tail -12 "$STATE_ROOT/realtime/relay.log" >&2 || true
      return 1
    fi
    if grep -q 'private_auth=enabled' "$STATE_ROOT/realtime/relay.log"; then return 0; fi
    sleep 0.25
  done
  echo 'Portable realtime relay did not become ready.' >&2
  tail -12 "$STATE_ROOT/realtime/relay.log" >&2 || true
  return 1
}

neptune_realtime_stop() {
  if [[ "$(uname -s)" != Darwin ]]; then
    systemctl stop neptune-portable-realtime.service 2>/dev/null || true
    return
  fi
  local pidfile="$STATE_ROOT/realtime/relay.pid" pid="" cmd=""
  [[ -f "$pidfile" ]] || return 0
  pid="$(cat "$pidfile" 2>/dev/null || true)"
  if [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null; then
    cmd="$(ps -p "$pid" -o command= 2>/dev/null || true)"
    if [[ "$cmd" == *"$STATE_ROOT/realtime/robot-recall-realtime.py"* ]]; then
      kill "$pid" 2>/dev/null || true
    fi
  fi
  rm -f "$pidfile"
}
