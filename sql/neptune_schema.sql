-- Neptune Public 1.0 canonical database schema
-- Generated from the live Neptune production database structure.
-- Schema only: no user/team/scouting data and no application secrets are included.
-- Import this file into an EMPTY database for a clean self-host installation.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- ------------------------------------------------------------------
-- action_request_receipts
-- ------------------------------------------------------------------
CREATE TABLE `action_request_receipts` (
  `request_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `response_code` smallint unsigned NOT NULL,
  `response_body` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_uuid`),
  KEY `idx_action_request_receipts_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- alliance_selection_beta_event_state
-- ------------------------------------------------------------------
CREATE TABLE `alliance_selection_beta_event_state` (
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `state_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `updated_by` bigint unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`organization_id`,`event_id`),
  KEY `idx_asbeta_event` (`event_id`),
  KEY `fk_asbeta_event_user` (`updated_by`),
  CONSTRAINT `fk_asbeta_event_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asbeta_event_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asbeta_event_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- alliance_selection_beta_team_state
-- ------------------------------------------------------------------
CREATE TABLE `alliance_selection_beta_team_state` (
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `strategy_frc_team_number` int unsigned NOT NULL,
  `state_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `updated_by` bigint unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`organization_id`,`event_id`,`strategy_frc_team_number`),
  KEY `idx_asbeta_team_event` (`event_id`),
  KEY `idx_asbeta_team_number` (`strategy_frc_team_number`),
  KEY `fk_asbeta_team_user` (`updated_by`),
  CONSTRAINT `fk_asbeta_team_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asbeta_team_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_asbeta_team_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- alliance_selection_state
-- ------------------------------------------------------------------
CREATE TABLE `alliance_selection_state` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `state_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alliance_selection_org_event` (`organization_id`,`event_id`),
  KEY `idx_alliance_selection_event` (`event_id`),
  KEY `fk_alliance_selection_updated_by` (`updated_by`),
  CONSTRAINT `fk_alliance_selection_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_alliance_selection_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_alliance_selection_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- audit_log
-- ------------------------------------------------------------------
CREATE TABLE `audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata_json` longtext COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_org_time` (`organization_id`,`created_at`),
  KEY `fk_audit_user` (`user_id`),
  CONSTRAINT `fk_audit_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_depa_context_controls
-- ------------------------------------------------------------------
CREATE TABLE `augur_depa_context_controls` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `target_match_id` bigint unsigned NOT NULL,
  `target_tba_match_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_match_number` smallint unsigned NOT NULL,
  `target_frc_team_number` int unsigned NOT NULL,
  `control_match_id` bigint unsigned NOT NULL,
  `control_tba_match_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `control_match_number` smallint unsigned NOT NULL,
  `control_alliance` enum('Red','Blue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `control_contains_target_team` tinyint(1) NOT NULL DEFAULT '0',
  `target_expected_alliance_score` decimal(12,4) NOT NULL,
  `target_expected_opponent_score` decimal(12,4) NOT NULL,
  `control_expected_alliance_score` decimal(12,4) NOT NULL,
  `control_expected_opponent_score` decimal(12,4) NOT NULL,
  `control_raw_suppression` decimal(12,4) NOT NULL,
  `distance_score` decimal(12,4) NOT NULL,
  `rank_order` smallint unsigned NOT NULL DEFAULT '0',
  `model_version` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_augur_depa_context_control` (`organization_id`,`event_id`,`target_match_id`,`target_frc_team_number`,`control_match_id`,`control_alliance`),
  KEY `idx_augur_depa_context_target` (`organization_id`,`event_id`,`target_frc_team_number`,`target_match_id`),
  KEY `idx_augur_depa_context_control_match` (`organization_id`,`event_id`,`control_match_id`),
  KEY `fk_augur_depa_context_event` (`event_id`),
  KEY `fk_augur_depa_context_target_match` (`target_match_id`),
  KEY `fk_augur_depa_context_control_match` (`control_match_id`),
  CONSTRAINT `fk_augur_depa_context_control_match` FOREIGN KEY (`control_match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_augur_depa_context_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_augur_depa_context_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_augur_depa_context_target_match` FOREIGN KEY (`target_match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_depa_event_ratings
-- ------------------------------------------------------------------
CREATE TABLE `augur_depa_event_ratings` (
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `tba_event_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `season_year` smallint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `public_depa` decimal(12,6) DEFAULT NULL,
  `defense_depa` decimal(12,6) DEFAULT NULL,
  `nondefense_suppression` decimal(12,6) DEFAULT NULL,
  `possible_defense_suppression` decimal(12,6) DEFAULT NULL,
  `event_has_scouting` tinyint(1) NOT NULL DEFAULT '0',
  `nondefense_matches` smallint unsigned NOT NULL DEFAULT '0',
  `macro_depa` decimal(12,6) DEFAULT NULL,
  `scouted_depa` decimal(12,6) DEFAULT NULL,
  `neptune_depa` decimal(12,6) DEFAULT NULL,
  `matches_played` smallint unsigned NOT NULL DEFAULT '0',
  `verified_defense_matches` smallint unsigned NOT NULL DEFAULT '0',
  `possible_defense_matches` smallint unsigned NOT NULL DEFAULT '0',
  `baseline_matches` smallint unsigned NOT NULL DEFAULT '0',
  `baseline_suppression` decimal(12,6) DEFAULT NULL,
  `defense_match_suppression` decimal(12,6) DEFAULT NULL,
  `baseline_source` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `context_control_count` int unsigned NOT NULL DEFAULT '0',
  `blend_pre_shrink` decimal(12,6) DEFAULT NULL,
  `reliability_factor` decimal(8,6) NOT NULL DEFAULT '0.000000',
  `defense_actions` int unsigned NOT NULL DEFAULT '0',
  `spot_great_defense` smallint unsigned NOT NULL DEFAULT '0',
  `spot_weak_defense` smallint unsigned NOT NULL DEFAULT '0',
  `role_confidence_score` decimal(6,2) NOT NULL DEFAULT '0.00',
  `role_confidence_label` enum('Low','Medium','High') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Low',
  `estimate_confidence_score` decimal(6,2) NOT NULL DEFAULT '0.00',
  `estimate_confidence_label` enum('Low','Medium','High') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Low',
  `validation_label` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Macro only',
  `confidence_score` decimal(6,2) NOT NULL DEFAULT '0.00',
  `confidence_label` enum('Low','Medium','High') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Low',
  `agreement_score` decimal(6,2) DEFAULT NULL,
  `source_label` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'macro only',
  `model_version` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`organization_id`,`event_id`,`frc_team_number`),
  KEY `idx_augur_depa_org_event_rating` (`organization_id`,`event_id`,`neptune_depa`),
  KEY `idx_augur_depa_org_year_team` (`organization_id`,`season_year`,`frc_team_number`),
  KEY `fk_augur_depa_rating_event` (`event_id`),
  CONSTRAINT `fk_augur_depa_rating_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_augur_depa_rating_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_depa_match_samples
-- ------------------------------------------------------------------
CREATE TABLE `augur_depa_match_samples` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tba_event_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `season_year` smallint unsigned NOT NULL,
  `tba_match_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comp_level` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'qm',
  `match_number` smallint unsigned NOT NULL,
  `defending_alliance` enum('Red','Blue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `team1` int unsigned NOT NULL,
  `team2` int unsigned NOT NULL,
  `team3` int unsigned NOT NULL,
  `opponent_team1` int unsigned NOT NULL,
  `opponent_team2` int unsigned NOT NULL,
  `opponent_team3` int unsigned NOT NULL,
  `expected_defending_score` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `expected_defending_source` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `expected_opponent_score` decimal(12,4) NOT NULL,
  `expected_opponent_source` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `actual_opponent_score` decimal(12,4) NOT NULL,
  `raw_suppression` decimal(12,4) NOT NULL,
  `model_version` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_augur_depa_match_alliance` (`tba_match_key`,`defending_alliance`),
  KEY `idx_augur_depa_sample_year_event` (`season_year`,`tba_event_key`),
  KEY `idx_augur_depa_sample_event_match` (`tba_event_key`,`match_number`,`defending_alliance`),
  CONSTRAINT `fk_augur_depa_sample_event` FOREIGN KEY (`tba_event_key`) REFERENCES `augur_epa_archive_events` (`tba_event_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_depa_network_match_evidence
-- ------------------------------------------------------------------
CREATE TABLE `augur_depa_network_match_evidence` (
  `tba_match_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tba_event_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `season_year` smallint unsigned NOT NULL,
  `match_number` smallint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `alliance` enum('Red','Blue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `raw_depa` decimal(12,4) NOT NULL,
  `expected_alliance_score` decimal(12,4) DEFAULT NULL,
  `expected_opponent_score` decimal(12,4) DEFAULT NULL,
  `actual_opponent_score` decimal(12,4) DEFAULT NULL,
  `defense_status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'None',
  `defense_actions` smallint unsigned NOT NULL DEFAULT '0',
  `defense_failures` smallint unsigned NOT NULL DEFAULT '0',
  `defense_attempts` smallint unsigned NOT NULL DEFAULT '0',
  `spot_great_defense` smallint unsigned NOT NULL DEFAULT '0',
  `spot_weak_defense` smallint unsigned NOT NULL DEFAULT '0',
  `observing_orgs` smallint unsigned NOT NULL DEFAULT '0',
  `verified_orgs` smallint unsigned NOT NULL DEFAULT '0',
  `possible_orgs` smallint unsigned NOT NULL DEFAULT '0',
  `model_version` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`tba_match_key`,`frc_team_number`),
  KEY `idx_augur_depa_network_event_team` (`tba_event_key`,`frc_team_number`),
  KEY `idx_augur_depa_network_year_team` (`season_year`,`frc_team_number`),
  KEY `idx_augur_depa_network_status` (`season_year`,`defense_status`),
  CONSTRAINT `fk_augur_depa_network_event` FOREIGN KEY (`tba_event_key`) REFERENCES `augur_epa_archive_events` (`tba_event_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_depa_public_event_ratings
-- ------------------------------------------------------------------
CREATE TABLE `augur_depa_public_event_ratings` (
  `tba_event_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `season_year` smallint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `macro_depa` decimal(12,6) NOT NULL DEFAULT '0.000000',
  `matches_played` smallint unsigned NOT NULL DEFAULT '0',
  `alliance_rows` smallint unsigned NOT NULL DEFAULT '0',
  `baseline_source` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public_epa',
  `neptune_depa` decimal(12,6) DEFAULT NULL,
  `verified_defense_matches` smallint unsigned NOT NULL DEFAULT '0',
  `possible_defense_matches` smallint unsigned NOT NULL DEFAULT '0',
  `observed_matches` smallint unsigned NOT NULL DEFAULT '0',
  `defense_actions` int unsigned NOT NULL DEFAULT '0',
  `defense_failures` int unsigned NOT NULL DEFAULT '0',
  `defense_attempts` int unsigned NOT NULL DEFAULT '0',
  `spot_great_defense` smallint unsigned NOT NULL DEFAULT '0',
  `spot_weak_defense` smallint unsigned NOT NULL DEFAULT '0',
  `contributing_orgs` smallint unsigned NOT NULL DEFAULT '0',
  `confidence_score` decimal(6,2) NOT NULL DEFAULT '0.00',
  `confidence_label` enum('Low','Medium','High') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Low',
  `validation_label` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'No defense observed',
  `source_label` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public baseline only',
  `model_version` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`tba_event_key`,`frc_team_number`),
  KEY `idx_augur_depa_public_year_team` (`season_year`,`frc_team_number`),
  KEY `idx_augur_depa_public_year_rating` (`season_year`,`macro_depa`),
  CONSTRAINT `fk_augur_depa_public_event` FOREIGN KEY (`tba_event_key`) REFERENCES `augur_epa_archive_events` (`tba_event_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_depa_public_season_ratings
-- ------------------------------------------------------------------
CREATE TABLE `augur_depa_public_season_ratings` (
  `season_year` smallint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `public_depa` decimal(12,6) DEFAULT NULL,
  `public_matches` smallint unsigned NOT NULL DEFAULT '0',
  `neptune_depa` decimal(12,6) DEFAULT NULL,
  `verified_defense_matches` smallint unsigned NOT NULL DEFAULT '0',
  `possible_defense_matches` smallint unsigned NOT NULL DEFAULT '0',
  `observed_matches` smallint unsigned NOT NULL DEFAULT '0',
  `defense_actions` int unsigned NOT NULL DEFAULT '0',
  `defense_failures` int unsigned NOT NULL DEFAULT '0',
  `defense_attempts` int unsigned NOT NULL DEFAULT '0',
  `spot_great_defense` smallint unsigned NOT NULL DEFAULT '0',
  `spot_weak_defense` smallint unsigned NOT NULL DEFAULT '0',
  `contributing_orgs` smallint unsigned NOT NULL DEFAULT '0',
  `events_observed` smallint unsigned NOT NULL DEFAULT '0',
  `rated_events` smallint unsigned NOT NULL DEFAULT '0',
  `confidence_score` decimal(6,2) NOT NULL DEFAULT '0.00',
  `confidence_label` enum('Low','Medium','High') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Low',
  `validation_label` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'No defense observed',
  `source_label` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'network scouting',
  `last_event_key` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_version` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`season_year`,`frc_team_number`),
  KEY `idx_augur_depa_season_rating` (`season_year`,`neptune_depa`),
  KEY `fk_augur_depa_season_last_event` (`last_event_key`),
  CONSTRAINT `fk_augur_depa_season_last_event` FOREIGN KEY (`last_event_key`) REFERENCES `augur_epa_archive_events` (`tba_event_key`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_depa_team_match_evidence
-- ------------------------------------------------------------------
CREATE TABLE `augur_depa_team_match_evidence` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `match_id` bigint unsigned NOT NULL,
  `tba_event_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tba_match_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `season_year` smallint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `alliance` enum('Red','Blue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `raw_alliance_suppression` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `expected_alliance_score` decimal(12,4) DEFAULT NULL,
  `expected_opponent_score` decimal(12,4) DEFAULT NULL,
  `actual_opponent_score` decimal(12,4) DEFAULT NULL,
  `defense_actions` smallint unsigned NOT NULL DEFAULT '0',
  `spot_great_defense` smallint unsigned NOT NULL DEFAULT '0',
  `spot_weak_defense` smallint unsigned NOT NULL DEFAULT '0',
  `evidence_score` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `defense_status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'None',
  `baseline_suppression` decimal(12,4) DEFAULT NULL,
  `adjusted_suppression` decimal(12,4) DEFAULT NULL,
  `context_control_count` smallint unsigned NOT NULL DEFAULT '0',
  `context_source` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `context_distance_avg` decimal(12,4) DEFAULT NULL,
  `attribution_weight` decimal(10,6) NOT NULL DEFAULT '0.000000',
  `credited_suppression` decimal(12,4) DEFAULT NULL,
  `model_version` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_augur_depa_org_match_team` (`organization_id`,`match_id`,`frc_team_number`),
  KEY `idx_augur_depa_evidence_org_event_team` (`organization_id`,`event_id`,`frc_team_number`),
  KEY `idx_augur_depa_evidence_tba` (`tba_match_key`,`frc_team_number`),
  KEY `fk_augur_depa_evidence_event` (`event_id`),
  KEY `fk_augur_depa_evidence_match` (`match_id`),
  CONSTRAINT `fk_augur_depa_evidence_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_augur_depa_evidence_match` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_augur_depa_evidence_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_epa_alliance_samples
-- ------------------------------------------------------------------
CREATE TABLE `augur_epa_alliance_samples` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tba_event_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `season_year` smallint unsigned NOT NULL,
  `tba_match_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comp_level` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'qm',
  `set_number` smallint unsigned NOT NULL DEFAULT '1',
  `match_number` smallint unsigned NOT NULL,
  `actual_time` bigint unsigned DEFAULT NULL,
  `alliance` enum('Red','Blue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `team1` int unsigned DEFAULT NULL,
  `team2` int unsigned DEFAULT NULL,
  `team3` int unsigned DEFAULT NULL,
  `team_keys_json` text COLLATE utf8mb4_unicode_ci,
  `official_score` decimal(10,3) NOT NULL DEFAULT '0.000',
  `modeled_score` decimal(10,3) NOT NULL DEFAULT '0.000',
  `auto_score` decimal(10,3) DEFAULT NULL,
  `teleop_score` decimal(10,3) DEFAULT NULL,
  `endgame_score` decimal(10,3) DEFAULT NULL,
  `score_breakdown_json` mediumtext COLLATE utf8mb4_unicode_ci,
  `source_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_augur_epa_sample_match_alliance` (`tba_match_key`,`alliance`),
  KEY `idx_augur_epa_sample_event_match` (`tba_event_key`,`match_number`,`alliance`),
  KEY `idx_augur_epa_sample_year` (`season_year`,`tba_event_key`),
  CONSTRAINT `fk_augur_epa_sample_event` FOREIGN KEY (`tba_event_key`) REFERENCES `augur_epa_archive_events` (`tba_event_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_epa_archive_events
-- ------------------------------------------------------------------
CREATE TABLE `augur_epa_archive_events` (
  `tba_event_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `season_year` smallint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `short_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_code` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_type` smallint DEFAULT NULL,
  `district_key` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_week` smallint DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `city` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_prov` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'discovered',
  `is_complete` tinyint(1) NOT NULL DEFAULT '0',
  `qual_match_count` int unsigned NOT NULL DEFAULT '0',
  `alliance_sample_count` int unsigned NOT NULL DEFAULT '0',
  `auto_sample_count` int unsigned NOT NULL DEFAULT '0',
  `teleop_sample_count` int unsigned NOT NULL DEFAULT '0',
  `endgame_sample_count` int unsigned NOT NULL DEFAULT '0',
  `source_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_fetched_at` datetime DEFAULT NULL,
  `ratings_calculated_at` datetime DEFAULT NULL,
  `model_version` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tba_event_key`),
  KEY `idx_augur_epa_archive_event_year` (`season_year`,`start_date`,`tba_event_key`),
  KEY `idx_augur_epa_archive_event_status` (`season_year`,`source_status`,`source_fetched_at`),
  KEY `idx_augur_epa_archive_event_complete` (`is_complete`,`season_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_epa_archive_years
-- ------------------------------------------------------------------
CREATE TABLE `augur_epa_archive_years` (
  `season_year` smallint unsigned NOT NULL,
  `source_status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `event_count` int unsigned NOT NULL DEFAULT '0',
  `source_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_discovered_at` datetime DEFAULT NULL,
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`season_year`),
  KEY `idx_augur_epa_archive_year_status` (`source_status`,`last_discovered_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_epa_event_ratings
-- ------------------------------------------------------------------
CREATE TABLE `augur_epa_event_ratings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tba_event_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `season_year` smallint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `rating` decimal(10,3) NOT NULL DEFAULT '0.000',
  `auto_rating` decimal(10,3) DEFAULT NULL,
  `teleop_rating` decimal(10,3) DEFAULT NULL,
  `endgame_rating` decimal(10,3) DEFAULT NULL,
  `trend` decimal(10,3) NOT NULL DEFAULT '0.000',
  `sigma` decimal(10,3) NOT NULL DEFAULT '0.000',
  `confidence` decimal(6,2) NOT NULL DEFAULT '0.00',
  `matches_played` smallint unsigned NOT NULL DEFAULT '0',
  `wins` smallint unsigned NOT NULL DEFAULT '0',
  `losses` smallint unsigned NOT NULL DEFAULT '0',
  `ties` smallint unsigned NOT NULL DEFAULT '0',
  `model_version` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_augur_epa_event_team` (`tba_event_key`,`frc_team_number`),
  KEY `idx_augur_epa_event_rank` (`tba_event_key`,`rating`),
  KEY `idx_augur_epa_event_year_team` (`season_year`,`frc_team_number`,`tba_event_key`),
  CONSTRAINT `fk_augur_epa_event_rating_event` FOREIGN KEY (`tba_event_key`) REFERENCES `augur_epa_archive_events` (`tba_event_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_epa_match_ratings
-- ------------------------------------------------------------------
CREATE TABLE `augur_epa_match_ratings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tba_event_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `season_year` smallint unsigned NOT NULL,
  `tba_match_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `match_number` smallint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `alliance` enum('Red','Blue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `pre_rating` decimal(10,3) NOT NULL,
  `post_rating` decimal(10,3) NOT NULL,
  `rating_delta` decimal(10,3) NOT NULL DEFAULT '0.000',
  `pre_auto_rating` decimal(10,3) DEFAULT NULL,
  `post_auto_rating` decimal(10,3) DEFAULT NULL,
  `pre_teleop_rating` decimal(10,3) DEFAULT NULL,
  `post_teleop_rating` decimal(10,3) DEFAULT NULL,
  `pre_endgame_rating` decimal(10,3) DEFAULT NULL,
  `post_endgame_rating` decimal(10,3) DEFAULT NULL,
  `pre_sigma` decimal(10,3) NOT NULL DEFAULT '0.000',
  `post_sigma` decimal(10,3) NOT NULL DEFAULT '0.000',
  `model_version` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_augur_epa_match_team` (`tba_match_key`,`frc_team_number`),
  KEY `idx_augur_epa_match_history` (`tba_event_key`,`frc_team_number`,`match_number`),
  KEY `idx_augur_epa_match_year_team` (`season_year`,`frc_team_number`,`tba_event_key`,`match_number`),
  CONSTRAINT `fk_augur_epa_match_rating_event` FOREIGN KEY (`tba_event_key`) REFERENCES `augur_epa_archive_events` (`tba_event_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_epa_phase_maps
-- ------------------------------------------------------------------
CREATE TABLE `augur_epa_phase_maps` (
  `season_year` smallint unsigned NOT NULL,
  `mapping_json` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`season_year`),
  KEY `idx_augur_epa_phase_maps_enabled` (`enabled`,`season_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_epa_season_ratings
-- ------------------------------------------------------------------
CREATE TABLE `augur_epa_season_ratings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `season_year` smallint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `rating` decimal(10,3) NOT NULL DEFAULT '0.000',
  `auto_rating` decimal(10,3) DEFAULT NULL,
  `teleop_rating` decimal(10,3) DEFAULT NULL,
  `endgame_rating` decimal(10,3) DEFAULT NULL,
  `trend` decimal(10,3) NOT NULL DEFAULT '0.000',
  `sigma` decimal(10,3) NOT NULL DEFAULT '0.000',
  `confidence` decimal(6,2) NOT NULL DEFAULT '0.00',
  `events_played` smallint unsigned NOT NULL DEFAULT '0',
  `matches_played` smallint unsigned NOT NULL DEFAULT '0',
  `wins` smallint unsigned NOT NULL DEFAULT '0',
  `losses` smallint unsigned NOT NULL DEFAULT '0',
  `ties` smallint unsigned NOT NULL DEFAULT '0',
  `last_event_key` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_version` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_augur_epa_season_team` (`season_year`,`frc_team_number`),
  KEY `idx_augur_epa_season_rank` (`season_year`,`rating`),
  KEY `idx_augur_epa_season_team_history` (`frc_team_number`,`season_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_epa_team_directory
-- ------------------------------------------------------------------
CREATE TABLE `augur_epa_team_directory` (
  `season_year` smallint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `tba_team_key` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nickname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` text COLLATE utf8mb4_unicode_ci,
  `city` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_prov` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rookie_year` smallint unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`season_year`,`frc_team_number`),
  KEY `idx_augur_epa_team_directory_team` (`frc_team_number`,`season_year`),
  KEY `idx_augur_epa_team_directory_country` (`season_year`,`country`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_opr_event_ratings
-- ------------------------------------------------------------------
CREATE TABLE `augur_opr_event_ratings` (
  `tba_event_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `season_year` smallint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `event_opr` decimal(12,6) NOT NULL,
  `matches_played` smallint unsigned NOT NULL DEFAULT '0',
  `alliance_rows` smallint unsigned NOT NULL DEFAULT '0',
  `model_version` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`tba_event_key`,`frc_team_number`),
  KEY `idx_augur_opr_season_team` (`season_year`,`frc_team_number`),
  KEY `idx_augur_opr_season_rating` (`season_year`,`event_opr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- augur_tag_event_ratings
-- ------------------------------------------------------------------
CREATE TABLE `augur_tag_event_ratings` (
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `tba_event_key` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `season_year` smallint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `matches_with_tag_data` smallint unsigned NOT NULL DEFAULT '0',
  `matches_with_score_share` smallint unsigned NOT NULL DEFAULT '0',
  `tag_points_avg` decimal(12,4) DEFAULT NULL,
  `tag_points_recent` decimal(12,4) DEFAULT NULL,
  `tag_points_high` decimal(12,4) DEFAULT NULL,
  `tag_points_trend` decimal(12,4) DEFAULT NULL,
  `public_epa` decimal(12,4) DEFAULT NULL,
  `neptune_epa` decimal(12,4) DEFAULT NULL,
  `public_depa` decimal(12,4) DEFAULT NULL,
  `tag_defense_depa` decimal(12,4) DEFAULT NULL,
  `possible_defense_depa` decimal(12,4) DEFAULT NULL,
  `nondefense_suppression` decimal(12,4) DEFAULT NULL,
  `neptune_depa` decimal(12,4) DEFAULT NULL,
  `verified_defense_matches` smallint unsigned NOT NULL DEFAULT '0',
  `possible_defense_matches` smallint unsigned NOT NULL DEFAULT '0',
  `no_defense_matches` smallint unsigned NOT NULL DEFAULT '0',
  `unknown_defense_matches` smallint unsigned NOT NULL DEFAULT '0',
  `offense_confidence` decimal(6,2) NOT NULL DEFAULT '0.00',
  `defense_confidence` decimal(6,2) NOT NULL DEFAULT '0.00',
  `model_version` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`organization_id`,`event_id`,`frc_team_number`),
  KEY `idx_augur_tag_event_epa` (`organization_id`,`event_id`,`neptune_epa`),
  KEY `idx_augur_tag_event_depa` (`organization_id`,`event_id`,`neptune_depa`),
  KEY `idx_augur_tag_year_team` (`organization_id`,`season_year`,`frc_team_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- backup_scout_sessions_event10_pre_prosper
-- ------------------------------------------------------------------
CREATE TABLE `backup_scout_sessions_event10_pre_prosper` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `match_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `scout_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `alliance` enum('Red','Blue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `station` tinyint unsigned DEFAULT NULL,
  `field_id` smallint unsigned NOT NULL DEFAULT '1',
  `match_run_number` smallint unsigned NOT NULL DEFAULT '1',
  `status` enum('assigned','connected','scouting','submitted','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'assigned',
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `idx_ss_live` (`organization_id`,`match_id`,`status`),
  KEY `fk_ss_event` (`event_id`),
  KEY `fk_ss_match` (`match_id`),
  KEY `fk_ss_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- backup_scouting_actions_event10_pre_prosper
-- ------------------------------------------------------------------
CREATE TABLE `backup_scouting_actions_event10_pre_prosper` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `organization_id` bigint unsigned NOT NULL,
  `owner_team_id` bigint unsigned DEFAULT NULL,
  `event_id` bigint unsigned NOT NULL,
  `match_id` bigint unsigned NOT NULL,
  `scout_session_id` bigint unsigned DEFAULT NULL,
  `game_id` bigint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `alliance` enum('Red','Blue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_code` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_name` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result` enum('Success','Failure','Neutral') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Neutral',
  `points` decimal(8,2) NOT NULL DEFAULT '0.00',
  `match_time_sec` smallint unsigned DEFAULT NULL,
  `match_run_number` smallint unsigned NOT NULL DEFAULT '1',
  `phase` enum('pre_match','auton','teleop','endgame','post_match','unknown') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `source` enum('scout','admin','legacy_import','shared') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scout',
  `source_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `legacy_source` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_id` bigint DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `deletion_reason` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uq_legacy_row` (`organization_id`,`legacy_source`,`legacy_id`),
  KEY `idx_action_robot` (`organization_id`,`event_id`,`frc_team_number`),
  KEY `idx_action_match` (`organization_id`,`match_id`),
  KEY `idx_action_live` (`organization_id`,`match_id`,`match_run_number`,`deleted_at`),
  KEY `idx_action_code` (`game_id`,`action_code`),
  KEY `fk_sa_owner_team` (`owner_team_id`),
  KEY `fk_sa_event` (`event_id`),
  KEY `fk_sa_match` (`match_id`),
  KEY `fk_sa_session` (`scout_session_id`),
  KEY `fk_sa_user` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- event_teams
-- ------------------------------------------------------------------
CREATE TABLE `event_teams` (
  `event_id` bigint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `nickname` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_prov` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tba_team_key` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`event_id`,`frc_team_number`),
  CONSTRAINT `fk_eventteams_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- events
-- ------------------------------------------------------------------
CREATE TABLE `events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `game_id` bigint unsigned NOT NULL,
  `game_revision_id` bigint unsigned NOT NULL,
  `name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_code` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tba_event_key` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `event_status` enum('planned','pit_open','schedule_ready','running','complete') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planned',
  `is_current` tinyint(1) NOT NULL DEFAULT '0',
  `roster_synced_at` datetime DEFAULT NULL,
  `schedule_synced_at` datetime DEFAULT NULL,
  `last_tba_sync_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_event` (`organization_id`,`game_id`,`name`),
  KEY `idx_event_tba` (`tba_event_key`),
  KEY `fk_events_game` (`game_id`),
  KEY `idx_events_game_revision` (`game_revision_id`),
  CONSTRAINT `fk_events_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_events_game_revision` FOREIGN KEY (`game_revision_id`) REFERENCES `game_revisions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_events_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- game_config_shares
-- ------------------------------------------------------------------
CREATE TABLE `game_config_shares` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `game_id` bigint unsigned NOT NULL,
  `recipient_organization_id` bigint unsigned NOT NULL,
  `shared_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_game_config_share` (`game_id`,`recipient_organization_id`),
  KEY `idx_game_config_recipient` (`recipient_organization_id`),
  KEY `idx_game_config_shared_by` (`shared_by`),
  CONSTRAINT `fk_game_config_share_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_game_config_share_recipient` FOREIGN KEY (`recipient_organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_game_config_share_user` FOREIGN KEY (`shared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- game_revisions
-- ------------------------------------------------------------------
CREATE TABLE `game_revisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `game_id` bigint unsigned NOT NULL,
  `revision_number` int unsigned NOT NULL,
  `status` enum('draft','published') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `match_config_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `pit_config_json` longtext COLLATE utf8mb4_unicode_ci,
  `pre_scout_config_json` longtext COLLATE utf8mb4_unicode_ci,
  `field_image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checksum` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `published_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `published_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_game_revision` (`game_id`,`revision_number`),
  KEY `idx_game_revision_org` (`organization_id`),
  KEY `idx_game_revision_status` (`game_id`,`status`,`revision_number`),
  KEY `idx_game_revision_created_by` (`created_by`),
  KEY `idx_game_revision_published_by` (`published_by`),
  CONSTRAINT `fk_game_revision_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_game_revision_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_game_revision_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_game_revision_published_by` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- games
-- ------------------------------------------------------------------
CREATE TABLE `games` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `season_year` smallint unsigned NOT NULL,
  `slug` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `json_filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `pit_config_json` longtext COLLATE utf8mb4_unicode_ci,
  `pre_scout_config_json` longtext COLLATE utf8mb4_unicode_ci,
  `current_revision_id` bigint unsigned DEFAULT NULL,
  `draft_revision_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_game_org_year_slug` (`organization_id`,`season_year`,`slug`),
  KEY `fk_game_creator` (`created_by`),
  KEY `idx_games_org` (`organization_id`),
  KEY `idx_games_current_revision` (`current_revision_id`),
  KEY `idx_games_draft_revision` (`draft_revision_id`),
  CONSTRAINT `fk_game_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_games_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- match_strategies
-- ------------------------------------------------------------------
CREATE TABLE `match_strategies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `match_id` bigint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `strategy_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_match_strategy` (`organization_id`,`match_id`,`frc_team_number`),
  KEY `idx_match_strategy_event_team` (`organization_id`,`event_id`,`frc_team_number`),
  KEY `fk_match_strategy_event` (`event_id`),
  KEY `fk_match_strategy_match` (`match_id`),
  KEY `fk_match_strategy_created_by` (`created_by`),
  KEY `fk_match_strategy_updated_by` (`updated_by`),
  CONSTRAINT `fk_match_strategy_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_match_strategy_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_match_strategy_match` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_match_strategy_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_match_strategy_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- match_teams
-- ------------------------------------------------------------------
CREATE TABLE `match_teams` (
  `match_id` bigint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `alliance` enum('Red','Blue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `station` tinyint unsigned NOT NULL,
  PRIMARY KEY (`match_id`,`alliance`,`station`),
  KEY `idx_mt_team` (`frc_team_number`),
  CONSTRAINT `fk_matchteams_match` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- matches
-- ------------------------------------------------------------------
CREATE TABLE `matches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `game_id` bigint unsigned NOT NULL,
  `tba_match_key` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comp_level` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'qm',
  `set_number` smallint unsigned NOT NULL DEFAULT '1',
  `match_number` smallint unsigned NOT NULL,
  `field_id` smallint unsigned NOT NULL DEFAULT '1',
  `scheduled_time` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `paused_at` datetime DEFAULT NULL,
  `total_pause_seconds` int unsigned NOT NULL DEFAULT '0',
  `run_number` smallint unsigned NOT NULL DEFAULT '1',
  `red_score` smallint DEFAULT NULL,
  `blue_score` smallint DEFAULT NULL,
  `winning_alliance` enum('Red','Blue','Tie','Unknown') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unknown',
  `state` enum('scheduled','ready','running','paused','ended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_match` (`organization_id`,`event_id`,`comp_level`,`set_number`,`match_number`,`field_id`),
  KEY `idx_match_state` (`organization_id`,`field_id`,`state`),
  KEY `fk_matches_event` (`event_id`),
  KEY `fk_matches_game` (`game_id`),
  CONSTRAINT `fk_matches_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_matches_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_matches_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- neptune_auth_invites
-- ------------------------------------------------------------------
CREATE TABLE `neptune_auth_invites` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invite_email` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scout',
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `used_by_user_id` bigint unsigned DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_neptune_auth_invite_token` (`token_hash`),
  KEY `idx_neptune_auth_invite_org_status` (`organization_id`,`expires_at`,`used_at`,`revoked_at`),
  KEY `fk_neptune_auth_invite_creator` (`created_by_user_id`),
  KEY `fk_neptune_auth_invite_used_by` (`used_by_user_id`),
  CONSTRAINT `fk_neptune_auth_invite_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_neptune_auth_invite_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_neptune_auth_invite_used_by` FOREIGN KEY (`used_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- neptune_auth_rate_limits
-- ------------------------------------------------------------------
CREATE TABLE `neptune_auth_rate_limits` (
  `bucket_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `window_started_at` datetime NOT NULL,
  `blocked_until` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`bucket_hash`),
  KEY `idx_narl_action` (`action`),
  KEY `idx_narl_updated` (`updated_at`),
  KEY `idx_narl_blocked` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- neptune_migrations
-- ------------------------------------------------------------------
CREATE TABLE `neptune_migrations` (
  `migration_key` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `package` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`migration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- neptune_tba_live_state
-- ------------------------------------------------------------------
CREATE TABLE `neptune_tba_live_state` (
  `organization_id` bigint unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `last_live_attempt_at` datetime DEFAULT NULL,
  `last_live_success_at` datetime DEFAULT NULL,
  `last_live_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`organization_id`),
  KEY `fk_tba_live_user` (`updated_by`),
  CONSTRAINT `fk_tba_live_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tba_live_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- neptune_tba_schedule_settings
-- ------------------------------------------------------------------
CREATE TABLE `neptune_tba_schedule_settings` (
  `id` tinyint unsigned NOT NULL DEFAULT '1',
  `live_interval_minutes` smallint unsigned NOT NULL DEFAULT '2',
  `weekly_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `weekly_day` tinyint unsigned NOT NULL DEFAULT '1',
  `weekly_hour` tinyint unsigned NOT NULL DEFAULT '3',
  `weekly_minute` tinyint unsigned NOT NULL DEFAULT '0',
  `last_weekly_attempt_at` datetime DEFAULT NULL,
  `last_weekly_success_at` datetime DEFAULT NULL,
  `last_weekly_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_tba_schedule_user` (`updated_by`),
  CONSTRAINT `fk_tba_schedule_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- offline_sync_runs
-- ------------------------------------------------------------------
CREATE TABLE `offline_sync_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `destination_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `started_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `status` enum('running','complete','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `matches_sent` int unsigned NOT NULL DEFAULT '0',
  `sessions_sent` int unsigned NOT NULL DEFAULT '0',
  `actions_sent` int unsigned NOT NULL DEFAULT '0',
  `response_json` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_offsync_org_time` (`organization_id`,`created_at`),
  CONSTRAINT `fk_offsync_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- organization_themes
-- ------------------------------------------------------------------
CREATE TABLE `organization_themes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `settings_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_theme_slug` (`organization_id`,`slug`),
  KEY `idx_org_theme_org` (`organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- organization_ui_settings
-- ------------------------------------------------------------------
CREATE TABLE `organization_ui_settings` (
  `organization_id` bigint unsigned NOT NULL,
  `active_theme_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'builtin',
  `active_theme_key` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'neptune-default',
  `updated_by` bigint unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- organizations
-- ------------------------------------------------------------------
CREATE TABLE `organizations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform_status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `suspended_at` datetime DEFAULT NULL,
  `suspended_by` bigint unsigned DEFAULT NULL,
  `suspension_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accent_color` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `google_signin_mode` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'optional',
  `google_workspace_domain` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_auto_provision` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_organizations_platform_status` (`platform_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- pit_match_board_match_state
-- ------------------------------------------------------------------
CREATE TABLE `pit_match_board_match_state` (
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `match_id` bigint unsigned NOT NULL,
  `queue_lead_minutes` smallint unsigned NOT NULL DEFAULT '10',
  `queued` tinyint(1) NOT NULL DEFAULT '0',
  `checklist_json` longtext COLLATE utf8mb4_unicode_ci,
  `match_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`organization_id`,`event_id`,`frc_team_number`,`match_id`),
  KEY `idx_pit_board_match` (`organization_id`,`event_id`,`match_id`),
  KEY `idx_pit_board_match_updated_by` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- pit_match_board_state
-- ------------------------------------------------------------------
CREATE TABLE `pit_match_board_state` (
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `robot_status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unset',
  `battery_label` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bumper_color` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inspection_ready` tinyint(1) NOT NULL DEFAULT '0',
  `drive_team_ready` tinyint(1) NOT NULL DEFAULT '0',
  `pit_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`organization_id`,`event_id`,`frc_team_number`),
  KEY `idx_pit_board_event` (`organization_id`,`event_id`),
  KEY `idx_pit_board_updated_by` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- pit_scouting
-- ------------------------------------------------------------------
CREATE TABLE `pit_scouting` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `owner_team_id` bigint unsigned DEFAULT NULL,
  `event_id` bigint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `submitted_by` bigint unsigned DEFAULT NULL,
  `data_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('in_progress','complete') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pit` (`organization_id`,`event_id`,`frc_team_number`),
  KEY `fk_pit_owner` (`owner_team_id`),
  KEY `fk_pit_event` (`event_id`),
  KEY `fk_pit_user` (`submitted_by`),
  CONSTRAINT `fk_pit_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pit_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pit_owner` FOREIGN KEY (`owner_team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pit_user` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- pit_scouting_photos
-- ------------------------------------------------------------------
CREATE TABLE `pit_scouting_photos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pit_scouting_id` bigint unsigned NOT NULL,
  `organization_id` bigint unsigned NOT NULL,
  `category` enum('front','back','left','right','mechanism','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `caption` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pit_photo_scouting` (`pit_scouting_id`),
  KEY `idx_pit_photo_org` (`organization_id`),
  KEY `fk_pit_photo_user` (`uploaded_by`),
  CONSTRAINT `fk_pit_photo_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pit_photo_scouting` FOREIGN KEY (`pit_scouting_id`) REFERENCES `pit_scouting` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pit_photo_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- pre_scouting
-- ------------------------------------------------------------------
CREATE TABLE `pre_scouting` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `game_id` bigint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `submitted_by` bigint unsigned DEFAULT NULL,
  `contact_status` enum('not_started','contacted','received','no_response','unavailable') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_started',
  `contact_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_method` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_details` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contacted_at` datetime DEFAULT NULL,
  `data_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('in_progress','complete') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `inherited_from_event_id` bigint unsigned DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pre_scout` (`organization_id`,`event_id`,`frc_team_number`),
  KEY `idx_pre_scout_season` (`organization_id`,`game_id`,`frc_team_number`),
  KEY `idx_pre_scout_status` (`organization_id`,`event_id`,`status`,`contact_status`),
  KEY `fk_pre_event` (`event_id`),
  KEY `fk_pre_game` (`game_id`),
  KEY `fk_pre_user` (`submitted_by`),
  KEY `fk_pre_inherited_event` (`inherited_from_event_id`),
  CONSTRAINT `fk_pre_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pre_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pre_inherited_event` FOREIGN KEY (`inherited_from_event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pre_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pre_user` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- robot_recall_answers
-- ------------------------------------------------------------------
CREATE TABLE `robot_recall_answers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `player_id` bigint unsigned NOT NULL,
  `selected_team_number` int unsigned NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT '0',
  `response_ms` int unsigned NOT NULL DEFAULT '0',
  `points_awarded` smallint unsigned NOT NULL DEFAULT '0',
  `answered_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_robot_recall_answer` (`question_id`,`player_id`),
  KEY `idx_robot_recall_answer_session` (`session_id`,`question_id`),
  KEY `fk_robot_recall_answer_player` (`player_id`),
  CONSTRAINT `fk_robot_recall_answer_player` FOREIGN KEY (`player_id`) REFERENCES `robot_recall_players` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_robot_recall_answer_question` FOREIGN KEY (`question_id`) REFERENCES `robot_recall_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_robot_recall_answer_session` FOREIGN KEY (`session_id`) REFERENCES `robot_recall_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- robot_recall_competition_migrations
-- ------------------------------------------------------------------
CREATE TABLE `robot_recall_competition_migrations` (
  `organization_id` bigint unsigned NOT NULL,
  `migration_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`organization_id`,`migration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- robot_recall_players
-- ------------------------------------------------------------------
CREATE TABLE `robot_recall_players` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `nickname` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `score` int unsigned NOT NULL DEFAULT '0',
  `correct_count` smallint unsigned NOT NULL DEFAULT '0',
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_robot_recall_player_name` (`session_id`,`nickname`),
  UNIQUE KEY `uq_robot_recall_player_token` (`session_id`,`token_hash`),
  KEY `idx_robot_recall_player_score` (`session_id`,`score`,`correct_count`),
  CONSTRAINT `fk_robot_recall_player_session` FOREIGN KEY (`session_id`) REFERENCES `robot_recall_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- robot_recall_public_hosts
-- ------------------------------------------------------------------
CREATE TABLE `robot_recall_public_hosts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `organization_id` bigint unsigned NOT NULL,
  `service_user_id` bigint unsigned NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `creator_fingerprint` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rr_public_host_session` (`session_id`),
  UNIQUE KEY `uq_rr_public_host_token` (`token_hash`),
  KEY `idx_rr_public_host_expiry` (`expires_at`),
  KEY `idx_rr_public_host_fingerprint` (`creator_fingerprint`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- robot_recall_questions
-- ------------------------------------------------------------------
CREATE TABLE `robot_recall_questions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `sort_order` smallint unsigned NOT NULL,
  `correct_team_number` int unsigned NOT NULL,
  `correct_nickname` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `options_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_robot_recall_question_order` (`session_id`,`sort_order`),
  CONSTRAINT `fk_robot_recall_question_session` FOREIGN KEY (`session_id`) REFERENCES `robot_recall_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- robot_recall_round_links
-- ------------------------------------------------------------------
CREATE TABLE `robot_recall_round_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `from_session_id` bigint unsigned NOT NULL,
  `from_room_code` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_session_id` bigint unsigned NOT NULL,
  `to_room_code` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_robot_recall_round_from_session` (`from_session_id`),
  UNIQUE KEY `uq_robot_recall_round_from_room` (`from_room_code`),
  KEY `idx_robot_recall_round_to_session` (`to_session_id`),
  KEY `idx_robot_recall_round_org` (`organization_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- robot_recall_series_links
-- ------------------------------------------------------------------
CREATE TABLE `robot_recall_series_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `previous_session_id` bigint unsigned NOT NULL,
  `previous_room_code` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `next_session_id` bigint unsigned NOT NULL,
  `next_room_code` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rr_series_previous_session` (`previous_session_id`),
  KEY `idx_rr_series_previous_room` (`previous_room_code`),
  KEY `idx_rr_series_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- robot_recall_session_flags
-- ------------------------------------------------------------------
CREATE TABLE `robot_recall_session_flags` (
  `session_id` bigint unsigned NOT NULL,
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned DEFAULT NULL,
  `is_test` tinyint(1) NOT NULL DEFAULT '0',
  `play_mode` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'production',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`),
  KEY `idx_rr_flags_org_test` (`organization_id`,`is_test`),
  KEY `idx_rr_flags_org_event` (`organization_id`,`event_id`,`is_test`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- robot_recall_sessions
-- ------------------------------------------------------------------
CREATE TABLE `robot_recall_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `event_id` bigint unsigned DEFAULT NULL,
  `season_year` smallint unsigned NOT NULL,
  `source_mode` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'event',
  `room_code` char(6) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Robot Recall',
  `question_count` smallint unsigned NOT NULL DEFAULT '10',
  `question_seconds` smallint unsigned NOT NULL DEFAULT '15',
  `status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'lobby',
  `current_question_index` smallint unsigned NOT NULL DEFAULT '0',
  `question_started_at` datetime(6) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_robot_recall_room` (`room_code`),
  KEY `idx_robot_recall_org_created` (`organization_id`,`created_at`),
  KEY `idx_robot_recall_event` (`event_id`),
  KEY `fk_robot_recall_session_user` (`created_by`),
  CONSTRAINT `fk_robot_recall_session_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_robot_recall_session_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_robot_recall_session_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- robot_season_profiles
-- ------------------------------------------------------------------
CREATE TABLE `robot_season_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `game_id` bigint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `data_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `tba_json` longtext COLLATE utf8mb4_unicode_ci,
  `tba_updated_at` datetime DEFAULT NULL,
  `last_event_id` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_robot_season` (`organization_id`,`game_id`,`frc_team_number`),
  KEY `idx_robot_season_team` (`frc_team_number`,`game_id`),
  KEY `fk_rsp_game` (`game_id`),
  KEY `fk_rsp_event` (`last_event_id`),
  KEY `fk_rsp_user` (`updated_by`),
  CONSTRAINT `fk_rsp_event` FOREIGN KEY (`last_event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rsp_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rsp_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rsp_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- scout_access_codes
-- ------------------------------------------------------------------
CREATE TABLE `scout_access_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned DEFAULT NULL,
  `code_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_codes_org` (`organization_id`),
  KEY `fk_codes_event` (`event_id`),
  CONSTRAINT `fk_codes_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_codes_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- scout_sessions
-- ------------------------------------------------------------------
CREATE TABLE `scout_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `match_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `scout_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `alliance` enum('Red','Blue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `station` tinyint unsigned DEFAULT NULL,
  `field_id` smallint unsigned NOT NULL DEFAULT '1',
  `match_run_number` smallint unsigned NOT NULL DEFAULT '1',
  `status` enum('assigned','connected','scouting','submitted','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'assigned',
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `idx_ss_live` (`organization_id`,`match_id`,`status`),
  KEY `fk_ss_event` (`event_id`),
  KEY `fk_ss_match` (`match_id`),
  KEY `fk_ss_user` (`user_id`),
  CONSTRAINT `fk_ss_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ss_match` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ss_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ss_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- scouting_actions
-- ------------------------------------------------------------------
CREATE TABLE `scouting_actions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `organization_id` bigint unsigned NOT NULL,
  `owner_team_id` bigint unsigned DEFAULT NULL,
  `event_id` bigint unsigned NOT NULL,
  `match_id` bigint unsigned NOT NULL,
  `scout_session_id` bigint unsigned DEFAULT NULL,
  `game_id` bigint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `alliance` enum('Red','Blue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_code` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_name` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result` enum('Success','Failure','Neutral') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Neutral',
  `points` decimal(8,2) NOT NULL DEFAULT '0.00',
  `match_time_sec` smallint unsigned DEFAULT NULL,
  `match_run_number` smallint unsigned NOT NULL DEFAULT '1',
  `phase` enum('pre_match','auton','teleop','endgame','post_match','unknown') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `source` enum('scout','admin','legacy_import','shared') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scout',
  `source_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `legacy_source` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_id` bigint DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `deletion_reason` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `uq_legacy_row` (`organization_id`,`legacy_source`,`legacy_id`),
  KEY `idx_action_robot` (`organization_id`,`event_id`,`frc_team_number`),
  KEY `idx_action_match` (`organization_id`,`match_id`),
  KEY `idx_action_live` (`organization_id`,`match_id`,`match_run_number`,`deleted_at`),
  KEY `idx_action_code` (`game_id`,`action_code`),
  KEY `fk_sa_owner_team` (`owner_team_id`),
  KEY `fk_sa_event` (`event_id`),
  KEY `fk_sa_match` (`match_id`),
  KEY `fk_sa_session` (`scout_session_id`),
  KEY `fk_sa_user` (`created_by`),
  CONSTRAINT `fk_sa_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sa_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sa_match` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sa_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sa_owner_team` FOREIGN KEY (`owner_team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sa_session` FOREIGN KEY (`scout_session_id`) REFERENCES `scout_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sa_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- sharing_relationships
-- ------------------------------------------------------------------
CREATE TABLE `sharing_relationships` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `owner_team_id` bigint unsigned NOT NULL,
  `recipient_team_id` bigint unsigned NOT NULL,
  `status` enum('pending','active','declined','revoked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `share_match_data` tinyint(1) NOT NULL DEFAULT '1',
  `share_pit_data` tinyint(1) NOT NULL DEFAULT '0',
  `share_notes` tinyint(1) NOT NULL DEFAULT '0',
  `share_raw_actions` tinyint(1) NOT NULL DEFAULT '1',
  `share_analytics` tinyint(1) NOT NULL DEFAULT '1',
  `starts_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_share_pair` (`owner_team_id`,`recipient_team_id`),
  KEY `fk_share_recipient` (`recipient_team_id`),
  CONSTRAINT `fk_share_owner` FOREIGN KEY (`owner_team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_share_recipient` FOREIGN KEY (`recipient_team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- tag_scouting_match_data
-- ------------------------------------------------------------------
CREATE TABLE `tag_scouting_match_data` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `observation_id` bigint unsigned DEFAULT NULL,
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `match_id` bigint unsigned NOT NULL,
  `game_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `alliance` enum('Red','Blue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `station` tinyint unsigned DEFAULT NULL,
  `scoring_contribution` tinyint unsigned DEFAULT NULL,
  `tags_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `tag_weights_json` longtext COLLATE utf8mb4_unicode_ci,
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_impact_observer_robot` (`organization_id`,`match_id`,`user_id`,`frc_team_number`),
  UNIQUE KEY `uq_tag_match_observation` (`observation_id`),
  KEY `idx_impact_robot` (`organization_id`,`event_id`,`frc_team_number`),
  KEY `idx_impact_match` (`organization_id`,`match_id`),
  KEY `idx_impact_user` (`organization_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- tag_scouting_match_metrics
-- ------------------------------------------------------------------
CREATE TABLE `tag_scouting_match_metrics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `match_id` bigint unsigned NOT NULL,
  `tba_match_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comp_level` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'qm',
  `match_number` smallint unsigned NOT NULL DEFAULT '0',
  `frc_team_number` int unsigned NOT NULL,
  `alliance` enum('Red','Blue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `station` tinyint unsigned DEFAULT NULL,
  `observer_count` smallint unsigned NOT NULL DEFAULT '0',
  `synthetic_observer_count` smallint unsigned NOT NULL DEFAULT '0',
  `score_estimate_count` smallint unsigned NOT NULL DEFAULT '0',
  `raw_share_avg` decimal(8,3) DEFAULT NULL,
  `raw_share_median` decimal(8,3) DEFAULT NULL,
  `share_stddev` decimal(8,3) DEFAULT NULL,
  `alliance_score_robot_count` tinyint unsigned NOT NULL DEFAULT '0',
  `alliance_raw_share_total` decimal(8,3) DEFAULT NULL,
  `normalized_share` decimal(8,3) DEFAULT NULL,
  `effective_share` decimal(8,3) DEFAULT NULL,
  `official_score` decimal(10,3) DEFAULT NULL,
  `modeled_score` decimal(10,3) DEFAULT NULL,
  `estimated_official_points` decimal(10,3) DEFAULT NULL,
  `estimated_modeled_points` decimal(10,3) DEFAULT NULL,
  `estimated_points` decimal(10,3) DEFAULT NULL,
  `point_source` enum('modeled','official','none') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `tags_consensus_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `tag_weights_consensus_json` longtext COLLATE utf8mb4_unicode_ci,
  `defense_status` enum('Unknown','None','Possible','Verified') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unknown',
  `defense_support_count` smallint unsigned NOT NULL DEFAULT '0',
  `no_defense_support_count` smallint unsigned NOT NULL DEFAULT '0',
  `defense_strength` decimal(6,3) DEFAULT NULL,
  `raw_depa` decimal(12,4) DEFAULT NULL,
  `confidence_score` decimal(6,2) NOT NULL DEFAULT '0.00',
  `latest_observation_at` datetime DEFAULT NULL,
  `model_version` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tag_metric_robot_match` (`organization_id`,`match_id`,`frc_team_number`),
  KEY `idx_tag_metric_event_team` (`organization_id`,`event_id`,`frc_team_number`),
  KEY `idx_tag_metric_match` (`organization_id`,`match_id`),
  KEY `idx_tag_metric_depa` (`organization_id`,`event_id`,`defense_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- tag_scouting_media
-- ------------------------------------------------------------------
CREATE TABLE `tag_scouting_media` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `observation_id` bigint unsigned NOT NULL,
  `organization_id` bigint unsigned NOT NULL,
  `media_type` enum('photo','video') COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_relpath` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint unsigned NOT NULL DEFAULT '0',
  `width` int unsigned DEFAULT NULL,
  `height` int unsigned DEFAULT NULL,
  `duration_seconds` decimal(8,2) DEFAULT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_spot_media_obs` (`observation_id`),
  KEY `idx_spot_media_org` (`organization_id`,`created_at`),
  KEY `fk_spot_media_user` (`uploaded_by`),
  CONSTRAINT `fk_spot_media_obs` FOREIGN KEY (`observation_id`) REFERENCES `tag_scouting_observations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_spot_media_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_spot_media_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- tag_scouting_observation_tags
-- ------------------------------------------------------------------
CREATE TABLE `tag_scouting_observation_tags` (
  `observation_id` bigint unsigned NOT NULL,
  `tag_id` bigint unsigned NOT NULL,
  `weight` tinyint unsigned DEFAULT NULL,
  PRIMARY KEY (`observation_id`,`tag_id`),
  KEY `idx_spot_ot_tag` (`tag_id`),
  CONSTRAINT `fk_spot_ot_obs` FOREIGN KEY (`observation_id`) REFERENCES `tag_scouting_observations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_spot_ot_tag` FOREIGN KEY (`tag_id`) REFERENCES `tag_scouting_tags` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- tag_scouting_observations
-- ------------------------------------------------------------------
CREATE TABLE `tag_scouting_observations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `organization_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned DEFAULT NULL,
  `match_id` bigint unsigned DEFAULT NULL,
  `field_id` smallint unsigned DEFAULT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `context` enum('match','pit','team') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'team',
  `entry_mode` enum('auto','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `source_key` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `severity` enum('positive','info','warning','critical') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `status` enum('open','resolved') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `resolved_by` bigint unsigned DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolution_note` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_spot_observation_uuid` (`uuid`),
  UNIQUE KEY `uq_tag_observation_source` (`organization_id`,`source_key`),
  KEY `idx_spot_org_recent` (`organization_id`,`created_at`),
  KEY `idx_spot_org_team` (`organization_id`,`frc_team_number`,`created_at`),
  KEY `idx_spot_org_event` (`organization_id`,`event_id`,`created_at`),
  KEY `idx_spot_review` (`organization_id`,`status`,`severity`,`created_at`),
  KEY `fk_spot_obs_event` (`event_id`),
  KEY `fk_spot_obs_match` (`match_id`),
  KEY `fk_spot_obs_user` (`created_by`),
  KEY `fk_spot_obs_resolver` (`resolved_by`),
  CONSTRAINT `fk_spot_obs_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_spot_obs_match` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_spot_obs_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_spot_obs_resolver` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_spot_obs_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- tag_scouting_preferences
-- ------------------------------------------------------------------
CREATE TABLE `tag_scouting_preferences` (
  `organization_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned DEFAULT NULL,
  `field_id` smallint unsigned DEFAULT NULL,
  `auto_follow` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`organization_id`,`user_id`),
  KEY `fk_spot_pref_user` (`user_id`),
  KEY `fk_spot_pref_event` (`event_id`),
  CONSTRAINT `fk_spot_pref_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_spot_pref_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_spot_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- tag_scouting_tag_visibility
-- ------------------------------------------------------------------
CREATE TABLE `tag_scouting_tag_visibility` (
  `organization_id` bigint unsigned NOT NULL,
  `tag_id` bigint unsigned NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`organization_id`,`tag_id`),
  KEY `fk_spot_vis_tag` (`tag_id`),
  CONSTRAINT `fk_spot_vis_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_spot_vis_tag` FOREIGN KEY (`tag_id`) REFERENCES `tag_scouting_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- tag_scouting_tags
-- ------------------------------------------------------------------
CREATE TABLE `tag_scouting_tags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned DEFAULT NULL,
  `seed_key` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `label` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'General',
  `icon` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fa-solid fa-tag',
  `severity` enum('positive','info','warning','critical') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `match_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `pit_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `team_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '100',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_spot_seed` (`seed_key`),
  UNIQUE KEY `uq_spot_org_slug` (`organization_id`,`slug`),
  KEY `idx_spot_tags_visible` (`organization_id`,`active`,`category`,`sort_order`),
  KEY `fk_spot_tags_user` (`created_by`),
  CONSTRAINT `fk_spot_tags_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_spot_tags_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- tba_cache
-- ------------------------------------------------------------------
CREATE TABLE `tba_cache` (
  `cache_key` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `response_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `etag` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fetched_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`cache_key`),
  KEY `idx_tba_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- teams
-- ------------------------------------------------------------------
CREATE TABLE `teams` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `frc_team_number` int unsigned NOT NULL,
  `nickname` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `display_name` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tba_key` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accent_color` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_team` (`organization_id`,`frc_team_number`),
  KEY `idx_team_number` (`frc_team_number`),
  CONSTRAINT `fk_teams_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- training_attempts
-- ------------------------------------------------------------------
CREATE TABLE `training_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `display_name_snapshot` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username_snapshot` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role_snapshot` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `course_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `course_version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempt_number` int unsigned NOT NULL DEFAULT '1',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `question_count` smallint unsigned NOT NULL DEFAULT '0',
  `correct_count` smallint unsigned DEFAULT NULL,
  `score_percent` decimal(5,2) DEFAULT NULL,
  `passing_score` decimal(5,2) NOT NULL DEFAULT '85.00',
  `passed` tinyint(1) DEFAULT NULL,
  `question_ids_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `option_order_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `results_json` longtext COLLATE utf8mb4_unicode_ci,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  `duration_seconds` int unsigned DEFAULT NULL,
  `client_ip` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_training_org_course` (`organization_id`,`course_code`,`status`),
  KEY `idx_training_user_course` (`user_id`,`course_code`,`status`),
  KEY `idx_training_completed` (`organization_id`,`completed_at`),
  KEY `idx_training_passed` (`organization_id`,`course_code`,`passed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- user_teams
-- ------------------------------------------------------------------
CREATE TABLE `user_teams` (
  `user_id` bigint unsigned NOT NULL,
  `team_id` bigint unsigned NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`,`team_id`),
  KEY `fk_ut_team` (`team_id`),
  CONSTRAINT `fk_ut_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ut_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- users
-- ------------------------------------------------------------------
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint unsigned NOT NULL,
  `username` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_sub` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_email` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_linked_at` datetime DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('owner','admin','strategy','scout') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scout',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `last_login_at` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_username` (`organization_id`,`username`),
  UNIQUE KEY `uq_org_email` (`organization_id`,`email`),
  UNIQUE KEY `uq_org_google_sub` (`organization_id`,`google_sub`),
  CONSTRAINT `fk_users_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
