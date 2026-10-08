-- Neptune Robot Recall v1
-- The host page auto-creates these tables. This file is provided for manual installation/review.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS robot_recall_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  event_id BIGINT UNSIGNED NULL,
  season_year SMALLINT UNSIGNED NOT NULL,
  source_mode VARCHAR(24) NOT NULL DEFAULT 'event',
  room_code CHAR(6) NOT NULL UNIQUE,
  title VARCHAR(160) NOT NULL DEFAULT 'Robot Recall',
  question_count SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  question_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  status VARCHAR(24) NOT NULL DEFAULT 'lobby',
  current_question_index SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  question_started_at DATETIME(6) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  KEY idx_robot_recall_org_created (organization_id,created_at),
  KEY idx_robot_recall_event (event_id),
  CONSTRAINT fk_robot_recall_session_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_robot_recall_session_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_robot_recall_session_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS robot_recall_questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL,
  correct_team_number INT UNSIGNED NOT NULL,
  correct_nickname VARCHAR(160) NULL,
  logo_path VARCHAR(255) NOT NULL,
  options_json LONGTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_robot_recall_question_order (session_id,sort_order),
  CONSTRAINT fk_robot_recall_question_session FOREIGN KEY (session_id) REFERENCES robot_recall_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS robot_recall_players (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  nickname VARCHAR(32) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  score INT UNSIGNED NOT NULL DEFAULT 0,
  correct_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_robot_recall_player_name (session_id,nickname),
  UNIQUE KEY uq_robot_recall_player_token (session_id,token_hash),
  KEY idx_robot_recall_player_score (session_id,score,correct_count),
  CONSTRAINT fk_robot_recall_player_session FOREIGN KEY (session_id) REFERENCES robot_recall_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS robot_recall_answers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  player_id BIGINT UNSIGNED NOT NULL,
  selected_team_number INT UNSIGNED NOT NULL,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  response_ms INT UNSIGNED NOT NULL DEFAULT 0,
  points_awarded SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  answered_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_robot_recall_answer (question_id,player_id),
  KEY idx_robot_recall_answer_session (session_id,question_id),
  CONSTRAINT fk_robot_recall_answer_session FOREIGN KEY (session_id) REFERENCES robot_recall_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_robot_recall_answer_question FOREIGN KEY (question_id) REFERENCES robot_recall_questions(id) ON DELETE CASCADE,
  CONSTRAINT fk_robot_recall_answer_player FOREIGN KEY (player_id) REFERENCES robot_recall_players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
