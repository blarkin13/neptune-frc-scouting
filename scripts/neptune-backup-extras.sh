#!/usr/bin/env bash
# Neptune backup extras: the server files the nightly backup does not cover.
#
# The nightly backup (/opt/neptune/bin/neptune-backup-github) holds the database,
# uploads/ and games/. Restoring a replacement server also needs everything below,
# and neptune_secure/spot_media holds every Tag Scouting photo and video.
#
#   neptune-backup-extras --check     report what exists and its size; writes nothing
#   neptune-backup-extras             build $OUT (root-only) for the nightly backup to include
#
# Wiring: in neptune-backup-github, run this script before the archive is encrypted and
# add $OUT to the encrypted archive as system.tar.gz. The archive is encrypted, so secrets
# in env files are protected the same way as the database dump. The one file that is
# NEVER included is /etc/neptune/backup.key: a backup cannot carry its own key.
# Keep that key off the server (password manager), as docs/PRODUCTION_BACKUP_AND_RESTORE.md says.
#
# Install: sudo install -o root -g root -m 0750 scripts/neptune-backup-extras.sh /opt/neptune/bin/neptune-backup-extras
set -Eeuo pipefail
umask 077

ROOT="${NEPTUNE_EXTRAS_ROOT:-}"            # test prefix only; empty on the server
OUT_DIR="$ROOT/var/lib/neptune-backup/extras"
OUT="$OUT_DIR/neptune-system-extras.tar.gz"
MODE="${1:-build}"

# Paths to keep, relative to /. Globs are expanded; missing paths are reported, not fatal.
PATHS=(
  var/www/neptune/neptune_secure          # app secrets/config, server-side PHP, spot_media (Tag photos/videos)
  etc/scout                               # db.env, offline-sync.env, neptune.env, field-mode.env
  etc/neptune                             # realtime secret, push VAPID keys, github-backup.env (backup.key excluded)
  etc/neptune-maintenance                 # Maintenance Console installer token
  etc/apache2                             # sites, conf-available/enabled, ports.conf, mods
  etc/letsencrypt                         # TLS certificates, keys and renewal config
  'etc/systemd/system/neptune*'           # backup timer, archive services, realtime
  'etc/cron.d/neptune*'                   # TBA scheduler and event-live cron entries
  'etc/sudoers.d/neptune*'                # narrow sudo rules for the web helpers
  'etc/php/*/apache2/conf.d'              # PHP upload limits and extensions
  'etc/php/*/cli/conf.d'
  opt/neptune                             # backup/restore tools, realtime server, push vendor
  usr/local/sbin/neptune-file-permissions # Maintenance Console permissions helper
)
EXCLUDES=(
  --exclude='etc/neptune/backup.key'
  --exclude='*.bak' --exclude='*.bak.*' --exclude='*~' --exclude='*.swp'
  --exclude='var/www/neptune/neptune_secure/sessions'
)

expand_paths() {
  local p m found
  for p in "${PATHS[@]}"; do
    found=0
    # shellcheck disable=SC2086
    for m in $ROOT/$p; do
      [[ -e "$m" ]] || continue
      found=1
      printf '%s\n' "${m#"$ROOT"/}"
    done
    [[ $found -eq 1 ]] || printf 'MISSING %s\n' "$p" >&2
  done
}

# TLS files referenced by Apache but stored outside /etc/letsencrypt (custom certificates).
tls_extras() {
  [[ -d "$ROOT/etc/apache2" ]] || return 0
  grep -rhoE '^\s*SSLCertificate(Key|Chain)?File\s+\S+' "$ROOT/etc/apache2" 2>/dev/null \
    | awk '{print $2}' | sed 's#^/##' | sort -u | while read -r f; do
      case "$f" in etc/letsencrypt/*) ;; *) [[ -e "$ROOT/$f" ]] && printf '%s\n' "$f";; esac
    done
}

LIST=$(mktemp); STAGE=$(mktemp -d)
trap 'rm -rf "$LIST" "$STAGE"' EXIT
{ expand_paths; tls_extras; } 2>"$STAGE/missing.txt" | sort -u > "$LIST"

if [[ "$MODE" == "--check" ]]; then
  echo "Covered by backup extras:"
  while read -r p; do
    printf '  %-48s %s\n' "/$p" "$(du -sh "$ROOT/$p" 2>/dev/null | cut -f1)"
  done < "$LIST"
  if [[ -s "$STAGE/missing.txt" ]]; then echo "Not present on this host:"; sed 's#^MISSING #  /#' "$STAGE/missing.txt"; fi
  media="$ROOT/var/www/neptune/neptune_secure/spot_media"
  [[ -d "$media" ]] && echo "Tag media files: $(find "$media" -type f | wc -l | tr -d ' ')"
  [[ -e "$ROOT/etc/neptune/backup.key" ]] && echo "backup.key is excluded on purpose. Confirm a copy exists off this server."
  exit 0
fi

[[ "$MODE" == "build" ]] || { echo "Usage: $0 [--check]" >&2; exit 2; }
[[ -n "$ROOT" || ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
[[ -s "$LIST" ]] || { echo 'Nothing to back up; no listed path exists.' >&2; exit 1; }

# Crontabs live in a spool that is awkward to restore by hand; save them as text.
mkdir -p "$STAGE/crontabs"
for user in root www-data; do
  crontab -l -u "$user" > "$STAGE/crontabs/$user" 2>/dev/null || rm -f "$STAGE/crontabs/$user"
done
{
  echo "created_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "host=$(hostname)"
  echo "paths:"; sed 's#^#  /#' "$LIST"
  echo "not_present:"; sed 's#^MISSING #  /#' "$STAGE/missing.txt"
} > "$STAGE/MANIFEST.txt"

mkdir -p "$OUT_DIR"; chmod 0700 "$OUT_DIR"
TMP="$OUT_DIR/.extras.partial"
trap 'rm -rf "$LIST" "$STAGE" "$TMP" "$TMP.tar"' EXIT
# Two steps (create, then append the manifest) so GNU and BSD tar behave the same.
(cd "${ROOT:-/}" && tar -cf "$TMP.tar" "${EXCLUDES[@]}" -T "$LIST")
(cd "$STAGE" && tar -rf "$TMP.tar" MANIFEST.txt crontabs)
if [ "$(tar -tf "$TMP.tar" | grep -c '^etc/neptune/backup\.key$')" -gt 0 ]; then
  echo 'Refusing: backup.key ended up in the archive.' >&2; exit 1
fi
gzip -c "$TMP.tar" > "$TMP"; rm -f "$TMP.tar"
chmod 0600 "$TMP"; mv -f "$TMP" "$OUT"
echo "Wrote $OUT ($(du -h "$OUT" | cut -f1)). Include it in the encrypted nightly archive as system.tar.gz."
