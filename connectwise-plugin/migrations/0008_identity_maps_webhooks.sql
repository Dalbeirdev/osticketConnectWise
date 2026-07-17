-- ---------------------------------------------------------------------------
-- ConnectWise Integration — migration 0008: identity maps + webhook log
--
-- 1. Persistent identity mapping tables, one row per ConnectWise record we
--    have linked to an osTicket record, scoped per client instance:
--      company_map   ConnectWise Company  -> osTicket Organization
--      contact_map   ConnectWise Contact  -> osTicket User
--      member_map    ConnectWise Member   -> osTicket Staff (IDENTITY ONLY —
--                    never used to auto-assign ticket ownership)
-- 2. Webhook (ConnectWise "callback") receipt log — future-ready scaffolding;
--    polling remains the authoritative sync trigger until a callback endpoint
--    ships.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `%PREFIX%connectwise_company_map` (
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

CREATE TABLE IF NOT EXISTS `%PREFIX%connectwise_contact_map` (
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

CREATE TABLE IF NOT EXISTS `%PREFIX%connectwise_member_map` (
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

CREATE TABLE IF NOT EXISTS `%PREFIX%connectwise_webhook_log` (
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
