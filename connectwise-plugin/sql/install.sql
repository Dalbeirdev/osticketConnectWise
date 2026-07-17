-- ---------------------------------------------------------------------------
-- ConnectWise Integration — full schema snapshot (manual install reference)
--
-- The plugin installs these automatically via the migration runner. This file
-- is provided for DBAs who prefer to provision the schema by hand.
--
-- Replace `ost_` below with your osTicket TABLE_PREFIX if different.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ost_connectwise_migrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(191) NOT NULL,
  `applied_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_connectwise_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skey` VARCHAR(191) NOT NULL,
  `svalue` LONGTEXT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_skey` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_connectwise_ticket_map` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `osticket_ticket_id` INT UNSIGNED NOT NULL,
  `connectwise_ticket_id` BIGINT UNSIGNED NULL,
  `connectwise_ticket_number` VARCHAR(64) NULL,
  `last_sync_time` DATETIME NULL,
  `last_updated_by` VARCHAR(16) NOT NULL DEFAULT 'system',
  `sync_direction` ENUM('to_connectwise','to_osticket','bidirectional') NOT NULL DEFAULT 'bidirectional',
  `osticket_hash` VARCHAR(64) NULL,
  `connectwise_lastactivity` DATETIME NULL,
  `created` DATETIME NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_osticket` (`osticket_ticket_id`),
  KEY `idx_connectwise` (`connectwise_ticket_id`),
  KEY `idx_lastsync` (`last_sync_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_connectwise_sync_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `osticket_ticket_id` INT UNSIGNED NULL,
  `entity_type` VARCHAR(32) NOT NULL,
  `action` VARCHAR(32) NOT NULL,
  `payload` LONGTEXT NULL,
  `status` ENUM('pending','processing','done','failed','dead') NOT NULL DEFAULT 'pending',
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `next_attempt_at` DATETIME NOT NULL,
  `last_error` TEXT NULL,
  `dedupe_key` VARCHAR(191) NULL,
  `created` DATETIME NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_next` (`status`, `next_attempt_at`),
  KEY `idx_ticket` (`osticket_ticket_id`),
  UNIQUE KEY `uq_dedupe` (`dedupe_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_connectwise_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `level` ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
  `category` VARCHAR(64) NOT NULL DEFAULT 'general',
  `osticket_ticket_id` INT UNSIGNED NULL,
  `connectwise_ticket_id` BIGINT UNSIGNED NULL,
  `message` TEXT NOT NULL,
  `request` MEDIUMTEXT NULL,
  `response` MEDIUMTEXT NULL,
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_level` (`level`),
  KEY `idx_category` (`category`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_connectwise_sync_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `map_id` INT UNSIGNED NULL,
  `osticket_ticket_id` INT UNSIGNED NULL,
  `connectwise_ticket_id` BIGINT UNSIGNED NULL,
  `direction` ENUM('to_connectwise','to_osticket') NOT NULL,
  `entity_type` VARCHAR(32) NOT NULL,
  `status` ENUM('success','failed','skipped') NOT NULL,
  `summary` VARCHAR(255) NULL,
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_map` (`map_id`),
  KEY `idx_created` (`created`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NOTE: the sync_queue also gains a `direction` column (migration 0002):
--   ALTER TABLE `ost_connectwise_sync_queue`
--     ADD COLUMN `direction` ENUM('to_connectwise','to_osticket')
--     NOT NULL DEFAULT 'to_connectwise' AFTER `entity_type`;

-- ---- Enterprise foundation tables (migration 0003) ----------------------

CREATE TABLE IF NOT EXISTS `ost_connectwise_note_map` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_map_id` INT UNSIGNED NOT NULL,
  `osticket_entry_id` INT UNSIGNED NULL,
  `connectwise_note_id` BIGINT UNSIGNED NULL,
  `direction` ENUM('to_connectwise','to_osticket') NOT NULL,
  `note_type` VARCHAR(16) NOT NULL DEFAULT 'note',
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_at_note` (`connectwise_note_id`),
  UNIQUE KEY `uq_os_entry` (`osticket_entry_id`),
  KEY `idx_map` (`ticket_map_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_connectwise_time_entry` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_map_id` INT UNSIGNED NOT NULL,
  `osticket_ticket_id` INT UNSIGNED NOT NULL,
  `connectwise_ticket_id` BIGINT UNSIGNED NULL,
  `connectwise_time_entry_id` BIGINT UNSIGNED NULL,
  `resource_id` INT UNSIGNED NULL,
  `start_datetime` DATETIME NULL,
  `end_datetime` DATETIME NULL,
  `hours_worked` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `billing_code_id` INT NULL,
  `role_id` INT NULL,
  `billable` TINYINT(1) NOT NULL DEFAULT 1,
  `summary_notes` TEXT NULL,
  `internal_notes` TEXT NULL,
  `status` ENUM('pending','synced','failed') NOT NULL DEFAULT 'pending',
  `created_by` INT UNSIGNED NULL,
  `last_error` TEXT NULL,
  `created` DATETIME NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_map` (`ticket_map_id`),
  KEY `idx_at_te` (`connectwise_time_entry_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_connectwise_picklist_cache` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity` VARCHAR(48) NOT NULL,
  `field` VARCHAR(48) NOT NULL,
  `value` VARCHAR(64) NOT NULL,
  `label` VARCHAR(191) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `fetched_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_efv` (`entity`, `field`, `value`),
  KEY `idx_ef` (`entity`, `field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_connectwise_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id` INT UNSIGNED NULL,
  `staff_name` VARCHAR(128) NULL,
  `action` VARCHAR(64) NOT NULL,
  `entity` VARCHAR(32) NULL,
  `osticket_ticket_id` INT UNSIGNED NULL,
  `connectwise_ticket_id` BIGINT UNSIGNED NULL,
  `detail` LONGTEXT NULL,
  `ip` VARCHAR(45) NULL,
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_staff` (`staff_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_connectwise_import_filter` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `criteria` LONGTEXT NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created` DATETIME NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_connectwise_conflict` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_map_id` INT UNSIGNED NOT NULL,
  `osticket_ticket_id` INT UNSIGNED NULL,
  `connectwise_ticket_id` BIGINT UNSIGNED NULL,
  `field` VARCHAR(48) NOT NULL,
  `osticket_value` TEXT NULL,
  `connectwise_value` TEXT NULL,
  `resolution` ENUM('unresolved','connectwise_wins','osticket_wins','manual') NOT NULL DEFAULT 'unresolved',
  `resolved` TINYINT(1) NOT NULL DEFAULT 0,
  `created` DATETIME NOT NULL,
  `resolved_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_map` (`ticket_map_id`),
  KEY `idx_resolved` (`resolved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Identity maps + webhook log (migration 0008, schema 2.2.0). NB: the live
-- migrations also add `instance_id` to every earlier table (migration 0005).

CREATE TABLE IF NOT EXISTS `ost_connectwise_company_map` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instance_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `cw_company_id` INT UNSIGNED NOT NULL,
  `osticket_org_id` INT UNSIGNED NOT NULL,
  `company_name` VARCHAR(191) NOT NULL DEFAULT '',
  `created` DATETIME NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_instance_company` (`instance_id`, `cw_company_id`),
  KEY `idx_org` (`osticket_org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_connectwise_contact_map` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instance_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `cw_contact_id` INT UNSIGNED NOT NULL,
  `osticket_user_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(191) NOT NULL DEFAULT '',
  `created` DATETIME NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_instance_contact` (`instance_id`, `cw_contact_id`),
  KEY `idx_user` (`osticket_user_id`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_connectwise_member_map` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instance_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `cw_member_id` INT UNSIGNED NOT NULL,
  `osticket_staff_id` INT UNSIGNED NULL,
  `email` VARCHAR(191) NOT NULL DEFAULT '',
  `name` VARCHAR(191) NOT NULL DEFAULT '',
  `created` DATETIME NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_instance_member` (`instance_id`, `cw_member_id`),
  KEY `idx_staff` (`osticket_staff_id`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_connectwise_webhook_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instance_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `event_type` VARCHAR(64) NOT NULL DEFAULT '',
  `entity` VARCHAR(64) NOT NULL DEFAULT '',
  `entity_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `payload` MEDIUMTEXT NULL,
  `signature` VARCHAR(191) NOT NULL DEFAULT '',
  `status` ENUM('received','processed','failed','ignored') NOT NULL DEFAULT 'received',
  `error` TEXT NULL,
  `received_at` DATETIME NOT NULL,
  `processed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_instance_status` (`instance_id`, `status`),
  KEY `idx_entity` (`entity`, `entity_id`),
  KEY `idx_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
