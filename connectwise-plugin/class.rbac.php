<?php
/**
 * ConnectWise Integration — role-based access control.
 *
 * Registers custom osTicket agent permissions and checks them for the
 * technician actions (log time, change status, complete). Admins always pass.
 * Falls back gracefully if the core RBAC API differs across versions.
 *
 * Grant these under: Admin Panel » Agents » Roles » (role) » Permissions » ConnectWise.
 *
 * @package ConnectWise Integration
 */

namespace ConnectWise;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Custom permission registration + checks.
 */
class Rbac
{
    const PERM_TIME     = 'connectwise.time';
    const PERM_STATUS   = 'connectwise.status';
    const PERM_COMPLETE = 'connectwise.complete';

    /** key => [title, description] */
    const PERMS = array(
        self::PERM_TIME     => array('ConnectWise: Log Time', 'Log time entries to ConnectWise from a ticket.'),
        self::PERM_STATUS   => array('ConnectWise: Change Status', 'Change the ConnectWise ticket status.'),
        self::PERM_COMPLETE => array('ConnectWise: Complete Ticket', 'Complete/close the ConnectWise ticket with a resolution.'),
    );

    /**
     * Register the permissions so they appear in the Roles UI. Idempotent.
     */
    public static function register(): void
    {
        if (!class_exists('RolePermission')) {
            return;
        }
        try {
            \RolePermission::register('ConnectWise', self::PERMS);
        } catch (\Throwable $e) {
            error_log('[ConnectWise][Rbac] register failed: ' . $e->getMessage());
        }
    }

    /**
     * @param mixed  $staff \Staff instance.
     * @param string $perm  One of the PERM_* constants.
     * @return bool
     */
    public static function can($staff, string $perm): bool
    {
        if (!$staff) {
            return false;
        }
        // Admins always pass.
        if (method_exists($staff, 'isAdmin') && $staff->isAdmin()) {
            return true;
        }
        // Enforce the specific permission when the RBAC API is available.
        if (method_exists($staff, 'hasPerm')) {
            try {
                return (bool) $staff->hasPerm($perm);
            } catch (\Throwable $e) {
                // fall through to permissive default
            }
        }
        // Graceful fallback (older cores without hasPerm): allow authenticated staff.
        return true;
    }
}
