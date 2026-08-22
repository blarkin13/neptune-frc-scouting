-- Neptune v3: Current Event + Pit Scouting
-- Run ONCE on an existing Neptune database after neptune_v2_upgrade.sql.
-- MariaDB 10.6+ / MySQL 8 compatible.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE games
  ADD COLUMN IF NOT EXISTS pit_config_json LONGTEXT NULL AFTER config_json;

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS last_tba_sync_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS event_status ENUM('planned','pit_open','schedule_ready','running','complete') NOT NULL DEFAULT 'planned',
  ADD COLUMN IF NOT EXISTS is_current TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS roster_synced_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS schedule_synced_at DATETIME NULL;

ALTER TABLE pit_scouting
  ADD COLUMN IF NOT EXISTS status ENUM('in_progress','complete') NOT NULL DEFAULT 'in_progress' AFTER notes,
  ADD COLUMN IF NOT EXISTS started_at DATETIME NULL AFTER status,
  ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL AFTER started_at,
  ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER completed_at;

CREATE TABLE IF NOT EXISTS pit_scouting_photos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pit_scouting_id BIGINT UNSIGNED NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  category ENUM('front','back','left','right','mechanism','other') NOT NULL DEFAULT 'other',
  file_path VARCHAR(255) NOT NULL,
  caption VARCHAR(190) NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pit_photo_scouting (pit_scouting_id),
  KEY idx_pit_photo_org (organization_id),
  CONSTRAINT fk_pit_photo_scouting FOREIGN KEY (pit_scouting_id) REFERENCES pit_scouting(id) ON DELETE CASCADE,
  CONSTRAINT fk_pit_photo_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_pit_photo_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing pit rows represent completed historical forms unless explicitly changed later.
UPDATE pit_scouting
SET status='complete',
    started_at=COALESCE(started_at, updated_at),
    completed_at=COALESCE(completed_at, updated_at)
WHERE status='in_progress' AND data_json IS NOT NULL AND LENGTH(TRIM(data_json)) > 2;

-- Existing events with a match schedule are immediately schedule-ready.
UPDATE events e
SET e.event_status='schedule_ready',
    e.schedule_synced_at=COALESCE(e.schedule_synced_at,e.last_tba_sync_at)
WHERE EXISTS (SELECT 1 FROM matches m WHERE m.event_id=e.id)
  AND e.event_status='planned';

-- Existing events with a roster but no schedule can be used for pit scouting now.
UPDATE events e
SET e.event_status='pit_open',
    e.roster_synced_at=COALESCE(e.roster_synced_at,e.last_tba_sync_at)
WHERE EXISTS (SELECT 1 FROM event_teams et WHERE et.event_id=e.id)
  AND NOT EXISTS (SELECT 1 FROM matches m WHERE m.event_id=e.id)
  AND e.event_status='planned';

-- Existing installs can choose the current event from Command > Event Setup.
