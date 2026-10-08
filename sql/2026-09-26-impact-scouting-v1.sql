-- Neptune Impact Scouting V1
-- Production patch note: Impact Scouting also runs this as CREATE TABLE IF NOT EXISTS
-- on first page load because the current Maintenance Console copies SQL files but does
-- not execute migrations automatically.

CREATE TABLE IF NOT EXISTS impact_scouting_observations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  alliance ENUM('Red','Blue') NOT NULL,
  station TINYINT UNSIGNED NULL,
  scoring_contribution TINYINT UNSIGNED NOT NULL DEFAULT 0,
  overall_impact DECIMAL(3,1) NOT NULL DEFAULT 5.0,
  auton_impact TINYINT UNSIGNED NOT NULL DEFAULT 5,
  defense_impact TINYINT UNSIGNED NOT NULL DEFAULT 5,
  tags_json LONGTEXT NOT NULL,
  note VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_impact_observer_robot (organization_id, match_id, user_id, frc_team_number),
  KEY idx_impact_robot (organization_id, event_id, frc_team_number),
  KEY idx_impact_match (organization_id, match_id),
  KEY idx_impact_user (organization_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
