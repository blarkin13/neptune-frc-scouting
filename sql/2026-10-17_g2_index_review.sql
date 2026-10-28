-- G2 index review: scouting_actions and scouting_assignments (2026-10-17)
-- Reviewed against the 2026-10-16 production dump. Run by hand; Maintenance
-- Upload copies SQL files but never executes them.
--
-- scouting_actions (40k rows, 9.5 MB data, 26.7 MB indexes), 12 indexes:
--   PRIMARY(id), uuid, uq_legacy_row(organization_id,legacy_source,legacy_id)  keep: uniqueness
--   idx_action_live(organization_id,match_id,match_run_number,deleted_at)        keep: live monitor / match totals
--   idx_action_robot(organization_id,event_id,frc_team_number)                   keep: robot analytics
--   idx_action_code(game_id,action_code)                                          keep: also backs fk_sa_game
--   fk_sa_event, fk_sa_match, fk_sa_owner_team, fk_sa_session, fk_sa_user        keep: each is the only index
--                                                                                 starting with its FK column
--   idx_action_match(organization_id,match_id)                                    DROP: left prefix of idx_action_live
--     (sys.schema_redundant_indexes agrees; with it ignored, org+match lookups use
--     idx_action_live as a covering index. No code names it in FORCE/USE INDEX.)
--
-- scouting_assignments (4 rows), 12 indexes: no redundant index.
--   7 single-column fk_asg_* indexes exist because MySQL requires an index whose
--   first column is the FK column; none is a prefix of another index. The table
--   is tiny, so their write cost is negligible. Nothing to drop.

-- Online and non-blocking on InnoDB (ALGORITHM=INPLACE, LOCK=NONE).
ALTER TABLE scouting_actions DROP INDEX idx_action_match, ALGORITHM=INPLACE, LOCK=NONE;

-- To undo:
-- ALTER TABLE scouting_actions ADD INDEX idx_action_match (organization_id,match_id), ALGORITHM=INPLACE, LOCK=NONE;
