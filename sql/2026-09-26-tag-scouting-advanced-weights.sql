-- Neptune Tag Scouting v1.9
-- Existing installations normally do not need to run this manually:
-- scout/impact.php checks for the column and adds it automatically.
ALTER TABLE impact_scouting_observations
  ADD COLUMN tag_weights_json LONGTEXT NULL AFTER tags_json;
