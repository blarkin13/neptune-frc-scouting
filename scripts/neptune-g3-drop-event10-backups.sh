#!/usr/bin/env bash
# G3: drop the two event-10 snapshot tables made on 2026-10-16 before the Prosper change.
#   backup_scouting_actions_event10_pre_prosper   (339 rows in the 2026-10-16 dump)
#   backup_scout_sessions_event10_pre_prosper     (23 rows)
#
# These tables are the ONLY copy of those rows: none of them (by id or uuid) exists in
# scouting_actions / scout_sessions any more. They are test scouting on 2026 Amarillo
# matches (event 10), recorded 2026-08-22 to 2026-09-20. Dropping them is safe only if
# that data is no longer wanted. --apply therefore exports both tables to a verified
# .sql.gz first, so the rows can still be restored by hand.
#
# Usage (as root, after the event is closed):
#   sudo bash neptune-g3-drop-event10-backups.sh            # dry run: counts and checks only
#   sudo bash neptune-g3-drop-event10-backups.sh --apply    # export, verify, drop
set -euo pipefail

APPLY="${1:-}"
DB_ENV=/etc/scout/db.env
TABLES=(backup_scouting_actions_event10_pre_prosper backup_scout_sessions_event10_pre_prosper)

[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo)." >&2; exit 2; }
[ -r "$DB_ENV" ] || { echo "Cannot read $DB_ENV" >&2; exit 2; }

# Read db.env without sourcing it, and hand the password to mysql through a root-only
# option file instead of the command line (where any user could see it in ps).
envval() {
  awk -v k="$1" '
    { line=$0; sub(/^[ \t]*export[ \t]+/,"",line) }
    line ~ ("^" k "[ \t]*=") { v=line; sub(/^[^=]*=[ \t]*/,"",v); val=v }
    END {
      if (val ~ /^".*"$/) { val=substr(val,2,length(val)-2); gsub(/\\"/,"\"",val) }
      else if (val ~ /^\047.*\047$/) { val=substr(val,2,length(val)-2) }
      print val
    }' "$DB_ENV"
}
DB_HOST="$(envval DB_HOST)"; DB_PORT="$(envval DB_PORT)"; DB_NAME="$(envval DB_NAME)"; DB_USER="$(envval DB_USER)"
DB_PASSWORD="$(envval DB_PASSWORD)"; [ -n "$DB_PASSWORD" ] || DB_PASSWORD="$(envval DB_PASS)"
[ -n "$DB_NAME" ] && [ -n "$DB_USER" ] || { echo "DB_NAME/DB_USER missing in $DB_ENV" >&2; exit 2; }
CNF="$(mktemp)"; chmod 600 "$CNF"; trap 'rm -f "$CNF"' EXIT
printf '[client]\nhost=%s\nuser=%s\npassword="%s"\n' "${DB_HOST:-127.0.0.1}" "$DB_USER" "${DB_PASSWORD//\"/\\\"}" > "$CNF"
[ -z "$DB_PORT" ] || printf 'port=%s\n' "$DB_PORT" >> "$CNF"
sql() { mysql --defaults-extra-file="$CNF" -N -B "$DB_NAME" -e "$1"; }

echo "Database: $DB_NAME"
present=()
for t in "${TABLES[@]}"; do
  if [ "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$t'")" = "1" ]; then
    rows="$(sql "SELECT COUNT(*) FROM \`$t\`")"
    printf '  FOUND  %-46s %s rows\n' "$t" "$rows"
    present+=("$t")
  else
    printf '  absent %s\n' "$t"
  fi
done
[ "${#present[@]}" -gt 0 ] || { echo "Nothing to drop."; exit 0; }

# How many of the snapshot rows still exist live (by uuid). 0 means this is the only copy.
a_live="$(sql "SELECT COUNT(*) FROM backup_scouting_actions_event10_pre_prosper b JOIN scouting_actions a ON a.uuid=b.uuid" 2>/dev/null || echo '?')"
s_live="$(sql "SELECT COUNT(*) FROM backup_scout_sessions_event10_pre_prosper b JOIN scout_sessions s ON s.uuid=b.uuid" 2>/dev/null || echo '?')"
echo "  Rows that still exist in the live tables: actions $a_live, sessions $s_live (0 = these tables are the only copy)."
echo "  Event 10: $(sql "SELECT CONCAT(name,' · ',COALESCE(event_status,'?')) FROM events WHERE id=10" 2>/dev/null || echo 'not found')"

if [ "$APPLY" != "--apply" ]; then
  echo
  echo "DRY RUN only. Re-run with --apply to export both tables to /root/neptune-cleanup-backups and drop them."
  exit 0
fi

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BK="/root/neptune-cleanup-backups"; mkdir -p "$BK"; chmod 700 "$BK"
OUT="$BK/g3-event10-pre-prosper-${STAMP}.sql.gz"

# 1. Export and prove the export holds every row before dropping anything.
# The snapshot tables are never written to, so no transaction or lock is needed
# (--single-transaction would also require the RELOAD privilege on MySQL 8.0.32+).
trap 'rm -f "$CNF"; [ -n "${DONE_EXPORT:-}" ] || rm -f "$OUT"' EXIT
# MySQL adds a GTID_PURGED statement that makes the file impossible to load back
# into the same server; MariaDB's mysqldump has no such option.
GTID=""; if [ "$(mysqldump --help 2>/dev/null | grep -c -- '--set-gtid-purged')" -gt 0 ]; then GTID="--set-gtid-purged=OFF"; fi
mysqldump --defaults-extra-file="$CNF" --skip-lock-tables --skip-extended-insert --no-tablespaces ${GTID:+"$GTID"} "$DB_NAME" "${present[@]}" | gzip -9 > "$OUT"
chmod 600 "$OUT"
for t in "${present[@]}"; do
  want="$(sql "SELECT COUNT(*) FROM \`$t\`")"
  got="$(gzip -dc "$OUT" | grep -c "^INSERT INTO \`$t\` " || true)"
  [ "$want" = "$got" ] || { echo "Export has $got of $want rows for $t. Nothing dropped." >&2; exit 1; }
  echo "  exported $t: $got rows"
done
sha256sum "$OUT" | tee "$OUT.sha256"
DONE_EXPORT=1

# 2. Drop.
for t in "${present[@]}"; do
  sql "DROP TABLE \`$t\`"
  echo "  dropped $t"
done
echo
echo "DONE. To restore:  gzip -dc $OUT | sudo mysql $DB_NAME"
