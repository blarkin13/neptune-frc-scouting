-- Neptune time standard - FORWARD data conversion
-- Run ONCE, immediately after installing the code package (connection.php now sets the database session to UTC).
-- Safe to re-run: the marker row makes a second run stop at the first INSERT with a duplicate-key error.
-- Reverse with neptune_time_standard_reverse.sql.
SET time_zone = '+00:00';
SET @cutover = UTC_TIMESTAMP();

CREATE TABLE IF NOT EXISTS neptune_time_standard_marker (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  cutover_utc DATETIME NOT NULL,
  audit_log_max_id BIGINT UNSIGNED NOT NULL,
  applied_at DATETIME NOT NULL,
  note VARCHAR(190) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS neptune_time_standard_dst (
  y SMALLINT NOT NULL PRIMARY KEY,
  start_local DATETIME NOT NULL, end_local DATETIME NOT NULL,
  start_utc DATETIME NOT NULL,   end_utc DATETIME NOT NULL
) ENGINE=InnoDB;
DELETE FROM neptune_time_standard_dst;
INSERT INTO neptune_time_standard_dst (y,start_local,end_local,start_utc,end_utc)
SELECT y, s, e, s + INTERVAL 6 HOUR, e + INTERVAL 5 HOUR FROM (
  SELECT y,
         DATE_ADD(DATE_ADD(m, INTERVAL MOD(8-DAYOFWEEK(m),7) DAY), INTERVAL 7 DAY) + INTERVAL 2 HOUR AS s,
         DATE_ADD(n, INTERVAL MOD(8-DAYOFWEEK(n),7) DAY) + INTERVAL 2 HOUR AS e
  FROM (SELECT y, STR_TO_DATE(CONCAT(y,'-03-01'),'%Y-%m-%d') AS m, STR_TO_DATE(CONCAT(y,'-11-01'),'%Y-%m-%d') AS n
        FROM (SELECT 2010 + a.d*10 + b.d AS y
              FROM (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) a
              CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                          UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b) yy) q
) z;

INSERT INTO neptune_time_standard_marker (id, cutover_utc, audit_log_max_id, applied_at, note)
VALUES (1, @cutover, (SELECT COALESCE(MAX(id),0) FROM audit_log), UTC_TIMESTAMP(), 'forward applied');

SET @cutover_local = @cutover - INTERVAL (SELECT IF(@cutover >= start_utc AND @cutover < end_utc, 5, 6) FROM neptune_time_standard_dst WHERE y = YEAR(@cutover)) HOUR;

-- 1. DATETIME columns the database filled with Chicago local time -> UTC (rows older than the cutover only)
UPDATE augur_first_event_ratings SET
  calculated_at = IF(calculated_at IS NULL OR calculated_at >= @cutover_local, calculated_at, calculated_at + INTERVAL (SELECT IF(calculated_at >= d.start_local AND calculated_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(calculated_at)) HOUR)
WHERE calculated_at < @cutover_local;

UPDATE neptune_first_event_links SET
  linked_at = IF(linked_at IS NULL OR linked_at >= @cutover_local, linked_at, linked_at + INTERVAL (SELECT IF(linked_at >= d.start_local AND linked_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(linked_at)) HOUR)
WHERE linked_at < @cutover_local;

UPDATE neptune_qa_modules SET
  updated_at = IF(updated_at IS NULL OR updated_at >= @cutover_local, updated_at, updated_at + INTERVAL (SELECT IF(updated_at >= d.start_local AND updated_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(updated_at)) HOUR)
WHERE updated_at < @cutover_local;

UPDATE neptune_qa_runs SET
  updated_at = IF(updated_at IS NULL OR updated_at >= @cutover_local, updated_at, updated_at + INTERVAL (SELECT IF(updated_at >= d.start_local AND updated_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(updated_at)) HOUR)
WHERE updated_at < @cutover_local;

UPDATE neptune_quality_review_audit SET
  created_at = IF(created_at IS NULL OR created_at >= @cutover_local, created_at, created_at + INTERVAL (SELECT IF(created_at >= d.start_local AND created_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(created_at)) HOUR)
WHERE created_at < @cutover_local;

UPDATE neptune_quality_reviews SET
  updated_at = IF(updated_at IS NULL OR updated_at >= @cutover_local, updated_at, updated_at + INTERVAL (SELECT IF(updated_at >= d.start_local AND updated_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(updated_at)) HOUR)
WHERE updated_at < @cutover_local;

UPDATE portable_sync_tokens SET
  created_at = IF(created_at IS NULL OR created_at >= @cutover_local, created_at, created_at + INTERVAL (SELECT IF(created_at >= d.start_local AND created_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(created_at)) HOUR)
WHERE created_at < @cutover_local;

UPDATE robot_recall_competition_migrations SET
  applied_at = IF(applied_at IS NULL OR applied_at >= @cutover_local, applied_at, applied_at + INTERVAL (SELECT IF(applied_at >= d.start_local AND applied_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(applied_at)) HOUR)
WHERE applied_at < @cutover_local;

UPDATE robot_recall_public_hosts SET
  created_at = IF(created_at IS NULL OR created_at >= @cutover_local, created_at, created_at + INTERVAL (SELECT IF(created_at >= d.start_local AND created_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(created_at)) HOUR),
  expires_at = IF(expires_at IS NULL, expires_at, expires_at + INTERVAL (SELECT IF(expires_at >= d.start_local AND expires_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(expires_at)) HOUR)
WHERE created_at < @cutover_local;

UPDATE robot_recall_series_links SET
  created_at = IF(created_at IS NULL OR created_at >= @cutover_local, created_at, created_at + INTERVAL (SELECT IF(created_at >= d.start_local AND created_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(created_at)) HOUR)
WHERE created_at < @cutover_local;

UPDATE robot_recall_session_flags SET
  created_at = IF(created_at IS NULL OR created_at >= @cutover_local, created_at, created_at + INTERVAL (SELECT IF(created_at >= d.start_local AND created_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(created_at)) HOUR),
  updated_at = IF(updated_at IS NULL OR updated_at >= @cutover_local, updated_at, updated_at + INTERVAL (SELECT IF(updated_at >= d.start_local AND updated_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(updated_at)) HOUR)
WHERE created_at < @cutover_local;

UPDATE scouting_assignments SET
  updated_at = IF(updated_at IS NULL OR updated_at >= @cutover_local, updated_at, updated_at + INTERVAL (SELECT IF(updated_at >= d.start_local AND updated_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(updated_at)) HOUR)
WHERE updated_at < @cutover_local;

UPDATE tag_scouting_observations SET
  created_at = IF(created_at IS NULL OR created_at >= @cutover_local, created_at, created_at + INTERVAL (SELECT IF(created_at >= d.start_local AND created_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(created_at)) HOUR),
  resolved_at = IF(resolved_at IS NULL OR resolved_at >= @cutover_local, resolved_at, resolved_at + INTERVAL (SELECT IF(resolved_at >= d.start_local AND resolved_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(resolved_at)) HOUR),
  updated_at = updated_at
WHERE created_at < @cutover_local;

UPDATE robot_recall_players SET
  last_seen_at = IF(last_seen_at = joined_at, IF(last_seen_at IS NULL OR last_seen_at >= @cutover_local, last_seen_at, last_seen_at + INTERVAL (SELECT IF(last_seen_at >= d.start_local AND last_seen_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(last_seen_at)) HOUR), last_seen_at),
  joined_at = IF(joined_at IS NULL OR joined_at >= @cutover_local, joined_at, joined_at + INTERVAL (SELECT IF(joined_at >= d.start_local AND joined_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(joined_at)) HOUR)
WHERE joined_at < @cutover_local;

-- 2. audit_log.created_at is a TIMESTAMP. Rows written with UTC_TIMESTAMP() while the session was Chicago are stored 5-6 hours late.
--    Rows written by the scheduler and the TBA link actions used the database clock and were already correct.
UPDATE audit_log SET
  created_at = created_at - INTERVAL (SELECT IF(created_at >= d.start_utc AND created_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(created_at)) HOUR
WHERE id <= (SELECT audit_log_max_id FROM neptune_time_standard_marker WHERE id = 1)
  AND action NOT IN ('cron_tba_live','cron_first_live','cron_tba_discovery','cron_tba_weekly','auto_link_event_to_tba','link_event_to_tba');
