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
