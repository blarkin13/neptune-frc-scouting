-- Neptune time standard - REVERSE data conversion
-- Undoes neptune_time_standard_forward.sql using the marker row. Run it BEFORE re-installing the old connection.php, or immediately after.
SET time_zone = '+00:00';

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

SET @cutover = (SELECT cutover_utc FROM neptune_time_standard_marker WHERE id = 1);
SET @audit_max = (SELECT audit_log_max_id FROM neptune_time_standard_marker WHERE id = 1);
-- If the marker is missing @cutover is NULL and every WHERE below matches nothing.

UPDATE augur_first_event_ratings SET
  calculated_at = IF(calculated_at IS NULL OR calculated_at >= @cutover, calculated_at, calculated_at - INTERVAL (SELECT IF(calculated_at >= d.start_utc AND calculated_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(calculated_at)) HOUR)
WHERE calculated_at < @cutover;

UPDATE neptune_first_event_links SET
  linked_at = IF(linked_at IS NULL OR linked_at >= @cutover, linked_at, linked_at - INTERVAL (SELECT IF(linked_at >= d.start_utc AND linked_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(linked_at)) HOUR)
WHERE linked_at < @cutover;

UPDATE neptune_qa_modules SET
  updated_at = IF(updated_at IS NULL OR updated_at >= @cutover, updated_at, updated_at - INTERVAL (SELECT IF(updated_at >= d.start_utc AND updated_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(updated_at)) HOUR)
WHERE updated_at < @cutover;

UPDATE neptune_qa_runs SET
  updated_at = IF(updated_at IS NULL OR updated_at >= @cutover, updated_at, updated_at - INTERVAL (SELECT IF(updated_at >= d.start_utc AND updated_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(updated_at)) HOUR)
WHERE updated_at < @cutover;

UPDATE neptune_quality_review_audit SET
  created_at = IF(created_at IS NULL OR created_at >= @cutover, created_at, created_at - INTERVAL (SELECT IF(created_at >= d.start_utc AND created_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(created_at)) HOUR)
WHERE created_at < @cutover;

UPDATE neptune_quality_reviews SET
  updated_at = IF(updated_at IS NULL OR updated_at >= @cutover, updated_at, updated_at - INTERVAL (SELECT IF(updated_at >= d.start_utc AND updated_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(updated_at)) HOUR)
WHERE updated_at < @cutover;

UPDATE portable_sync_tokens SET
  created_at = IF(created_at IS NULL OR created_at >= @cutover, created_at, created_at - INTERVAL (SELECT IF(created_at >= d.start_utc AND created_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(created_at)) HOUR)
WHERE created_at < @cutover;

UPDATE robot_recall_competition_migrations SET
  applied_at = IF(applied_at IS NULL OR applied_at >= @cutover, applied_at, applied_at - INTERVAL (SELECT IF(applied_at >= d.start_utc AND applied_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(applied_at)) HOUR)
WHERE applied_at < @cutover;

UPDATE robot_recall_public_hosts SET
  created_at = IF(created_at IS NULL OR created_at >= @cutover, created_at, created_at - INTERVAL (SELECT IF(created_at >= d.start_utc AND created_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(created_at)) HOUR),
  expires_at = IF(expires_at IS NULL, expires_at, expires_at - INTERVAL (SELECT IF(expires_at >= d.start_utc AND expires_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(expires_at)) HOUR)
WHERE created_at < @cutover;

UPDATE robot_recall_series_links SET
  created_at = IF(created_at IS NULL OR created_at >= @cutover, created_at, created_at - INTERVAL (SELECT IF(created_at >= d.start_utc AND created_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(created_at)) HOUR)
WHERE created_at < @cutover;

UPDATE robot_recall_session_flags SET
  created_at = IF(created_at IS NULL OR created_at >= @cutover, created_at, created_at - INTERVAL (SELECT IF(created_at >= d.start_utc AND created_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(created_at)) HOUR),
  updated_at = IF(updated_at IS NULL OR updated_at >= @cutover, updated_at, updated_at - INTERVAL (SELECT IF(updated_at >= d.start_utc AND updated_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(updated_at)) HOUR)
WHERE created_at < @cutover;

UPDATE scouting_assignments SET
  updated_at = IF(updated_at IS NULL OR updated_at >= @cutover, updated_at, updated_at - INTERVAL (SELECT IF(updated_at >= d.start_utc AND updated_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(updated_at)) HOUR)
WHERE updated_at < @cutover;

UPDATE tag_scouting_observations SET
  created_at = IF(created_at IS NULL OR created_at >= @cutover, created_at, created_at - INTERVAL (SELECT IF(created_at >= d.start_utc AND created_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(created_at)) HOUR),
  resolved_at = IF(resolved_at IS NULL OR resolved_at >= @cutover, resolved_at, resolved_at - INTERVAL (SELECT IF(resolved_at >= d.start_utc AND resolved_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(resolved_at)) HOUR),
  updated_at = updated_at
WHERE created_at < @cutover;

UPDATE robot_recall_players SET
  last_seen_at = IF(last_seen_at = joined_at, IF(last_seen_at IS NULL OR last_seen_at >= @cutover, last_seen_at, last_seen_at - INTERVAL (SELECT IF(last_seen_at >= d.start_utc AND last_seen_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(last_seen_at)) HOUR), last_seen_at),
  joined_at = IF(joined_at IS NULL OR joined_at >= @cutover, joined_at, joined_at - INTERVAL (SELECT IF(joined_at >= d.start_utc AND joined_at < d.end_utc, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(joined_at)) HOUR)
WHERE joined_at < @cutover;

UPDATE audit_log SET
  created_at = created_at + INTERVAL (SELECT IF(created_at >= d.start_local AND created_at < d.end_local, 5, 6) FROM neptune_time_standard_dst d WHERE d.y = YEAR(created_at)) HOUR
WHERE id <= @audit_max
  AND action NOT IN ('cron_tba_live','cron_first_live','cron_tba_discovery','cron_tba_weekly','auto_link_event_to_tba','link_event_to_tba');

DELETE FROM neptune_time_standard_marker WHERE id = 1;
