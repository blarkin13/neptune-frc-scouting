-- Neptune Tag Scouting v1.6
-- Simplifies the existing Impact/Tag Scouting observation table to the fields
-- used by the Tag Scouting workflow. The page performs this migration safely
-- on first load; this file is retained for source control/manual maintenance.

ALTER TABLE impact_scouting_observations
  MODIFY scoring_contribution TINYINT UNSIGNED NULL DEFAULT NULL,
  DROP COLUMN overall_impact,
  DROP COLUMN auton_impact,
  DROP COLUMN defense_impact;
