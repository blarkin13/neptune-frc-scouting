-- Neptune TBA scheduler state
-- Additive migration only: creates two new tables and does not alter scouting data.

CREATE TABLE IF NOT EXISTS `neptune_tba_schedule_settings` (
  `id` tinyint unsigned NOT NULL DEFAULT '1',
  `live_interval_minutes` smallint unsigned NOT NULL DEFAULT '2',
  `weekly_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `weekly_day` tinyint unsigned NOT NULL DEFAULT '1',
  `weekly_hour` tinyint unsigned NOT NULL DEFAULT '3',
  `weekly_minute` tinyint unsigned NOT NULL DEFAULT '0',
  `last_weekly_attempt_at` datetime DEFAULT NULL,
  `last_weekly_success_at` datetime DEFAULT NULL,
  `last_weekly_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_tba_schedule_user` (`updated_by`),
  CONSTRAINT `fk_tba_schedule_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `neptune_tba_schedule_settings`
  (`id`,`live_interval_minutes`,`weekly_enabled`,`weekly_day`,`weekly_hour`,`weekly_minute`)
VALUES (1,2,1,1,3,0)
ON DUPLICATE KEY UPDATE `id`=`id`;

CREATE TABLE IF NOT EXISTS `neptune_tba_live_state` (
  `organization_id` bigint unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `last_live_attempt_at` datetime DEFAULT NULL,
  `last_live_success_at` datetime DEFAULT NULL,
  `last_live_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`organization_id`),
  KEY `fk_tba_live_user` (`updated_by`),
  CONSTRAINT `fk_tba_live_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tba_live_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
