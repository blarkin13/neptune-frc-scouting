-- Neptune time standard - read-only CHECK. Run any time after the forward script. Expect 0 in every "future_rows" column.
SET time_zone = '+00:00';
SELECT 'marker' AS item, cutover_utc, audit_log_max_id, applied_at FROM neptune_time_standard_marker;
SELECT 'augur_first_event_ratings.calculated_at' AS col, COUNT(*) AS rows_total, SUM(calculated_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(calculated_at) AS newest FROM augur_first_event_ratings
UNION ALL
SELECT 'neptune_first_event_links.linked_at' AS col, COUNT(*) AS rows_total, SUM(linked_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(linked_at) AS newest FROM neptune_first_event_links
UNION ALL
SELECT 'neptune_qa_modules.updated_at' AS col, COUNT(*) AS rows_total, SUM(updated_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(updated_at) AS newest FROM neptune_qa_modules
UNION ALL
SELECT 'neptune_qa_runs.updated_at' AS col, COUNT(*) AS rows_total, SUM(updated_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(updated_at) AS newest FROM neptune_qa_runs
UNION ALL
SELECT 'neptune_quality_review_audit.created_at' AS col, COUNT(*) AS rows_total, SUM(created_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(created_at) AS newest FROM neptune_quality_review_audit
UNION ALL
SELECT 'neptune_quality_reviews.updated_at' AS col, COUNT(*) AS rows_total, SUM(updated_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(updated_at) AS newest FROM neptune_quality_reviews
UNION ALL
SELECT 'portable_sync_tokens.created_at' AS col, COUNT(*) AS rows_total, SUM(created_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(created_at) AS newest FROM portable_sync_tokens
UNION ALL
SELECT 'robot_recall_competition_migrations.applied_at' AS col, COUNT(*) AS rows_total, SUM(applied_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(applied_at) AS newest FROM robot_recall_competition_migrations
UNION ALL
SELECT 'robot_recall_public_hosts.created_at' AS col, COUNT(*) AS rows_total, SUM(created_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(created_at) AS newest FROM robot_recall_public_hosts
UNION ALL
SELECT 'robot_recall_public_hosts.expires_at' AS col, COUNT(*) AS rows_total, SUM(expires_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(expires_at) AS newest FROM robot_recall_public_hosts
UNION ALL
SELECT 'robot_recall_series_links.created_at' AS col, COUNT(*) AS rows_total, SUM(created_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(created_at) AS newest FROM robot_recall_series_links
UNION ALL
SELECT 'robot_recall_session_flags.created_at' AS col, COUNT(*) AS rows_total, SUM(created_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(created_at) AS newest FROM robot_recall_session_flags
UNION ALL
SELECT 'robot_recall_session_flags.updated_at' AS col, COUNT(*) AS rows_total, SUM(updated_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(updated_at) AS newest FROM robot_recall_session_flags
UNION ALL
SELECT 'scouting_assignments.updated_at' AS col, COUNT(*) AS rows_total, SUM(updated_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(updated_at) AS newest FROM scouting_assignments
UNION ALL
SELECT 'tag_scouting_observations.created_at' AS col, COUNT(*) AS rows_total, SUM(created_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(created_at) AS newest FROM tag_scouting_observations
UNION ALL
SELECT 'tag_scouting_observations.resolved_at' AS col, COUNT(*) AS rows_total, SUM(resolved_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(resolved_at) AS newest FROM tag_scouting_observations
UNION ALL
SELECT 'robot_recall_players.joined_at' AS col, COUNT(*) AS rows_total, SUM(joined_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(joined_at) AS newest FROM robot_recall_players
UNION ALL
SELECT 'robot_recall_players.last_seen_at' AS col, COUNT(*) AS rows_total, SUM(last_seen_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE) AS future_rows, MAX(last_seen_at) AS newest FROM robot_recall_players
UNION ALL
SELECT 'audit_log.created_at', COUNT(*), SUM(created_at > UTC_TIMESTAMP() + INTERVAL 1 MINUTE), MAX(created_at) FROM audit_log;
