#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CLOUD="${1:-}"
ORG="${2:-1}"
EVENT="${3:-}"
if [[ -z "$CLOUD" ]]; then
  echo "Usage: sudo $0 https://YOUR-NEPTUNE-HOST [organization_id] [event_id]"
  exit 1
fi
if [[ -n "$EVENT" ]]; then
  exec php "$SCRIPT_DIR/sync-to-cloud.php" "$CLOUD" "$ORG" "$EVENT"
else
  exec php "$SCRIPT_DIR/sync-to-cloud.php" "$CLOUD" "$ORG"
fi
