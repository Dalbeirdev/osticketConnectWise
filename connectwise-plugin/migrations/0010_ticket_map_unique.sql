-- ---------------------------------------------------------------------------
-- ConnectWise Integration — migration 0010: ticket_map uniqueness (schema 2.3.1)
--
-- A ConnectWise ticket must map to AT MOST ONE osTicket ticket per client
-- instance. The import worker's check-then-create was not atomic, so two
-- overlapping cron runs importing the same CW ticket in the same second could
-- both pass the "already mapped?" check and create two osTicket twins
-- (observed live: CW #551 -> osT #14 + #15). The worker now serializes imports
-- with an advisory lock; this index is the database-level guarantee that a
-- silent duplicate can never be persisted again.
--
-- connectwise_ticket_id is NULL while an outbound-created ticket waits for its
-- ConnectWise id — MySQL unique indexes permit multiple NULLs, so pending
-- outbound rows are unaffected.
--
-- PRECONDITION handled by the deploy: existing duplicate rows must be removed
-- before this migration runs (the upgrade path de-duplicates first).
-- ---------------------------------------------------------------------------

ALTER TABLE `%PREFIX%connectwise_ticket_map`
  ADD UNIQUE KEY `uq_instance_cw_ticket` (`instance_id`, `connectwise_ticket_id`);
