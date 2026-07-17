-- ---------------------------------------------------------------------------
-- ConnectWise Integration — migration 0007: fix note_map uniqueness scope.
--
-- `connectwise_note_id` was globally UNIQUE, but ConnectWise TicketNotes,
-- TimeEntries and Attachments are SEPARATE id-spaces — and every client
-- tenant has its own ids. A collision (e.g. attachment #9 vs time entry #9)
-- made the dedupe marker's INSERT IGNORE silently no-op, so the item was
-- re-imported on every sync tick. Scope both unique keys by instance and
-- note_type. (The new keys are strictly weaker, so existing rows always fit.)
-- ---------------------------------------------------------------------------

ALTER TABLE `%PREFIX%connectwise_note_map`
  DROP INDEX `uq_at_note`,
  DROP INDEX `uq_os_entry`;

ALTER TABLE `%PREFIX%connectwise_note_map`
  ADD UNIQUE KEY `uq_at_note` (`instance_id`, `note_type`, `connectwise_note_id`),
  ADD UNIQUE KEY `uq_os_entry` (`instance_id`, `note_type`, `osticket_entry_id`);
