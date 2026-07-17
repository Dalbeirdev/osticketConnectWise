<?php
/**
 * ConnectWise Integration — SCP dashboard loader.
 *
 * COPY THIS FILE INTO YOUR osTicket scp/ DIRECTORY AS:  scp/connectwise.php
 *
 *   cp include/plugins/connectwise/scp-connectwise.php scp/connectwise.php
 *
 * It is web-accessible (scp/ is not blocked like include/), reuses osTicket's
 * staff authentication, then hands off to the plugin's dashboard controller.
 *
 * Access:  http://<your-helpdesk>/scp/connectwise.php   (must be logged in as Admin)
 *
 * @package ConnectWise Integration
 */

// staff.inc.php lives in the same scp/ directory and sets up $thisstaff / $ost.
require('staff.inc.php');

if (!defined('INCLUDE_DIR')) {
    http_response_code(500);
    die('osTicket context not loaded.');
}

// Auto-discover the plugin folder (works whether it is named connectwise,
// connectwise-plugin, etc.) by locating its dashboard controller.
$matches = glob(INCLUDE_DIR . 'plugins/*/admin/dashboard.inc.php');
if (!$matches) {
    http_response_code(404);
    die('ConnectWise plugin dashboard controller not found under include/plugins/.');
}

require $matches[0];
