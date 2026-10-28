#!/usr/bin/env bash
# Neptune Release Center archive: ONE-FILE server installer. Run once as root, BEFORE
# installing the Maintenance Console ZIP. It installs the archive script, its systemd unit,
# a narrow sudo rule and the export directory. It does not modify any Neptune web files.
set -Eeuo pipefail
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run this installer as root.' >&2;exit 1; }
[[ -d /opt/neptune-release-center ]] || { echo 'Missing /opt/neptune-release-center' >&2;exit 1; }
[[ -d /opt/neptune/bin ]] || { echo 'Missing /opt/neptune/bin (existing Neptune tools directory).' >&2;exit 1; }
[[ -f /etc/systemd/system/neptune-www-archive.service ]] || { echo 'Existing /var/www archive service not found; install that first.' >&2;exit 1; }
getent group www-data >/dev/null || { echo 'Group www-data not found.' >&2;exit 1; }
SCRIPT=/opt/neptune/bin/neptune-release-center-tar
UNIT=/etc/systemd/system/neptune-release-center-archive.service
W=$(mktemp -d); trap 'rm -rf "$W"' EXIT
cat > "$W/tar" <<'NEPTUNE_RC_TAR_EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
umask 027
SRC_PARENT=/opt
SRC_NAME=neptune-release-center
DIR=/var/lib/neptune-release-center-export
STATUS="$DIR/status.json"
MAX_BYTES=$((2*1024*1024*1024))
mkdir -p "$DIR"
chown root:www-data "$DIR"
chmod 0750 "$DIR"
set_status() {
  STATUS_VALUE="$1" STATUS_MESSAGE="${2:-}" STATUS_FILE="$STATUS" STATUS_ARCHIVE="${3:-}" python3 - <<'PY'
import json,os,tempfile,datetime
p=os.environ['STATUS_FILE']
d={'status':os.environ['STATUS_VALUE'],'message':os.environ['STATUS_MESSAGE'][:300], 'archive':os.environ['STATUS_ARCHIVE'],'updated_at':datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')}
fd,tmp=tempfile.mkstemp(dir=os.path.dirname(p),prefix='.status-')
with os.fdopen(fd,'w') as f: json.dump(d,f)
os.chown(tmp,0,__import__('grp').getgrnam('www-data').gr_gid);os.chmod(tmp,0o640);os.replace(tmp,p)
PY
}
TMP=""
on_fail() { local rc=$?; [[ -z "$TMP" ]] || rm -f "$TMP"; set_status failed "Archive generation failed (exit $rc); inspect journalctl -u neptune-release-center-archive.service"; exit "$rc"; }
trap on_fail ERR
set_status running 'Archiving /opt/neptune-release-center (excluding secrets)'
[[ -d "$SRC_PARENT/$SRC_NAME" ]] || { set_status failed 'Missing /opt/neptune-release-center'; exit 1; }
size=$(du -sb --exclude="$DIR" "$SRC_PARENT/$SRC_NAME" | cut -f1)
if (( size > MAX_BYTES )); then
  set_status failed "Source is $((size/1024/1024)) MiB; limit is $((MAX_BYTES/1024/1024)) MiB. Archive not created."
  exit 1
fi
NAME="Neptune_release_center_$(date -u +%Y%m%d-%H%M%SZ).tar.gz"
TMP="$DIR/.$NAME.partial"
# Do not dereference symlinks. Exclude credentials, tokens, private keys, environment
# files and git remote settings (which can embed access tokens). Release packages and
# scripts are kept.
rc=0
tar -C "$SRC_PARENT" -czf "$TMP" \
  --exclude="$SRC_NAME/.git/config" \
  --exclude='*/.env' --exclude='*/.env.*' \
  --exclude='*/db.env' --exclude='*/*.env' --exclude='*/backup.key' \
  --exclude='*/config.php' --exclude='*/config.local.php' \
  --exclude='*/.git-credentials' --exclude='*/.netrc' --exclude='*/.npmrc' --exclude='*/.pypirc' \
  --exclude='*/credentials*' --exclude='*/secrets*' \
  --exclude='*/*.token' --exclude='*/*.pem' --exclude='*/*.key' --exclude='*/*.p12' --exclude='*/*.pfx' \
  --exclude='*/id_rsa*' --exclude='*/id_ed25519*' --exclude='*/id_ecdsa*' \
  "$SRC_NAME" || rc=$?
# tar exits 1 when a file changed while being read; the archive is still usable.
(( rc <= 1 )) || exit "$rc"
# Ensure gzip and tar both complete; no partial archives are published.
gzip -t "$TMP"
tar -tzf "$TMP" >/dev/null
chown root:www-data "$TMP";chmod 0640 "$TMP"
mv "$TMP" "$DIR/$NAME"
TMP=""
# Keep up to three completed archives for manual download.
mapfile -t old < <(find "$DIR" -maxdepth 1 -type f -name 'Neptune_release_center_*.tar.gz' -printf '%f\n' | sort -r | tail -n +4)
for f in "${old[@]}"; do rm -f -- "$DIR/$f"; done
set_status success 'Archive ready to download' "$NAME"
NEPTUNE_RC_TAR_EOF
cat > "$W/unit" <<'NEPTUNE_RC_UNIT_EOF'
[Unit]
Description=Create on-demand Neptune Release Center archive
After=local-fs.target
[Service]
Type=oneshot
User=root
Group=root
ExecStart=/opt/neptune/bin/neptune-release-center-tar
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=7
PrivateTmp=true
ProtectHome=true
NEPTUNE_RC_UNIT_EOF
bash -n "$W/tar"
install -o root -g root -m 0750 "$W/tar" "$SCRIPT"
install -o root -g root -m 0644 "$W/unit" "$UNIT"
systemctl daemon-reload
SUDOERS=/etc/sudoers.d/neptune-release-center-archive
printf 'www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start --no-block neptune-release-center-archive.service\n' > "$W/sudoers"
chmod 0440 "$W/sudoers"
visudo -cf "$W/sudoers"
install -o root -g root -m 0440 "$W/sudoers" "$SUDOERS"
visudo -cf "$SUDOERS"
mkdir -p /var/lib/neptune-release-center-export
chown root:www-data /var/lib/neptune-release-center-export
chmod 0750 /var/lib/neptune-release-center-export
echo 'Installed Release Center archive service. Next: install the Maintenance Console ZIP.'
echo "To undo: rm $SCRIPT $UNIT $SUDOERS && systemctl daemon-reload"
