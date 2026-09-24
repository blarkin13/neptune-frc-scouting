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
