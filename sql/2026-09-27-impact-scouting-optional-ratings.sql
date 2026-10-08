-- Neptune Impact Scouting v1.4 - Optional Ratings
-- Untouched ratings are NULL so aggregate averages ignore them.
ALTER TABLE impact_scouting_observations
  MODIFY scoring_contribution TINYINT UNSIGNED NULL DEFAULT NULL,
  MODIFY overall_impact DECIMAL(3,1) NULL DEFAULT NULL,
  MODIFY auton_impact TINYINT UNSIGNED NULL DEFAULT NULL,
  MODIFY defense_impact TINYINT UNSIGNED NULL DEFAULT NULL;
