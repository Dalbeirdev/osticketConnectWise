-- ---------------------------------------------------------------------------
-- ConnectWise Integration — migration 0002: add direction to the sync queue
--
-- Enables the queue to carry BOTH outbound (osTicket -> ConnectWise) and inbound
-- (ConnectWise -> osTicket) jobs, so every synchronization operation is queued,
-- retried and backed off uniformly.
-- ---------------------------------------------------------------------------

ALTER TABLE `%PREFIX%connectwise_sync_queue`
  ADD COLUMN `direction` ENUM('to_connectwise','to_osticket')
  NOT NULL DEFAULT 'to_connectwise' AFTER `entity_type`;
