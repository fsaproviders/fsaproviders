<?php
/**
 * Plugin Name: FSA Account Menu Manager
 * Description: Control the WooCommerce My Account menu (add endpoints, pages, or custom URLs) and automatically remove WooCommerce endpoints from the MyListing user navigation dropdown.
 * Version:     1.2.2
 * Author:      FSA Providers
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 * Text Domain: fsa-amm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FSA_AMM_VERSION', '1.2.2' );
define( 'FSA_AMM_FILE', __FILE__ );
define( 'FSA_AMM_DIR', plugin_dir_path( __FILE__ ) );
define( 'FSA_AMM_URL', plugin_dir_url( __FILE__ ) );
define( 'FSA_AMM_OPTION', 'fsa_amm_items' );

require_once FSA_AMM_DIR . 'includes/class-fsa-amm-plugin.php';
require_once FSA_AMM_DIR . 'includes/class-fsa-amm-renderer.php';
require_once FSA_AMM_DIR . 'includes/class-fsa-amm-admin.php';

add_action( 'plugins_loaded', function () {
	FSA_AMM_Plugin::instance();
} );
