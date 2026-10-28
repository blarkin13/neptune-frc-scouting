#!/usr/bin/env bash
# Neptune cleanup S1 + S3: back up, then remove stale files.
#   S1: public_html/Neptune/Neptune_MultiOrg_DatabaseLab/   (whole folder)
#   S3: public_html/Neptune/admin/apply-user-guide-header.php
#       public_html/Neptune/admin/apply-user-guide-header-v2.php
#
# Usage (as root):
#   sudo bash neptune-cleanup-s1-s3.sh /var/www/neptune            # dry run
#   sudo bash neptune-cleanup-s1-s3.sh /var/www/neptune --apply    # back up, delete, verify
#   sudo bash neptune-cleanup-s1-s3.sh /var/www/neptune-qa --apply
set -euo pipefail

ROOT="${1:-}"
APPLY="${2:-}"

case "$ROOT" in
  /var/www/neptune)    HOST="neptunefrc.com" ;;
  /var/www/neptune-qa) HOST="qa.neptunefrc.com" ;;
  *) echo "First argument must be /var/www/neptune or /var/www/neptune-qa" >&2; exit 2 ;;
esac
[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo)." >&2; exit 2; }
[ -d "$ROOT/public_html/Neptune" ] || { echo "Not a Neptune root: $ROOT" >&2; exit 2; }

TARGETS=(
  "public_html/Neptune/Neptune_MultiOrg_DatabaseLab"
  "public_html/Neptune/admin/apply-user-guide-header.php"
  "public_html/Neptune/admin/apply-user-guide-header-v2.php"
)

echo "Root: $ROOT   (site: $HOST)"
present=()
for t in "${TARGETS[@]}"; do
  if [ -e "$ROOT/$t" ]; then
    printf '  FOUND  %s  (%s)\n' "$t" "$(du -sh "$ROOT/$t" | cut -f1)"
    present+=("$t")
  else
    printf '  absent %s\n' "$t"
  fi
done

if [ "${#present[@]}" -eq 0 ]; then echo "Nothing to remove."; exit 0; fi
if [ "$APPLY" != "--apply" ]; then
  echo
  echo "DRY RUN only. Re-run with --apply to back up and delete."
  exit 0
fi

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
NAME="$(basename "$ROOT")"
BK="/root/neptune-cleanup-backups"
mkdir -p "$BK"; chmod 700 "$BK"
ARCHIVE="$BK/${NAME}-s1-s3-${STAMP}.tar.gz"

# 1. Back up, preserving owners and modes, then prove the archive reads back.
tar -C "$ROOT" -czpf "$ARCHIVE" "${present[@]}"
chmod 600 "$ARCHIVE"
LISTING="$(tar -tzf "$ARCHIVE")" || { echo "Backup archive is unreadable. Nothing deleted." >&2; exit 1; }
for t in "${present[@]}"; do
  grep -qF -- "$t" <<<"$LISTING" || { echo "Backup is missing $t. Nothing deleted." >&2; exit 1; }
done
sha256sum "$ARCHIVE" | tee "$ARCHIVE.sha256"
echo "Backup verified: $ARCHIVE"

# 2. Delete.
for t in "${present[@]}"; do
  rm -rf --one-file-system -- "${ROOT:?}/$t"
  echo "  removed $t"
done

# 3. Verify gone on disk and over HTTP (through this machine, to the real vhost).
echo
fail=0
for t in "${present[@]}"; do
  [ ! -e "$ROOT/$t" ] || { echo "STILL PRESENT: $t"; fail=1; }
done
for p in "Neptune_MultiOrg_DatabaseLab/admin/index.php" \
         "Neptune_MultiOrg_DatabaseLab/dashboard/data.php" \
         "admin/apply-user-guide-header.php" \
         "admin/apply-user-guide-header-v2.php"; do
  code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 --resolve "$HOST:443:127.0.0.1" "https://$HOST/$p" || true)"
  printf '  HTTP %-4s https://%s/%s\n' "${code:-000}" "$HOST" "$p"
  [ "${code:-000}" = "404" ] || fail=1
done
echo
if [ "$fail" -eq 0 ]; then
  echo "DONE. All targets gone and returning 404."
else
  echo "CHECK ABOVE: something is still present or did not return 404 (a redirect to the login page is not a 404)."
fi
echo "To undo:  sudo tar -C $ROOT -xzpf $ARCHIVE"
