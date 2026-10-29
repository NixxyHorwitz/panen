-- ══════════════════════════════════════════════════════════
-- Tabel fitur Nonton Video (disalin dari skema database lokal `mancink`).
-- Jalankan sekali di database yang belum punya tabel ini
-- (mis. lebahcua_panen). Aman diulang: IF NOT EXISTS.
-- ══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `watch_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `video_id` int unsigned NOT NULL,
  `reward_given` decimal(10,2) NOT NULL DEFAULT '0.00',
  `watched_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_video` (`video_id`),
  KEY `idx_user_video_date` (`user_id`,`video_id`,`watched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_watch_progress` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `video_id` int unsigned NOT NULL,
  `seconds_watched` int unsigned NOT NULL DEFAULT '0',
  `duration` int unsigned NOT NULL DEFAULT '0',
  `last_position` float NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_video` (`user_id`,`video_id`),
  KEY `idx_user_updated` (`user_id`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
