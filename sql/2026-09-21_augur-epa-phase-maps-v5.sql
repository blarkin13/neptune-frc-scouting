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
