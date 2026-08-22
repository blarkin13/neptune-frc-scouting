-- Neptune live scouting / action management upgrade
-- Run this ONCE on an existing Neptune database before uploading the matching PHP patch.
-- MariaDB 10.6+ / MySQL 8 compatible.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE scouting_actions
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER legacy_id,
  ADD COLUMN IF NOT EXISTS deleted_by BIGINT UNSIGNED NULL AFTER deleted_at,
  ADD COLUMN IF NOT EXISTS deletion_reason VARCHAR(32) NULL AFTER deleted_by;

-- If a match was already re-scouted using an earlier Neptune build, older runs
-- were preserved as active data. Void those superseded runs now so only the
-- current run contributes to analytics and scouting totals.
UPDATE scouting_actions sa
JOIN matches m ON m.id=sa.match_id AND m.organization_id=sa.organization_id
SET sa.deleted_at=COALESCE(sa.deleted_at,UTC_TIMESTAMP()),
    sa.deletion_reason=COALESCE(sa.deletion_reason,'match_rescout')
WHERE sa.match_run_number < m.run_number
  AND sa.deleted_at IS NULL;

UPDATE scout_sessions ss
JOIN matches m ON m.id=ss.match_id AND m.organization_id=ss.organization_id
SET ss.status='closed'
WHERE ss.match_run_number < m.run_number
  AND ss.status<>'closed';
