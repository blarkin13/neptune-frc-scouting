#!/usr/bin/env bash
set -euo pipefail

echo "=== Neptune Field Server Check ==="
echo "Host: $(hostname)"
echo "IP: $(hostname -I 2>/dev/null || true)"
echo
systemctl is-active --quiet apache2 && echo "Apache: OK" || echo "Apache: DOWN"
systemctl is-active --quiet mariadb && echo "MariaDB: OK" || echo "MariaDB: DOWN"
systemctl is-active --quiet avahi-daemon && echo "mDNS: OK" || echo "mDNS: DOWN"
echo
printf "Neptune status: "
curl -fsS http://127.0.0.1/api/offline-status.php || echo "FAILED"
echo
