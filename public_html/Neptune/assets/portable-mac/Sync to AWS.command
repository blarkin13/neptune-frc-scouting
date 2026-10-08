#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
# shellcheck disable=SC1091
. "./portable-common.sh"
neptune_load

URL="$(neptune_url_for_ip localhost)admin/portable.php#sync"
echo "Opening Neptune Sync to AWS:"
echo "  $URL"
open "$URL"
