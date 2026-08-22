-- Neptune UX / replay / analytics upgrade
-- Run this ONCE on an existing Neptune database before uploading the matching PHP update.
-- MariaDB 10.6+ / MySQL 8 compatible.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS last_tba_sync_at DATETIME NULL AFTER active;

ALTER TABLE matches
  ADD COLUMN IF NOT EXISTS run_number SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER total_pause_seconds,
  ADD COLUMN IF NOT EXISTS red_score SMALLINT NULL AFTER run_number,
  ADD COLUMN IF NOT EXISTS blue_score SMALLINT NULL AFTER red_score,
  ADD COLUMN IF NOT EXISTS winning_alliance ENUM('Red','Blue','Tie','Unknown') NOT NULL DEFAULT 'Unknown' AFTER blue_score;

ALTER TABLE scout_sessions
  ADD COLUMN IF NOT EXISTS match_run_number SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER field_id;

ALTER TABLE scouting_actions
  ADD COLUMN IF NOT EXISTS match_run_number SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER match_time_sec;

-- Existing data represents the first scouting pass/run of each match.
UPDATE scout_sessions SET match_run_number = 1 WHERE match_run_number IS NULL OR match_run_number < 1;
UPDATE scouting_actions SET match_run_number = 1 WHERE match_run_number IS NULL OR match_run_number < 1;
UPDATE matches SET run_number = 1 WHERE run_number IS NULL OR run_number < 1;

-- Existing 2026 Rebuilt installations predate the configurable Auto -> Teleop pause.
-- Add the 2026 timing without replacing the existing action definitions.
UPDATE games
SET config_json = JSON_SET(
    config_json,
    '$.timing', JSON_OBJECT(
        'autonSeconds', 15,
        'transitionPauseSeconds', 10,
        'teleopSeconds', 135,
        'endgameSeconds', 15
    ),
    '$.grid', JSON_OBJECT('columns', 4)
)
WHERE name = '2026 Rebuilt'
  AND JSON_VALID(config_json);
