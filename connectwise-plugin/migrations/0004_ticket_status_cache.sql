-- ---------------------------------------------------------------------------
-- ConnectWise Integration — migration 0004: cache ConnectWise status on the map
--
-- Avoids a live getTicket() API call on every ticket-view panel load; the
-- status is kept fresh by inbound sync + outbound status changes, and
-- lazy-populated once for pre-existing mappings.
-- ---------------------------------------------------------------------------

ALTER TABLE `%PREFIX%connectwise_ticket_map`
  ADD COLUMN `connectwise_status` VARCHAR(16) NULL AFTER `connectwise_ticket_number`;
