-- ---------------------------------------------------------------------------
-- ConnectWise Integration — manual uninstall (DESTRUCTIVE)
--
-- Removes every table created by the plugin. Run only if you want to delete all
-- mapping/log/queue/history data. Replace `ost_` with your TABLE_PREFIX.
-- ---------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `ost_connectwise_conflict`;
DROP TABLE IF EXISTS `ost_connectwise_import_filter`;
DROP TABLE IF EXISTS `ost_connectwise_audit`;
DROP TABLE IF EXISTS `ost_connectwise_picklist_cache`;
DROP TABLE IF EXISTS `ost_connectwise_time_entry`;
DROP TABLE IF EXISTS `ost_connectwise_note_map`;
DROP TABLE IF EXISTS `ost_connectwise_sync_history`;
DROP TABLE IF EXISTS `ost_connectwise_log`;
DROP TABLE IF EXISTS `ost_connectwise_sync_queue`;
DROP TABLE IF EXISTS `ost_connectwise_ticket_map`;
DROP TABLE IF EXISTS `ost_connectwise_settings`;
DROP TABLE IF EXISTS `ost_connectwise_migrations`;

SET FOREIGN_KEY_CHECKS = 1;
