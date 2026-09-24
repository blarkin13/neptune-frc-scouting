-- Neptune Offline / Field Server sync support
-- Current Neptune already has UUIDs on scout_sessions and scouting_actions.
-- This table records field-to-cloud sync attempts locally for operator visibility.

CREATE TABLE IF NOT EXISTS offline_sync_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  destination_url VARCHAR(255) NOT NULL,
  started_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  status ENUM('running','complete','failed') NOT NULL DEFAULT 'running',
  matches_sent INT UNSIGNED NOT NULL DEFAULT 0,
  sessions_sent INT UNSIGNED NOT NULL DEFAULT 0,
  actions_sent INT UNSIGNED NOT NULL DEFAULT 0,
  response_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_offsync_org_time (organization_id, created_at),
  CONSTRAINT fk_offsync_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
