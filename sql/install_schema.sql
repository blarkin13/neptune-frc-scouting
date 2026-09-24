-- Neptune complete clean-install schema
-- Generated from the current 2026-09-24 Neptune server snapshot.
-- Target: MariaDB 10.6+ (also intended to remain MySQL 8 compatible).
-- This file creates schema only; it does not create the database/user itself.

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
  organization_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  season_year SMALLINT UNSIGNED NOT NULL,
  slug VARCHAR(120) NOT NULL,
  json_filename VARCHAR(255) NOT NULL DEFAULT '',
  config_json LONGTEXT NOT NULL,
  pit_config_json LONGTEXT NULL,
  pre_scout_config_json LONGTEXT NULL,
  current_revision_id BIGINT UNSIGNED NULL,
  draft_revision_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_org_year_slug (organization_id, season_year, slug),
  KEY idx_game_current_revision (current_revision_id),
  KEY idx_game_draft_revision (draft_revision_id),
  CONSTRAINT fk_game_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_game_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE game_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  revision_number INT UNSIGNED NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  match_config_json LONGTEXT NOT NULL,
  pit_config_json LONGTEXT NULL,
  pre_scout_config_json LONGTEXT NULL,
  field_image_path VARCHAR(255) NULL,
  checksum CHAR(64) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  published_by BIGINT UNSIGNED NULL,
  published_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_revision_number (game_id, revision_number),
  KEY idx_game_revision_org_status (organization_id, status),
  CONSTRAINT fk_gr_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_gr_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  CONSTRAINT fk_gr_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_gr_published_by FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE games
  ADD CONSTRAINT fk_game_current_revision FOREIGN KEY (current_revision_id) REFERENCES game_revisions(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_game_draft_revision FOREIGN KEY (draft_revision_id) REFERENCES game_revisions(id) ON DELETE SET NULL;

CREATE TABLE game_config_shares (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  game_id BIGINT UNSIGNED NOT NULL,
  recipient_organization_id BIGINT UNSIGNED NOT NULL,
  shared_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_config_share (game_id, recipient_organization_id),
  KEY idx_game_config_recipient (recipient_organization_id, game_id),
  CONSTRAINT fk_gcs_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  CONSTRAINT fk_gcs_recipient FOREIGN KEY (recipient_organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_gcs_user FOREIGN KEY (shared_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  game_revision_id BIGINT UNSIGNED NOT NULL,
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
  KEY idx_event_revision (game_revision_id),
  CONSTRAINT fk_events_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_events_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE RESTRICT,
  CONSTRAINT fk_events_revision FOREIGN KEY (game_revision_id) REFERENCES game_revisions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_teams (
  event_id BIGINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  nickname VARCHAR(160) NULL,
  city VARCHAR(120) NULL,
  state_prov VARCHAR(120) NULL,
  country VARCHAR(120) NULL,
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


CREATE TABLE robot_season_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  data_json LONGTEXT NOT NULL,
  notes TEXT NULL,
  tba_json LONGTEXT NULL,
  tba_updated_at DATETIME NULL,
  last_event_id BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_robot_season (organization_id,game_id,frc_team_number),
  KEY idx_robot_season_team (frc_team_number,game_id),
  CONSTRAINT fk_rsp_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_rsp_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  CONSTRAINT fk_rsp_event FOREIGN KEY (last_event_id) REFERENCES events(id) ON DELETE SET NULL,
  CONSTRAINT fk_rsp_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pre_scouting (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  submitted_by BIGINT UNSIGNED NULL,
  contact_status ENUM('not_started','contacted','received','no_response','unavailable') NOT NULL DEFAULT 'not_started',
  contact_name VARCHAR(160) NULL,
  contact_method VARCHAR(60) NULL,
  contact_details VARCHAR(255) NULL,
  contacted_at DATETIME NULL,
  data_json LONGTEXT NOT NULL,
  notes TEXT NULL,
  status ENUM('in_progress','complete') NOT NULL DEFAULT 'in_progress',
  inherited_from_event_id BIGINT UNSIGNED NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pre_scout (organization_id,event_id,frc_team_number),
  KEY idx_pre_scout_season (organization_id,game_id,frc_team_number),
  KEY idx_pre_scout_status (organization_id,event_id,status,contact_status),
  CONSTRAINT fk_pre_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_pre_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_pre_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  CONSTRAINT fk_pre_user FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pre_inherited_event FOREIGN KEY (inherited_from_event_id) REFERENCES events(id) ON DELETE SET NULL
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

-- ============================================================
-- Offline field/cloud sync tracking
-- ============================================================
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


-- ============================================================
-- Alliance Selection shared state
-- ============================================================
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


-- ============================================================
-- AUGUR EPA archive v3
-- ============================================================
-- Neptune AUGUR EPA Archive v3
-- Global/public EPA archive. No organization_id and no foreign keys to Neptune's operational events.
-- TBA source data is cached once per event in normalized alliance samples; EPA can then be recalculated
-- locally without re-downloading completed historical events.
--
-- User has NOT installed the previous EPA v2 schema, so this is a clean install.
-- Do not run 2026-09-21_augur-epa-v2.sql if you use this schema.

CREATE TABLE IF NOT EXISTS augur_epa_archive_years (
  season_year SMALLINT UNSIGNED NOT NULL,
  source_status VARCHAR(24) NOT NULL DEFAULT 'new',
  event_count INT UNSIGNED NOT NULL DEFAULT 0,
  source_hash CHAR(64) NULL,
  last_discovered_at DATETIME NULL,
  last_error TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (season_year),
  KEY idx_augur_epa_archive_year_status (source_status,last_discovered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS augur_epa_archive_events (
  tba_event_key VARCHAR(40) NOT NULL,
  season_year SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  short_name VARCHAR(255) NULL,
  event_code VARCHAR(32) NULL,
  event_type SMALLINT NULL,
  district_key VARCHAR(40) NULL,
  event_week SMALLINT NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  city VARCHAR(120) NULL,
  state_prov VARCHAR(120) NULL,
  country VARCHAR(120) NULL,
  source_status VARCHAR(24) NOT NULL DEFAULT 'discovered',
  is_complete TINYINT(1) NOT NULL DEFAULT 0,
  qual_match_count INT UNSIGNED NOT NULL DEFAULT 0,
  alliance_sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  auto_sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  teleop_sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  endgame_sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  source_hash CHAR(64) NULL,
  source_fetched_at DATETIME NULL,
  ratings_calculated_at DATETIME NULL,
  model_version VARCHAR(40) NULL,
  last_error TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tba_event_key),
  KEY idx_augur_epa_archive_event_year (season_year,start_date,tba_event_key),
  KEY idx_augur_epa_archive_event_status (season_year,source_status,source_fetched_at),
  KEY idx_augur_epa_archive_event_complete (is_complete,season_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS augur_epa_alliance_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tba_event_key VARCHAR(40) NOT NULL,
  season_year SMALLINT UNSIGNED NOT NULL,
  tba_match_key VARCHAR(64) NOT NULL,
  comp_level VARCHAR(8) NOT NULL DEFAULT 'qm',
  set_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  match_number SMALLINT UNSIGNED NOT NULL,
  actual_time BIGINT UNSIGNED NULL,
  alliance ENUM('Red','Blue') NOT NULL,
  team1 INT UNSIGNED NULL,
  team2 INT UNSIGNED NULL,
  team3 INT UNSIGNED NULL,
  team_keys_json TEXT NULL,
  official_score DECIMAL(10,3) NOT NULL DEFAULT 0,
  modeled_score DECIMAL(10,3) NOT NULL DEFAULT 0,
  auto_score DECIMAL(10,3) NULL,
  teleop_score DECIMAL(10,3) NULL,
  endgame_score DECIMAL(10,3) NULL,
  score_breakdown_json MEDIUMTEXT NULL,
  source_hash CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_augur_epa_sample_match_alliance (tba_match_key,alliance),
  KEY idx_augur_epa_sample_event_match (tba_event_key,match_number,alliance),
  KEY idx_augur_epa_sample_year (season_year,tba_event_key),
  CONSTRAINT fk_augur_epa_sample_event FOREIGN KEY (tba_event_key)
    REFERENCES augur_epa_archive_events(tba_event_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS augur_epa_event_ratings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tba_event_key VARCHAR(40) NOT NULL,
  season_year SMALLINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  rating DECIMAL(10,3) NOT NULL DEFAULT 0,
  auto_rating DECIMAL(10,3) NULL,
  teleop_rating DECIMAL(10,3) NULL,
  endgame_rating DECIMAL(10,3) NULL,
  trend DECIMAL(10,3) NOT NULL DEFAULT 0,
  sigma DECIMAL(10,3) NOT NULL DEFAULT 0,
  confidence DECIMAL(6,2) NOT NULL DEFAULT 0,
  matches_played SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  wins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  losses SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ties SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  model_version VARCHAR(40) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_augur_epa_event_team (tba_event_key,frc_team_number),
  KEY idx_augur_epa_event_rank (tba_event_key,rating),
  KEY idx_augur_epa_event_year_team (season_year,frc_team_number,tba_event_key),
  CONSTRAINT fk_augur_epa_event_rating_event FOREIGN KEY (tba_event_key)
    REFERENCES augur_epa_archive_events(tba_event_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS augur_epa_match_ratings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tba_event_key VARCHAR(40) NOT NULL,
  season_year SMALLINT UNSIGNED NOT NULL,
  tba_match_key VARCHAR(64) NOT NULL,
  match_number SMALLINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  alliance ENUM('Red','Blue') NOT NULL,
  pre_rating DECIMAL(10,3) NOT NULL,
  post_rating DECIMAL(10,3) NOT NULL,
  rating_delta DECIMAL(10,3) NOT NULL DEFAULT 0,
  pre_auto_rating DECIMAL(10,3) NULL,
  post_auto_rating DECIMAL(10,3) NULL,
  pre_teleop_rating DECIMAL(10,3) NULL,
  post_teleop_rating DECIMAL(10,3) NULL,
  pre_endgame_rating DECIMAL(10,3) NULL,
  post_endgame_rating DECIMAL(10,3) NULL,
  pre_sigma DECIMAL(10,3) NOT NULL DEFAULT 0,
  post_sigma DECIMAL(10,3) NOT NULL DEFAULT 0,
  model_version VARCHAR(40) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_augur_epa_match_team (tba_match_key,frc_team_number),
  KEY idx_augur_epa_match_history (tba_event_key,frc_team_number,match_number),
  KEY idx_augur_epa_match_year_team (season_year,frc_team_number,tba_event_key,match_number),
  CONSTRAINT fk_augur_epa_match_rating_event FOREIGN KEY (tba_event_key)
    REFERENCES augur_epa_archive_events(tba_event_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS augur_epa_season_ratings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  season_year SMALLINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  rating DECIMAL(10,3) NOT NULL DEFAULT 0,
  auto_rating DECIMAL(10,3) NULL,
  teleop_rating DECIMAL(10,3) NULL,
  endgame_rating DECIMAL(10,3) NULL,
  trend DECIMAL(10,3) NOT NULL DEFAULT 0,
  sigma DECIMAL(10,3) NOT NULL DEFAULT 0,
  confidence DECIMAL(6,2) NOT NULL DEFAULT 0,
  events_played SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  matches_played SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  wins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  losses SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ties SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_event_key VARCHAR(40) NULL,
  model_version VARCHAR(40) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_augur_epa_season_team (season_year,frc_team_number),
  KEY idx_augur_epa_season_rank (season_year,rating),
  KEY idx_augur_epa_season_team_history (frc_team_number,season_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- AUGUR EPA phase maps
-- ============================================================
-- Neptune AUGUR EPA v5: configurable phase mappings
-- Additive migration for the existing AUGUR EPA Archive v3/v4 schema.
-- The EPA math remains generic. This table tells AUGUR how a season's TBA
-- score_breakdown fields map to Auto / Teleop / Endgame.
--
-- Future seasons can be configured from:
--   /Neptune/admin/augur-epa-phase-maps.php
-- without editing PHP.

CREATE TABLE IF NOT EXISTS augur_epa_phase_maps (
  season_year SMALLINT UNSIGNED NOT NULL,
  mapping_json MEDIUMTEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (season_year),
  KEY idx_augur_epa_phase_maps_enabled (enabled,season_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the current 2026 mapping. Existing historical seasons continue to use
-- the v4 historical fallback unless/until you choose to configure them here.
INSERT INTO augur_epa_phase_maps (season_year,mapping_json,enabled,notes)
VALUES (
  2026,
  '{"version":1,"auto":{"mode":"sum","terms":[{"path":"hubScore.autoPoints","weight":1},{"path":"autoTowerPoints","weight":1}]},"teleop":{"mode":"sum","terms":[{"path":"hubScore.transitionPoints","weight":1},{"path":"hubScore.shift1Points","weight":1},{"path":"hubScore.shift2Points","weight":1},{"path":"hubScore.shift3Points","weight":1},{"path":"hubScore.shift4Points","weight":1}]},"endgame":{"mode":"sum","terms":[{"path":"hubScore.endgamePoints","weight":1},{"path":"endGameTowerPoints","weight":1}]},"modeled_subtract":[],"reconcile":"teleop"}',
  1,
  '2026 TBA phase mapping: auto fuel+tower; teleop transition+shifts 1-4; endgame fuel+tower. Residual reconciled to teleop.'
)
ON DUPLICATE KEY UPDATE
  mapping_json=VALUES(mapping_json),
  enabled=VALUES(enabled),
  notes=VALUES(notes),
  updated_at=CURRENT_TIMESTAMP;


-- ============================================================
-- AUGUR EPA team directory
-- ============================================================
-- Neptune AUGUR public team directory
-- Stores public TBA team identity/location metadata locally so the public
-- EPA page and API never need live TBA calls during page requests.

CREATE TABLE IF NOT EXISTS augur_epa_team_directory (
  season_year SMALLINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  tba_team_key VARCHAR(40) NULL,
  nickname VARCHAR(160) NULL,
  name VARCHAR(255) NULL,
  city VARCHAR(120) NULL,
  state_prov VARCHAR(120) NULL,
  country VARCHAR(120) NULL,
  rookie_year SMALLINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (season_year,frc_team_number),
  KEY idx_augur_epa_team_directory_team (frc_team_number,season_year),
  KEY idx_augur_epa_team_directory_country (season_year,country)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- AUGUR traditional OPR persisted event ratings
-- ============================================================
CREATE TABLE IF NOT EXISTS augur_opr_event_ratings (
  tba_event_key VARCHAR(40) NOT NULL,
  season_year SMALLINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  event_opr DECIMAL(12,6) NOT NULL,
  matches_played SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  alliance_rows SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  model_version VARCHAR(80) NOT NULL,
  calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tba_event_key, frc_team_number),
  KEY idx_augur_opr_season_team (season_year, frc_team_number),
  KEY idx_augur_opr_season_rating (season_year, event_opr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- Match Strategy saved plans
-- ============================================================
CREATE TABLE IF NOT EXISTS match_strategies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  strategy_json LONGTEXT NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_match_strategy (organization_id, match_id, frc_team_number),
  KEY idx_match_strategy_event_team (organization_id, event_id, frc_team_number),
  CONSTRAINT fk_ms_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_ms_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_ms_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
  CONSTRAINT fk_ms_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ms_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- Browser action replay receipts used by Connection Guard
-- ============================================================
CREATE TABLE IF NOT EXISTS action_request_receipts (
  request_uuid CHAR(36) NOT NULL PRIMARY KEY,
  session_hash CHAR(64) NOT NULL,
  response_code SMALLINT UNSIGNED NOT NULL,
  response_body MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_action_request_receipts_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- Spot Scouting
-- ============================================================
-- Neptune Spot Scouting v1
-- Organization-scoped quick observations, tags, review queue, field-following,
-- photos/videos, and export support.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS spot_tags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NULL,
  seed_key VARCHAR(100) NULL,
  label VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT 'General',
  icon VARCHAR(120) NOT NULL DEFAULT 'fa-solid fa-tag',
  severity ENUM('positive','info','warning','critical') NOT NULL DEFAULT 'info',
  match_enabled TINYINT(1) NOT NULL DEFAULT 1,
  pit_enabled TINYINT(1) NOT NULL DEFAULT 1,
  general_enabled TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_spot_seed (seed_key),
  UNIQUE KEY uq_spot_org_slug (organization_id,slug),
  KEY idx_spot_tags_visible (organization_id,active,category,sort_order),
  CONSTRAINT fk_spot_tags_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_spot_tags_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spot_tag_visibility (
  organization_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (organization_id,tag_id),
  CONSTRAINT fk_spot_vis_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_spot_vis_tag FOREIGN KEY (tag_id) REFERENCES spot_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spot_observations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NULL,
  match_id BIGINT UNSIGNED NULL,
  field_id SMALLINT UNSIGNED NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  context ENUM('match','pit','general') NOT NULL DEFAULT 'general',
  entry_mode ENUM('auto','manual') NOT NULL DEFAULT 'manual',
  note TEXT NULL,
  severity ENUM('positive','info','warning','critical') NOT NULL DEFAULT 'info',
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_by BIGINT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  resolution_note TEXT NULL,
  UNIQUE KEY uq_spot_observation_uuid (uuid),
  KEY idx_spot_org_recent (organization_id,created_at),
  KEY idx_spot_org_team (organization_id,frc_team_number,created_at),
  KEY idx_spot_org_event (organization_id,event_id,created_at),
  KEY idx_spot_review (organization_id,status,severity,created_at),
  CONSTRAINT fk_spot_obs_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_spot_obs_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
  CONSTRAINT fk_spot_obs_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE SET NULL,
  CONSTRAINT fk_spot_obs_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_spot_obs_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spot_observation_tags (
  observation_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (observation_id,tag_id),
  KEY idx_spot_ot_tag (tag_id),
  CONSTRAINT fk_spot_ot_obs FOREIGN KEY (observation_id) REFERENCES spot_observations(id) ON DELETE CASCADE,
  CONSTRAINT fk_spot_ot_tag FOREIGN KEY (tag_id) REFERENCES spot_tags(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spot_observation_media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  observation_id BIGINT UNSIGNED NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  media_type ENUM('photo','video') NOT NULL,
  storage_relpath VARCHAR(500) NOT NULL,
  original_filename VARCHAR(255) NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  duration_seconds DECIMAL(8,2) NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_spot_media_obs (observation_id),
  KEY idx_spot_media_org (organization_id,created_at),
  CONSTRAINT fk_spot_media_obs FOREIGN KEY (observation_id) REFERENCES spot_observations(id) ON DELETE CASCADE,
  CONSTRAINT fk_spot_media_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_spot_media_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spot_scout_preferences (
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NULL,
  field_id SMALLINT UNSIGNED NULL,
  auto_follow TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (organization_id,user_id),
  CONSTRAINT fk_spot_pref_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_spot_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_spot_pref_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('great-defense',NULL,'Great Defense','great-defense','Performance','fa-solid fa-shield-halved','positive',1,0,1,1,10)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('weak-defense',NULL,'Weak Defense','weak-defense','Performance','fa-solid fa-shield','warning',1,0,1,1,20)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('fast-cycles',NULL,'Fast Cycles','fast-cycles','Performance','fa-solid fa-bolt','positive',1,0,1,1,30)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('slow-cycles',NULL,'Slow Cycles','slow-cycles','Performance','fa-solid fa-gauge-simple-low','warning',1,0,1,1,40)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('accurate',NULL,'Accurate','accurate','Performance','fa-solid fa-bullseye','positive',1,0,1,1,50)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('inaccurate',NULL,'Inaccurate','inaccurate','Performance','fa-solid fa-circle-xmark','warning',1,0,1,1,60)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('strong-auto',NULL,'Strong Auto','strong-auto','Performance','fa-solid fa-robot','positive',1,0,1,1,70)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('auto-failed',NULL,'Auto Failed','auto-failed','Performance','fa-solid fa-triangle-exclamation','warning',1,0,1,1,80)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('strong-endgame',NULL,'Strong Endgame','strong-endgame','Performance','fa-solid fa-flag-checkered','positive',1,0,1,1,90)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('endgame-failed',NULL,'Endgame Failed','endgame-failed','Performance','fa-solid fa-xmark','warning',1,0,1,1,100)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('lots-of-fouls',NULL,'Lots of Fouls','lots-of-fouls','Discipline','fa-solid fa-flag','warning',1,0,1,1,10)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('penalty-risk',NULL,'Penalty Risk','penalty-risk','Discipline','fa-solid fa-triangle-exclamation','warning',1,0,1,1,20)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('card-concern',NULL,'Yellow / Red Card Concern','card-concern','Discipline','fa-solid fa-clone','critical',1,0,1,1,30)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('aggressive-driving',NULL,'Aggressive Driving','aggressive-driving','Discipline','fa-solid fa-car-burst','info',1,0,1,1,40)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('robot-disabled',NULL,'Robot Disabled','robot-disabled','Reliability','fa-solid fa-power-off','critical',1,1,1,1,10)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('intermittent-problem',NULL,'Intermittent Problem','intermittent-problem','Reliability','fa-solid fa-wave-square','warning',1,1,1,1,20)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('broke-during-match',NULL,'Broke During Match','broke-during-match','Reliability','fa-solid fa-screwdriver-wrench','critical',1,1,1,1,30)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('recovered',NULL,'Recovered','recovered','Reliability','fa-solid fa-heart-pulse','positive',1,1,1,1,40)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('connection-issue',NULL,'Connection Issue','connection-issue','Reliability','fa-solid fa-wifi','warning',1,1,1,1,50)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('robot-damaged',NULL,'Robot Damaged','robot-damaged','Pit','fa-solid fa-hammer','critical',0,1,1,1,10)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('repairing-robot',NULL,'Repairing Robot','repairing-robot','Pit','fa-solid fa-screwdriver-wrench','warning',0,1,1,1,20)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('major-repair',NULL,'Major Repair','major-repair','Pit','fa-solid fa-toolbox','critical',0,1,1,1,30)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('minor-repair',NULL,'Minor Repair','minor-repair','Pit','fa-solid fa-wrench','warning',0,1,1,1,40)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('waiting-for-parts',NULL,'Waiting for Parts','waiting-for-parts','Pit','fa-solid fa-clock','critical',0,1,1,1,50)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('inspection-issue',NULL,'Inspection Issue','inspection-issue','Pit','fa-solid fa-clipboard-check','warning',0,1,1,1,60)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('passed-inspection',NULL,'Passed Inspection','passed-inspection','Pit','fa-solid fa-circle-check','positive',0,1,1,1,70)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('looks-ready',NULL,'Looks Ready','looks-ready','Pit','fa-solid fa-thumbs-up','positive',0,1,1,1,80)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('good-defender',NULL,'Good Defender','good-defender','Strategy','fa-solid fa-shield-heart','positive',1,0,1,1,10)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('hard-to-defend',NULL,'Hard to Defend','hard-to-defend','Strategy','fa-solid fa-person-running','positive',1,0,1,1,20)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('feeder-specialist',NULL,'Feeder Specialist','feeder-specialist','Strategy','fa-solid fa-arrows-spin','info',1,0,1,1,30)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('multiple-roles',NULL,'Plays Multiple Roles','multiple-roles','Strategy','fa-solid fa-layer-group','positive',1,1,1,1,40)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('avoids-contact',NULL,'Avoids Contact','avoids-contact','Strategy','fa-solid fa-route','info',1,0,1,1,50)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('watch-this-team',NULL,'Watch This Team','watch-this-team','General','fa-solid fa-eye','info',1,1,1,1,10)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('improved',NULL,'Improved','improved','General','fa-solid fa-arrow-trend-up','positive',1,1,1,1,20)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('declining',NULL,'Declining','declining','General','fa-solid fa-arrow-trend-down','warning',1,1,1,1,30)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

INSERT INTO spot_tags
(seed_key,organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order)
VALUES ('needs-follow-up',NULL,'Needs Follow-up','needs-follow-up','General','fa-solid fa-bookmark','warning',1,1,1,1,40)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),
  match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),general_enabled=VALUES(general_enabled),
  active=1,sort_order=VALUES(sort_order);

