<?php
/**
 * ConnectWise Integration — SCP Time Entry panel loader.
 *
 * COPY THIS FILE INTO YOUR osTicket scp/ DIRECTORY AS: scp/connectwise-ticket.php
 *
 *   sudo cp include/plugins/connectwise/scp-connectwise-ticket.php scp/connectwise-ticket.php
 *   sudo chown www-data:www-data scp/connectwise-ticket.php
 *
 * Access:  http://<helpdesk>/scp/connectwise-ticket.php?id=<osticket-ticket-id>
 * (must be logged in as staff with access to the ticket).
 *
 * @package ConnectWise Integration
 */

require('staff.inc.php');

if (!defined('INCLUDE_DIR')) {
    http_response_code(500);
    die('osTicket context not loaded.');
}

$matches = glob(INCLUDE_DIR . 'plugins/*/admin/timeentry.inc.php');
if (!$matches) {
    http_response_code(404);
    die('ConnectWise Time Entry controller not found under include/plugins/.');
}

require $matches[0];
