#!/usr/bin/env bash
# Neptune cleanup: old copies (*.bak and similar) in neptune_secure/.
# These hold earlier versions of server-side PHP and config, sometimes with old
# credentials, and are not used by Neptune. spot_media/ is never touched.
#
# Usage (as root):
#   sudo bash neptune-cleanup-secure-bak.sh /var/www/neptune            # dry run: list only
#   sudo bash neptune-cleanup-secure-bak.sh /var/www/neptune --apply    # back up, delete, verify
#   sudo bash neptune-cleanup-secure-bak.sh /var/www/neptune-qa --apply
set -euo pipefail

ROOT="${1:-}"
APPLY="${2:-}"

case "$ROOT" in
  /var/www/neptune|/var/www/neptune-qa) ;;
  *) echo "First argument must be /var/www/neptune or /var/www/neptune-qa" >&2; exit 2 ;;
esac
[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo)." >&2; exit 2; }
SECURE="$ROOT/neptune_secure"
[ -d "$SECURE" ] || { echo "Not found: $SECURE" >&2; exit 2; }

# Old-copy patterns: name.bak, name.bak.20261001, name.php.bak-v2, name.orig, name~, name.php.before-*
present=()
while IFS= read -r -d '' f; do
  present+=("${f#"$ROOT"/}")
done < <(find "$SECURE" -path "$SECURE/spot_media" -prune -o -type f \( \
    -name '*.bak' -o -name '*.bak.*' -o -name '*.bak-*' -o -name '*.orig' -o -name '*~' -o -name '*.before-*' \
  \) -print0 | sort -z)

echo "Root: $ROOT"
if [ "${#present[@]}" -eq 0 ]; then echo "No old copies found in neptune_secure/."; exit 0; fi
for t in "${present[@]}"; do
  live="${t%.bak}"; live="${live%%.bak.*}"; live="${live%%.bak-*}"; live="${live%.orig}"; live="${live%\~}"; live="${live%%.before-*}"
  note="no live file of that name"
  [ -f "$ROOT/$live" ] && note="live copy: $live"
  printf '  FOUND  %s  (%s, %s)\n' "$t" "$(du -h "$ROOT/$t" | cut -f1)" "$note"
done

if [ "$APPLY" != "--apply" ]; then
  echo
  echo "DRY RUN only. Re-run with --apply to back up and delete these ${#present[@]} file(s)."
  exit 0
fi

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
NAME="$(basename "$ROOT")"
BK="/root/neptune-cleanup-backups"
mkdir -p "$BK"; chmod 700 "$BK"
ARCHIVE="$BK/${NAME}-secure-bak-${STAMP}.tar.gz"

# 1. Back up, preserving owners and modes, then prove the archive reads back.
tar -C "$ROOT" -czpf "$ARCHIVE" "${present[@]}"
chmod 600 "$ARCHIVE"
LISTING="$(tar -tzf "$ARCHIVE")" || { echo "Backup archive is unreadable. Nothing deleted." >&2; exit 1; }
for t in "${present[@]}"; do
  grep -qxF -- "$t" <<<"$LISTING" || { echo "Backup is missing $t. Nothing deleted." >&2; exit 1; }
done
sha256sum "$ARCHIVE" | tee "$ARCHIVE.sha256"
echo "Backup verified: $ARCHIVE"

# 2. Delete the files only (never directories).
for t in "${present[@]}"; do
  rm -f -- "${ROOT:?}/$t"
  echo "  removed $t"
done

# 3. Verify.
fail=0
for t in "${present[@]}"; do
  [ ! -e "$ROOT/$t" ] || { echo "STILL PRESENT: $t"; fail=1; }
done
echo
if [ "$fail" -eq 0 ]; then echo "DONE. ${#present[@]} old copies removed."; else echo "CHECK ABOVE: some files are still present."; fi
echo "To undo:  sudo tar -C $ROOT -xzpf $ARCHIVE"
echo "The backup holds old config and may contain credentials; delete it once you are sure:  sudo rm $ARCHIVE $ARCHIVE.sha256"
