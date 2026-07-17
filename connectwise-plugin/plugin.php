<?php
/**
 * ConnectWise Integration — osTicket Plugin Manifest
 *
 * This file is read by osTicket's PluginManager when the plugin folder is
 * scanned. It MUST return an associative array describing the plugin and
 * pointing at the bootstrap class that osTicket will instantiate.
 *
 * @package   ConnectWise Integration
 * @author    DPI / Senior PHP Engineering
 * @license   GPLv2
 * @link      https://github.com/Dalbeirdev/ConnectWise-PSA-integration-with-osticket
 *
 * Compatible with osTicket 1.17.x / 1.18.x, PHP 8.0 – 8.3.
 *
 * PSR-12 compliant. No external Composer dependencies.
 */

return array(
    // Globally-unique identifier. Convention: vendor:plugin.
    'id'          => 'dpi:connectwise',

    // Semantic version. Used by the migration runner to detect upgrades.
    'version'     => '1.0.0',

    // Human readable metadata shown in Admin Panel » Manage » Plugins.
    'name'        => 'ConnectWise Integration',
    'author'      => 'DPI',
    'description' => 'Two-way synchronization between osTicket and ConnectWise PSA '
                   . '(tickets, replies, notes, status & priority). Includes an '
                   . 'admin dashboard, retry queue, scheduler and full logging.',
    'url'         => 'https://github.com/Dalbeirdev/ConnectWise-PSA-integration-with-osticket',

    // Entry point: "<file>:<ClassName>". osTicket includes the file and
    // instantiates the class (which must extend the core Plugin class).
    'plugin'      => 'bootstrap.php:ConnectWise\\ConnectWisePlugin',

    // Minimum core/PHP requirements (informational; enforced in bootstrap()).
    'requires'    => array(
        'php'      => '8.0.0',
        'osticket' => '1.17',
    ),
);
