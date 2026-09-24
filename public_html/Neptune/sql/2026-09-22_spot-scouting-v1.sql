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

