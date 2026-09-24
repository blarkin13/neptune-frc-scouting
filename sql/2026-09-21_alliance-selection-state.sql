-- Neptune Alliance Selection - lean shared-state storage
-- One row per organization + event. TBA remains the source for rankings, W-L-T, and alliance history.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS alliance_selection_state (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  state_json LONGTEXT NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alliance_selection_org_event (organization_id,event_id),
  KEY idx_alliance_selection_event (event_id),
  CONSTRAINT fk_alliance_selection_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_alliance_selection_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_alliance_selection_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The previous Alliance Selection prototype used several normalized tables.
-- They are intentionally not dropped here so this migration is non-destructive.
-- Once this version is verified, those prototype tables can be removed separately.
