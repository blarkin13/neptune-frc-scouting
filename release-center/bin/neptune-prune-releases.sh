#!/bin/sh
# Neptune release cleanup; --dry-run previews without removing files.
exec /usr/bin/python3 /opt/neptune-release-center/bin/release_prune.py "$@"
