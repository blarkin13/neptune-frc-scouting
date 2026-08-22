-- Neptune FRC Scouting Platform
-- MariaDB 10.6+ / MySQL 8 compatible
-- Create the hosting database first if your host requires it (for example YOURPREFIX_Neptune),
-- select it, then import this file.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE organizations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(100) NOT NULL UNIQUE,
  logo_path VARCHAR(255) NULL,
  accent_color VARCHAR(16) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE teams (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  nickname VARCHAR(160) NULL,
  display_name VARCHAR(190) NULL,
  tba_key VARCHAR(40) NULL,
  logo_path VARCHAR(255) NULL,
  accent_color VARCHAR(16) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_org_team (organization_id, frc_team_number),
  KEY idx_team_number (frc_team_number),
  CONSTRAINT fk_teams_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  username VARCHAR(80) NOT NULL,
  email VARCHAR(190) NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  role ENUM('owner','admin','strategy','scout') NOT NULL DEFAULT 'scout',
  active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  password_changed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_org_username (organization_id, username),
  UNIQUE KEY uq_org_email (organization_id, email),
  CONSTRAINT fk_users_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_teams (
  user_id BIGINT UNSIGNED NOT NULL,
  team_id BIGINT UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, team_id),
  CONSTRAINT fk_ut_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ut_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE games (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  season_year SMALLINT UNSIGNED NOT NULL,
  slug VARCHAR(120) NOT NULL,
  json_filename VARCHAR(255) NOT NULL,
  config_json LONGTEXT NOT NULL,
  pit_config_json LONGTEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_year_slug (season_year, slug),
  CONSTRAINT fk_game_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  event_code VARCHAR(80) NULL,
  tba_event_key VARCHAR(40) NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  event_status ENUM('planned','pit_open','schedule_ready','running','complete') NOT NULL DEFAULT 'planned',
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  roster_synced_at DATETIME NULL,
  schedule_synced_at DATETIME NULL,
  last_tba_sync_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_org_event (organization_id, game_id, name),
  KEY idx_event_tba (tba_event_key),
  CONSTRAINT fk_events_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_events_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_teams (
  event_id BIGINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  nickname VARCHAR(160) NULL,
  tba_team_key VARCHAR(40) NULL,
  PRIMARY KEY (event_id, frc_team_number),
  CONSTRAINT fk_eventteams_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE matches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  tba_match_key VARCHAR(60) NULL,
  comp_level VARCHAR(8) NOT NULL DEFAULT 'qm',
  set_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  match_number SMALLINT UNSIGNED NOT NULL,
  field_id SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  scheduled_time DATETIME NULL,
  started_at DATETIME NULL,
  ended_at DATETIME NULL,
  paused_at DATETIME NULL,
  total_pause_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  run_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  red_score SMALLINT NULL,
  blue_score SMALLINT NULL,
  winning_alliance ENUM('Red','Blue','Tie','Unknown') NOT NULL DEFAULT 'Unknown',
  state ENUM('scheduled','ready','running','paused','ended') NOT NULL DEFAULT 'scheduled',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_match (organization_id,event_id,comp_level,set_number,match_number,field_id),
  KEY idx_match_state (organization_id,field_id,state),
  CONSTRAINT fk_matches_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_matches_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_matches_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE match_teams (
  match_id BIGINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  alliance ENUM('Red','Blue') NOT NULL,
  station TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (match_id, alliance, station),
  KEY idx_mt_team (frc_team_number),
  CONSTRAINT fk_matchteams_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scout_access_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NULL,
  code_hash VARCHAR(255) NOT NULL,
  label VARCHAR(100) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  expires_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_codes_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_codes_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scout_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  scout_name VARCHAR(120) NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  alliance ENUM('Red','Blue') NOT NULL,
  station TINYINT UNSIGNED NULL,
  field_id SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  match_run_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('assigned','connected','scouting','submitted','closed') NOT NULL DEFAULT 'assigned',
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ss_live (organization_id,match_id,status),
  CONSTRAINT fk_ss_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_ss_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_ss_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
  CONSTRAINT fk_ss_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scouting_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id BIGINT UNSIGNED NOT NULL,
  owner_team_id BIGINT UNSIGNED NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  scout_session_id BIGINT UNSIGNED NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  alliance ENUM('Red','Blue') NOT NULL,
  action_code VARCHAR(190) NOT NULL,
  action_name VARCHAR(190) NULL,
  action_type VARCHAR(80) NULL,
  location VARCHAR(120) NULL,
  result ENUM('Success','Failure','Neutral') NOT NULL DEFAULT 'Neutral',
  points DECIMAL(8,2) NOT NULL DEFAULT 0,
  match_time_sec SMALLINT UNSIGNED NULL,
  match_run_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  phase ENUM('pre_match','auton','teleop','endgame','post_match','unknown') NOT NULL DEFAULT 'unknown',
  source ENUM('scout','admin','legacy_import','shared') NOT NULL DEFAULT 'scout',
  source_ip VARCHAR(45) NULL,
  created_by BIGINT UNSIGNED NULL,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  legacy_source VARCHAR(64) NULL,
  legacy_id BIGINT NULL,
  deleted_at DATETIME NULL,
  deleted_by BIGINT UNSIGNED NULL,
  deletion_reason VARCHAR(32) NULL,
  UNIQUE KEY uq_legacy_row (organization_id,legacy_source,legacy_id),
  KEY idx_action_robot (organization_id,event_id,frc_team_number),
  KEY idx_action_match (organization_id,match_id),
  KEY idx_action_live (organization_id,match_id,match_run_number,deleted_at),
  KEY idx_action_code (game_id,action_code),
  CONSTRAINT fk_sa_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_sa_owner_team FOREIGN KEY (owner_team_id) REFERENCES teams(id) ON DELETE SET NULL,
  CONSTRAINT fk_sa_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_sa_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
  CONSTRAINT fk_sa_session FOREIGN KEY (scout_session_id) REFERENCES scout_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_sa_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sa_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pit_scouting (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  owner_team_id BIGINT UNSIGNED NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  submitted_by BIGINT UNSIGNED NULL,
  data_json LONGTEXT NOT NULL,
  notes TEXT NULL,
  status ENUM('in_progress','complete') NOT NULL DEFAULT 'in_progress',
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pit (organization_id,event_id,frc_team_number),
  CONSTRAINT fk_pit_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_pit_owner FOREIGN KEY (owner_team_id) REFERENCES teams(id) ON DELETE SET NULL,
  CONSTRAINT fk_pit_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_pit_user FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pit_scouting_photos (
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

CREATE TABLE sharing_relationships (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_team_id BIGINT UNSIGNED NOT NULL,
  recipient_team_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','active','declined','revoked') NOT NULL DEFAULT 'pending',
  share_match_data TINYINT(1) NOT NULL DEFAULT 1,
  share_pit_data TINYINT(1) NOT NULL DEFAULT 0,
  share_notes TINYINT(1) NOT NULL DEFAULT 0,
  share_raw_actions TINYINT(1) NOT NULL DEFAULT 1,
  share_analytics TINYINT(1) NOT NULL DEFAULT 1,
  starts_at DATETIME NULL,
  expires_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_share_pair (owner_team_id,recipient_team_id),
  CONSTRAINT fk_share_owner FOREIGN KEY (owner_team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_share_recipient FOREIGN KEY (recipient_team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id VARCHAR(80) NULL,
  metadata_json LONGTEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_org_time (organization_id,created_at),
  CONSTRAINT fk_audit_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tba_cache (
  cache_key VARCHAR(190) PRIMARY KEY,
  response_json LONGTEXT NOT NULL,
  etag VARCHAR(255) NULL,
  fetched_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY idx_tba_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
