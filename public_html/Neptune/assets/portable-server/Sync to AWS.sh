#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
. "./portable-common.sh"
neptune_load
URL="$(neptune_url_for_ip localhost)admin/portable.php#sync"
echo "Opening organization sync:"
echo "$URL"
neptune_open_url "$URL"
