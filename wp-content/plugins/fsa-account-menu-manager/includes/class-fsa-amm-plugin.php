<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSA_AMM_Plugin {

	private static $instance = null;

	/** @var FSA_AMM_Renderer */
	public $renderer;

	/** @var FSA_AMM_Admin */
	public $admin;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->renderer = new FSA_AMM_Renderer();

		if ( is_admin() ) {
			$this->admin = new FSA_AMM_Admin();
		}
	}

	/**
	 * Fetch the configured menu items.
	 *
	 * @return array
	 */
	public static function get_items() {
		$items = get_option( FSA_AMM_OPTION, null );

		if ( null === $items ) {
			return self::default_items();
		}

		if ( ! is_array( $items ) ) {
			return [];
		}

		return $items;
	}

	/**
	 * Persist menu items.
	 *
	 * @param array $items
	 */
	public static function save_items( array $items ) {
		update_option( FSA_AMM_OPTION, $items, false );
	}

	/**
	 * Default menu seeded on first load, matches WooCommerce defaults.
	 *
	 * @return array
	 */
	public static function default_items() {
		return [
			[ 'id' => 'default_dashboard',       'type' => 'endpoint', 'key' => 'dashboard',       'label' => 'My Account' ],
			[ 'id' => 'default_orders',          'type' => 'endpoint', 'key' => 'orders',          'label' => 'Orders' ],
			[ 'id' => 'default_edit_address',    'type' => 'endpoint', 'key' => 'edit-address',    'label' => 'Addresses' ],
			[ 'id' => 'default_payment_methods', 'type' => 'endpoint', 'key' => 'payment-methods', 'label' => 'Payment Methods' ],
			[ 'id' => 'default_edit_account',    'type' => 'endpoint', 'key' => 'edit-account',    'label' => 'Account Details' ],
			[ 'id' => 'default_logout',          'type' => 'endpoint', 'key' => 'customer-logout', 'label' => 'Log Out' ],
		];
	}
}
