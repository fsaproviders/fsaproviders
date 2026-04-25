<?php
/**
 * Plugin Name: FSAHSA Sync (Zapier <-> Salesforce)
 * Description: Two-way sync helper between WordPress Job Listings and Salesforce Provider_Profile__c via Zapier webhooks.
 * Version: 0.9.8
 * Author: Angela Bacon + ChatGPT
 */

if (!defined('ABSPATH')) { exit; }

define('FSAHSA_SYNC_VERSION', '0.9.8');
define('FSAHSA_SYNC_OPTION', 'fsahsa_sync_settings');
define('FSAHSA_SYNC_LOG_OPTION', 'fsahsa_sync_debug_logs');

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/outbound.php';
require_once __DIR__ . '/includes/location.php';

register_activation_hook(__FILE__, function() {
    $s = fsahsa_sync_get_settings();
    // Ensure defaults exist and option is an array
    update_option(FSAHSA_SYNC_OPTION, $s);

    // Ensure debug log option exists but is not autoloaded (prevents memory bloat sitewide).
    if (get_option(FSAHSA_SYNC_LOG_OPTION, null) === null) {
        add_option(FSAHSA_SYNC_LOG_OPTION, [], '', 'no');
    }
});
