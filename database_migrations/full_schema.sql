-- ══════════════════════════════════════════════════════════
-- Skema lengkap (struktur saja, tanpa data) — diambil dari database produksi
-- lebahcua_panen pada 2026-10-29. Untuk instalasi baru pada database kosong.
-- ══════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `admin_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `payload` text DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tg_msg_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint(20) unsigned NOT NULL,
  `sender` enum('user','admin','ai','system') NOT NULL DEFAULT 'user',
  `message` text NOT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `tg_msg_id` int(11) DEFAULT NULL COMMENT 'Telegram message_id',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`),
  CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `chat_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `chat_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue_token` varchar(64) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `user_name` varchar(100) DEFAULT 'Guest',
  `user_email` varchar(150) DEFAULT NULL,
  `mode` varchar(20) NOT NULL DEFAULT 'admin',
  `status` varchar(20) NOT NULL DEFAULT 'waiting',
  `assigned_session_key` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_ping_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_queue_token` (`queue_token`),
  KEY `idx_status` (`status`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_last_ping_at` (`last_ping_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_key` varchar(64) NOT NULL COMMENT 'UUID sesi unik',
  `user_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = guest',
  `user_name` varchar(100) NOT NULL DEFAULT 'Guest',
  `user_email` varchar(120) DEFAULT NULL,
  `tg_thread_id` int(11) DEFAULT NULL COMMENT 'Telegram message_thread_id untuk grup',
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `close_reason` text DEFAULT NULL,
  `mode` enum('admin','ai') NOT NULL DEFAULT 'ai',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_message_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_kept` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_key` (`session_key`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_session_key` (`session_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `contact_buttons` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(100) NOT NULL,
  `url` varchar(500) NOT NULL,
  `icon_type` enum('preset','custom') NOT NULL DEFAULT 'preset',
  `icon_value` varchar(255) NOT NULL DEFAULT 'wa',
  `bg_color` varchar(20) NOT NULL DEFAULT '#25D366',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `deposits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `method` varchar(50) NOT NULL DEFAULT 'transfer',
  `proof_image` varchar(255) DEFAULT NULL,
  `status` enum('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  `admin_note` text DEFAULT NULL,
  `no_commission` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `confirmed_at` datetime DEFAULT NULL,
  `tg_msg_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `deposits_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `discount_vouchers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `discounts` text NOT NULL,
  `max_claims` int(11) NOT NULL DEFAULT 0,
  `claims_count` int(11) NOT NULL DEFAULT 0,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `farm_admin_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin` varchar(80) NOT NULL,
  `action` varchar(40) NOT NULL,
  `detail` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `farm_crops` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(80) NOT NULL,
  `model` varchar(20) NOT NULL DEFAULT 'rice',
  `color` varchar(9) NOT NULL DEFAULT '#facc15',
  `growth_secs` int(10) unsigned NOT NULL DEFAULT 60,
  `seed_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `yield_min` int(10) unsigned NOT NULL DEFAULT 1,
  `yield_max` int(10) unsigned NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `xp` int(10) unsigned NOT NULL DEFAULT 5,
  `min_level` int(10) unsigned NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `farm_inventory` (
  `user_id` int(10) unsigned NOT NULL,
  `kind` enum('seed','harvest','item') NOT NULL,
  `ref_id` int(10) unsigned NOT NULL,
  `qty` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`kind`,`ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `farm_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(80) NOT NULL,
  `effect` varchar(20) NOT NULL,
  `effect_value` int(11) NOT NULL DEFAULT 0,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `description` varchar(190) NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `farm_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `action` varchar(20) NOT NULL,
  `plot_index` tinyint(3) unsigned DEFAULT NULL,
  `ref_id` int(10) unsigned DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `wallet` enum('dep','wd','none') NOT NULL DEFAULT 'none',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `farm_plots` (
  `user_id` int(10) unsigned NOT NULL,
  `plot_index` tinyint(3) unsigned NOT NULL,
  `status` enum('empty','growing','ready') NOT NULL DEFAULT 'empty',
  `crop_id` int(10) unsigned DEFAULT NULL,
  `planted_at` int(10) unsigned NOT NULL DEFAULT 0,
  `matures_at` int(10) unsigned NOT NULL DEFAULT 0,
  `pest_at` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`plot_index`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `farm_users` (
  `user_id` int(10) unsigned NOT NULL,
  `unlocked_plots` tinyint(3) unsigned NOT NULL DEFAULT 4,
  `xp` int(10) unsigned NOT NULL DEFAULT 0,
  `barn_capacity` int(10) unsigned NOT NULL DEFAULT 30,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fishing_admin_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `admin` varchar(80) NOT NULL,
  `action` varchar(60) NOT NULL,
  `target_user_id` int(10) unsigned DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fishing_bait_packs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tag` varchar(30) DEFAULT NULL,
  `description` varchar(160) DEFAULT NULL,
  `color` varchar(9) NOT NULL DEFAULT '#f472b6',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fishing_bait_purchases` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `pack_id` int(11) DEFAULT NULL,
  `pack_name` varchar(80) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fishing_lock_uses` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `log_id` bigint(20) NOT NULL,
  `is_free` tinyint(1) NOT NULL DEFAULT 0,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lock_log` (`log_id`),
  KEY `idx_lock_user` (`user_id`,`is_free`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fishing_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `fish_name` varchar(100) NOT NULL,
  `fish_icon` varchar(50) NOT NULL DEFAULT '?',
  `rarity` varchar(30) NOT NULL DEFAULT 'common',
  `weight_kg` decimal(5,2) NOT NULL DEFAULT 1.00,
  `reward` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `sold_at` datetime DEFAULT NULL,
  `sold_price` decimal(12,2) DEFAULT NULL,
  `sale_id` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_unsold` (`user_id`,`sold_at`),
  KEY `idx_sale` (`sale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fishing_market_prices` (
  `price_date` date NOT NULL,
  `badge` varchar(20) NOT NULL,
  `mult` decimal(5,3) NOT NULL DEFAULT 1.000,
  `is_manual` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`price_date`,`badge`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fishing_prefs` (
  `user_id` int(10) unsigned NOT NULL,
  `rod_skin` varchar(32) NOT NULL DEFAULT 'bambu',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sound_muted` tinyint(1) NOT NULL DEFAULT 0,
  `tutorial_seen` varchar(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fishing_released` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `log_id` bigint(20) NOT NULL,
  `fish_name` varchar(100) NOT NULL,
  `rarity` varchar(30) NOT NULL,
  `weight_kg` decimal(5,2) NOT NULL DEFAULT 0.00,
  `reward` decimal(12,2) NOT NULL DEFAULT 0.00,
  `was_locked` tinyint(1) NOT NULL DEFAULT 0,
  `caught_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rel_user` (`user_id`),
  KEY `idx_rel_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fishing_rod_skins` (
  `skin_key` varchar(32) NOT NULL,
  `name` varchar(80) NOT NULL,
  `rarity` varchar(20) NOT NULL DEFAULT 'biasa',
  `req_tier` int(11) NOT NULL DEFAULT 0 COMMENT 'sort_order membership minimal',
  `req_catches` int(11) NOT NULL DEFAULT 0,
  `req_jackpots` int(11) NOT NULL DEFAULT 0,
  `reel_bonus` int(11) NOT NULL DEFAULT 0 COMMENT '% kecepatan tarikan',
  `bite_bonus` int(11) NOT NULL DEFAULT 0 COMMENT '% pengurangan waktu tunggu',
  `description` varchar(200) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`skin_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fishing_sales` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `fish_count` int(11) NOT NULL DEFAULT 0,
  `total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fishing_species` (
  `badge` varchar(20) NOT NULL,
  `name` varchar(80) NOT NULL,
  `chance` decimal(6,2) NOT NULL DEFAULT 0.00,
  `weight_min` decimal(6,2) NOT NULL DEFAULT 0.50,
  `weight_max` decimal(6,2) NOT NULL DEFAULT 1.00,
  `price_min` decimal(12,2) NOT NULL DEFAULT 0.00,
  `price_max` decimal(12,2) NOT NULL DEFAULT 0.00,
  `hardness` decimal(4,2) NOT NULL DEFAULT 1.00,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`badge`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forwarder_heartbeats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `interval_minutes` int(11) NOT NULL DEFAULT 5,
  `device_info` varchar(255) DEFAULT NULL,
  `payload_text` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `gc_admin_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `admin` varchar(80) NOT NULL,
  `action` varchar(60) NOT NULL,
  `target_user_id` int(10) unsigned DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gcl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gc_daily_usage` (
  `user_id` int(10) unsigned NOT NULL,
  `period_key` varchar(40) NOT NULL,
  `msg_count` int(11) NOT NULL DEFAULT 0,
  `img_count` int(11) NOT NULL DEFAULT 0,
  `last_sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`,`period_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gc_messages` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `username` varchar(60) NOT NULL DEFAULT '',
  `tier_name` varchar(60) NOT NULL DEFAULT '',
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `message` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `is_shadow` tinyint(1) NOT NULL DEFAULT 0,
  `flag_reason` varchar(160) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_by` varchar(60) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gc_visible` (`is_deleted`,`is_shadow`,`id`),
  KEY `idx_gc_user` (`user_id`,`created_at`),
  KEY `idx_gc_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gc_user_overrides` (
  `user_id` int(10) unsigned NOT NULL,
  `can_view` tinyint(1) DEFAULT NULL,
  `can_send` tinyint(1) DEFAULT NULL,
  `can_image` tinyint(1) DEFAULT NULL,
  `extra_msg` int(11) NOT NULL DEFAULT 0,
  `extra_img` int(11) NOT NULL DEFAULT 0,
  `muted_until` datetime DEFAULT NULL,
  `note` varchar(160) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `investment_packages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `roi_percent` decimal(5,2) NOT NULL,
  `duration_days` int(10) unsigned NOT NULL,
  `daily_profit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `investment_profit_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `user_investment_id` int(10) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `days_claimed` int(10) unsigned NOT NULL,
  `claimed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `user_investment_id` (`user_investment_id`),
  CONSTRAINT `investment_profit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `investment_profit_logs_ibfk_2` FOREIGN KEY (`user_investment_id`) REFERENCES `user_investments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `memberships` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `icon` varchar(20) NOT NULL DEFAULT '⭐',
  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `original_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `watch_limit` int(11) NOT NULL DEFAULT 10 COMMENT 'Jumlah video per hari',
  `duration_days` int(11) NOT NULL DEFAULT 30,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `min_wd` decimal(15,2) NOT NULL DEFAULT 50000.00,
  `max_wd` decimal(15,2) NOT NULL DEFAULT 0.00,
  `wd_hold` tinyint(1) NOT NULL DEFAULT 0,
  `allow_edit_bank` tinyint(1) NOT NULL DEFAULT 0,
  `perf_avg` decimal(5,2) NOT NULL DEFAULT 99.80,
  `perf_down_if_own` tinyint(1) NOT NULL DEFAULT 0,
  `is_wd_disabled` tinyint(1) NOT NULL DEFAULT 0,
  `is_genjutsu` tinyint(1) NOT NULL DEFAULT 0,
  `is_genjutsu_hilang` tinyint(1) NOT NULL DEFAULT 0,
  `price_genjutsu` decimal(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `minigame_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `game_type` varchar(50) NOT NULL,
  `score` int(11) NOT NULL,
  `reward` decimal(10,2) NOT NULL,
  `played_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE IF NOT EXISTS `missions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) NOT NULL,
  `category` enum('daily','weekly','lifetime') NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `target_value` int(11) NOT NULL DEFAULT 1,
  `reward_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reward_bait` int(11) NOT NULL DEFAULT 0,
  `icon` varchar(100) DEFAULT 'ph-trophy',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `notification_reads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `notification_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `read_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uidx_notif_user` (`notification_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notification_reads_ibfk_1` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notification_reads_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','success','warning','alert','congrats') NOT NULL DEFAULT 'info',
  `icon` varchar(10) DEFAULT NULL,
  `target_type` enum('all','single','selected','has_balance','has_membership') NOT NULL DEFAULT 'all',
  `target_user_ids` text DEFAULT NULL,
  `action_url` varchar(255) DEFAULT NULL,
  `action_text` varchar(50) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `orderkuota_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `auth_token` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `page_views` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `path` varchar(255) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_hash` varchar(64) DEFAULT NULL,
  `referrer` varchar(500) DEFAULT NULL,
  `user_agent` varchar(300) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_path` (`path`),
  KEY `idx_date` (`created_at`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE IF NOT EXISTS `payment_channels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `type` enum('bank','ewallet') DEFAULT 'bank',
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE IF NOT EXISTS `payment_gateway_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `payload` text NOT NULL,
  `extracted_amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `deposit_id` int(10) unsigned DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'unmatched',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_deposit` (`deposit_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plinko_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `coins_bet` int(10) unsigned NOT NULL,
  `multiplier` decimal(5,2) NOT NULL,
  `reward_coins` int(10) unsigned NOT NULL DEFAULT 0,
  `reward_wd` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `plinko_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `promotor_daily_targets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `date` date NOT NULL,
  `target_deposits` decimal(16,2) NOT NULL,
  `actual_deposits` decimal(16,2) NOT NULL,
  `deposit_count` int(11) NOT NULL DEFAULT 0,
  `target_regs` int(11) NOT NULL,
  `actual_regs` int(11) NOT NULL,
  `valid_regs` int(11) DEFAULT NULL,
  `percentage` decimal(5,2) NOT NULL,
  `salary_rate` decimal(16,2) NOT NULL,
  `guarantee_amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `earned_amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `is_paid` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_date` (`user_id`,`date`),
  KEY `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `redeem_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `reward_wd` decimal(15,2) NOT NULL DEFAULT 0.00,
  `reward_dep` decimal(15,2) NOT NULL DEFAULT 0.00,
  `reward_level_id` int(10) unsigned DEFAULT NULL,
  `max_claims` int(11) NOT NULL DEFAULT 0,
  `claims_count` int(11) NOT NULL DEFAULT 0,
  `expires_at` datetime DEFAULT NULL,
  `target_users` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `referral_clicks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `promotor_id` int(10) unsigned NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(300) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_promotor_date` (`promotor_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `referral_commissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `from_user_id` int(10) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'claimed',
  `unlock_at` datetime DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `from_user_id` (`from_user_id`),
  KEY `idx_user_status_unlock` (`user_id`,`status`,`unlock_at`),
  CONSTRAINT `referral_commissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `referral_commissions_ibfk_2` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(100) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `staff` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `role_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `staff_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `staff_roles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE IF NOT EXISTS `staff_role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission` varchar(100) NOT NULL,
  PRIMARY KEY (`role_id`,`permission`),
  CONSTRAINT `staff_role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `staff_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE IF NOT EXISTS `staff_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE IF NOT EXISTS `upgrade_orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `membership_id` int(10) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `status` enum('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  `admin_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `confirmed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `membership_id` (`membership_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_discount_claims` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `voucher_id` int(10) unsigned NOT NULL,
  `claimed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uidx_user_voucher` (`user_id`,`voucher_id`),
  KEY `voucher_id` (`voucher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_investments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `package_id` int(10) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `daily_profit` decimal(15,2) NOT NULL,
  `roi_percent` decimal(5,2) NOT NULL,
  `duration_days` int(10) unsigned NOT NULL,
  `days_passed` int(10) unsigned NOT NULL DEFAULT 0,
  `last_profit_claimed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','completed') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_missions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `mission_slug` varchar(100) NOT NULL,
  `progress` int(11) NOT NULL DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `period_key` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_mission_period` (`user_id`,`mission_slug`,`period_key`),
  KEY `idx_user_missions_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_redeems` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `code_id` int(10) unsigned NOT NULL,
  `claimed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uidx_user_code` (`user_id`,`code_id`),
  KEY `code_id` (`code_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_surveys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `source_info` varchar(150) NOT NULL,
  `source_other` varchar(255) DEFAULT NULL,
  `experience` text NOT NULL,
  `satisfaction_rating` tinyint(3) unsigned NOT NULL COMMENT '1 to 5 rating',
  `feedback_message` text NOT NULL,
  `reward_amount` decimal(15,2) NOT NULL DEFAULT 15000.00,
  `status` enum('completed','revoked') NOT NULL DEFAULT 'completed',
  `revoked_at` datetime DEFAULT NULL,
  `revoke_reason` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_survey` (`user_id`),
  KEY `idx_survey_created` (`created_at`),
  KEY `idx_survey_rating` (`satisfaction_rating`),
  KEY `idx_survey_source` (`source_info`),
  CONSTRAINT `fk_survey_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_watch_progress` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `video_id` int(10) unsigned NOT NULL,
  `seconds_watched` int(10) unsigned NOT NULL DEFAULT 0,
  `duration` int(10) unsigned NOT NULL DEFAULT 0,
  `last_position` float NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_video` (`user_id`,`video_id`),
  KEY `idx_user_updated` (`user_id`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(120) NOT NULL,
  `whatsapp` varchar(20) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `referral_code` varchar(12) NOT NULL,
  `referred_by` varchar(12) DEFAULT NULL,
  `balance_wd` decimal(15,2) NOT NULL DEFAULT 0.00,
  `balance_dep` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_earned` decimal(15,2) NOT NULL DEFAULT 0.00,
  `plinko_coins` int(10) unsigned NOT NULL DEFAULT 0,
  `fishing_baits` int(10) unsigned NOT NULL DEFAULT 2,
  `last_plinko_claim` date DEFAULT NULL,
  `membership_id` int(10) unsigned DEFAULT NULL,
  `membership_expires_at` datetime DEFAULT NULL,
  `watch_count_today` int(11) NOT NULL DEFAULT 0,
  `watch_reset_date` date DEFAULT NULL,
  `last_checkin` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_debug` tinyint(1) NOT NULL DEFAULT 0,
  `debug_watch_duration` int(10) unsigned DEFAULT 3,
  `debug_watch_reward` decimal(10,2) DEFAULT 50000.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_seen` datetime DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `account_name` varchar(100) DEFAULT NULL,
  `can_withdraw` tinyint(1) NOT NULL DEFAULT 1,
  `edit_bank_deposit_min` int(11) NOT NULL DEFAULT 50000,
  `plinko_rtp` decimal(5,2) DEFAULT NULL,
  `can_chat` tinyint(1) NOT NULL DEFAULT 1,
  `is_promotor` tinyint(4) NOT NULL DEFAULT 0,
  `promotor_target_deposits` decimal(16,2) NOT NULL DEFAULT 0.00,
  `promotor_target_regs` int(11) NOT NULL DEFAULT 0,
  `promotor_salary_rate` decimal(16,2) NOT NULL DEFAULT 0.00,
  `refund_cut_percent` decimal(5,2) NOT NULL DEFAULT 20.00,
  `is_refund_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `acc_num_input_type` varchar(10) DEFAULT 'typed',
  `acc_name_input_type` varchar(10) DEFAULT 'typed',
  `acc_num_record` text DEFAULT NULL,
  `acc_name_record` text DEFAULT NULL,
  `is_referral_active` tinyint(1) NOT NULL DEFAULT 1,
  `spin_tickets` int(11) DEFAULT 0,
  `registration_ip` varchar(45) DEFAULT NULL,
  `is_flagged_abuse` tinyint(1) NOT NULL DEFAULT 0,
  `abuse_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `referral_code` (`referral_code`),
  KEY `idx_email` (`email`),
  KEY `idx_username` (`username`),
  KEY `idx_referral` (`referral_code`),
  KEY `last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `video_likes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `video_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_video` (`user_id`,`video_id`),
  KEY `idx_video` (`video_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `videos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `youtube_id` varchar(255) NOT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `reward_amount` decimal(10,2) NOT NULL DEFAULT 100.00,
  `watch_duration` int(11) NOT NULL DEFAULT 30 COMMENT 'Durasi minimum tonton (detik)',
  `total_watches` int(10) unsigned NOT NULL DEFAULT 0,
  `fake_watches` int(10) unsigned NOT NULL DEFAULT 0,
  `total_likes` int(10) unsigned NOT NULL DEFAULT 0,
  `fake_likes` int(10) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_youtube` (`youtube_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `watch_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `video_id` int(10) unsigned NOT NULL,
  `reward_given` decimal(10,2) NOT NULL DEFAULT 0.00,
  `watched_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_video` (`video_id`),
  KEY `idx_user_video_date` (`user_id`,`video_id`,`watched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `withdrawals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `account_number` varchar(50) NOT NULL,
  `account_name` varchar(100) NOT NULL,
  `status` enum('pending','approved','rejected','hold','refunded') NOT NULL DEFAULT 'pending',
  `admin_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  `tg_msg_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
