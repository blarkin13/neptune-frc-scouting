-- Neptune AUGUR D-EPA Beta v1
-- Higher D-EPA is better: estimated opponent points prevented relative to expected offense.
-- This beta is isolated from production Neptune EPA and Match Strategy predictions.

CREATE TABLE IF NOT EXISTS augur_depa_match_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tba_event_key VARCHAR(40) NOT NULL,
  season_year SMALLINT UNSIGNED NOT NULL,
  tba_match_key VARCHAR(64) NOT NULL,
  comp_level VARCHAR(8) NOT NULL DEFAULT 'qm',
  match_number SMALLINT UNSIGNED NOT NULL,
  defending_alliance ENUM('Red','Blue') NOT NULL,
  team1 INT UNSIGNED NOT NULL,
  team2 INT UNSIGNED NOT NULL,
  team3 INT UNSIGNED NOT NULL,
  opponent_team1 INT UNSIGNED NOT NULL,
  opponent_team2 INT UNSIGNED NOT NULL,
  opponent_team3 INT UNSIGNED NOT NULL,
  expected_opponent_score DECIMAL(12,4) NOT NULL,
  actual_opponent_score DECIMAL(12,4) NOT NULL,
  raw_suppression DECIMAL(12,4) NOT NULL,
  model_version VARCHAR(80) NOT NULL,
  calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_augur_depa_match_alliance (tba_match_key,defending_alliance),
  KEY idx_augur_depa_sample_year_event (season_year,tba_event_key),
  KEY idx_augur_depa_sample_event_match (tba_event_key,match_number,defending_alliance),
  CONSTRAINT fk_augur_depa_sample_event FOREIGN KEY (tba_event_key)
    REFERENCES augur_epa_archive_events(tba_event_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS augur_depa_public_event_ratings (
  tba_event_key VARCHAR(40) NOT NULL,
  season_year SMALLINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  macro_depa DECIMAL(12,6) NOT NULL DEFAULT 0,
  matches_played SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  alliance_rows SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  baseline_source VARCHAR(40) NOT NULL DEFAULT 'public_epa',
  model_version VARCHAR(80) NOT NULL,
  calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tba_event_key,frc_team_number),
  KEY idx_augur_depa_public_year_team (season_year,frc_team_number),
  KEY idx_augur_depa_public_year_rating (season_year,macro_depa),
  CONSTRAINT fk_augur_depa_public_event FOREIGN KEY (tba_event_key)
    REFERENCES augur_epa_archive_events(tba_event_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS augur_depa_team_match_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  tba_event_key VARCHAR(40) NOT NULL,
  tba_match_key VARCHAR(64) NOT NULL,
  season_year SMALLINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  alliance ENUM('Red','Blue') NOT NULL,
  raw_alliance_suppression DECIMAL(12,4) NOT NULL DEFAULT 0,
  defense_actions SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  spot_great_defense SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  spot_weak_defense SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  evidence_score DECIMAL(10,4) NOT NULL DEFAULT 0,
  attribution_weight DECIMAL(10,6) NOT NULL DEFAULT 0,
  credited_suppression DECIMAL(12,4) NULL,
  model_version VARCHAR(80) NOT NULL,
  calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_augur_depa_org_match_team (organization_id,match_id,frc_team_number),
  KEY idx_augur_depa_evidence_org_event_team (organization_id,event_id,frc_team_number),
  KEY idx_augur_depa_evidence_tba (tba_match_key,frc_team_number),
  CONSTRAINT fk_augur_depa_evidence_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_augur_depa_evidence_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_augur_depa_evidence_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS augur_depa_event_ratings (
  organization_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  tba_event_key VARCHAR(40) NOT NULL,
  season_year SMALLINT UNSIGNED NOT NULL,
  frc_team_number INT UNSIGNED NOT NULL,
  macro_depa DECIMAL(12,6) NULL,
  scouted_depa DECIMAL(12,6) NULL,
  neptune_depa DECIMAL(12,6) NULL,
  matches_played SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  verified_defense_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  defense_actions INT UNSIGNED NOT NULL DEFAULT 0,
  spot_great_defense SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  spot_weak_defense SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  confidence_score DECIMAL(6,2) NOT NULL DEFAULT 0,
  confidence_label ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low',
  agreement_score DECIMAL(6,2) NULL,
  source_label VARCHAR(80) NOT NULL DEFAULT 'macro only',
  model_version VARCHAR(80) NOT NULL,
  calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (organization_id,event_id,frc_team_number),
  KEY idx_augur_depa_org_event_rating (organization_id,event_id,neptune_depa),
  KEY idx_augur_depa_org_year_team (organization_id,season_year,frc_team_number),
  CONSTRAINT fk_augur_depa_rating_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_augur_depa_rating_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
