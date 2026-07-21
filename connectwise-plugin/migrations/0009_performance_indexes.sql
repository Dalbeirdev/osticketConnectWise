-- ---------------------------------------------------------------------------
-- ConnectWise Integration — migration 0009: performance indexes (schema 2.3.0)
--
-- Index coverage for the hot paths at MSP scale (thousands of tickets, many
-- concurrent sync jobs). Every index below was added for a specific query
-- that previously scanned:
--
--   time_entry(osticket_ticket_id)          panel/time-history listing had NO
--                                           usable index -> full table scan on
--                                           every ticket view.
--   time_entry(ticket_map_id, cw_te_id)     covering index for the inbound
--                                           echo-guard lookup (see below).
--   note_map(ticket_map_id, note_type)      attachment/time-entry dedupe reads
--                                           filtered note_type after idx_map.
--   log(category, created)                  dashboard "API calls (24h)".
--   log(level, id)                          dashboard "last error" (ORDER BY id).
--   sync_history(entity_type, status)       dashboard imported/exported split.
--   picklist_cache(instance_id, field, value)
--                                           label/value lookups omit `entity`,
--                                           so uq_iefv could only use its
--                                           instance_id prefix.
--
-- Plain ADD KEY (no IF NOT EXISTS) for MySQL 8 + MariaDB portability; the
-- migration runner guarantees single execution.
-- ---------------------------------------------------------------------------

ALTER TABLE `%PREFIX%connectwise_time_entry`
  ADD KEY `idx_osticket_ticket` (`osticket_ticket_id`),
  ADD KEY `idx_map_te` (`ticket_map_id`, `connectwise_time_entry_id`);

ALTER TABLE `%PREFIX%connectwise_note_map`
  ADD KEY `idx_map_type` (`ticket_map_id`, `note_type`);

ALTER TABLE `%PREFIX%connectwise_log`
  ADD KEY `idx_category_created` (`category`, `created`),
  ADD KEY `idx_level_id` (`level`, `id`);

ALTER TABLE `%PREFIX%connectwise_sync_history`
  ADD KEY `idx_entity_status` (`entity_type`, `status`);

ALTER TABLE `%PREFIX%connectwise_picklist_cache`
  ADD KEY `idx_ifv` (`instance_id`, `field`, `value`);
