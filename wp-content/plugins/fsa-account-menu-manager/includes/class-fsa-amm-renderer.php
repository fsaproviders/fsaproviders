<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles filtering the WooCommerce account menu items and URLs.
 *
 * Context detection:
 *  - When WooCommerce renders its own account navigation template, it fires
 *    `woocommerce_before_account_navigation` before calling
 *    `wc_get_account_menu_items()`. We use this as a flag.
 *  - Anywhere else that calls `wc_get_account_menu_items()` (notably the
 *    MyListing user dropdown in the site header) will hit our filter with
 *    the flag OFF, and we return an empty array so the WC endpoints do not
 *    appear in that dropdown.
 */
class FSA_AMM_Renderer {

	/** @var bool */
	private $in_wc_nav = false;

	/** @var array key => url, populated when custom items are rendered */
	private $custom_urls = [];

	public function __construct() {
		add_action( 'woocommerce_before_account_navigation', [ $this, 'enter_wc_nav' ], 1 );
		add_action( 'woocommerce_after_account_navigation',  [ $this, 'exit_wc_nav' ], 999 );

		add_filter( 'woocommerce_account_menu_items',         [ $this, 'filter_menu_items' ], 999 );
		add_filter( 'woocommerce_get_endpoint_url',           [ $this, 'filter_endpoint_url' ], 10, 4 );
		add_filter( 'woocommerce_account_menu_item_classes',  [ $this, 'filter_item_classes' ], 10, 2 );

		// Strip WP Frontend Delete Account from any WordPress nav menu render
		// (this catches MyListing's theme-location menu and any other
		// wp_nav_menu() call that would otherwise include a manually-added
		// Delete Account link or inherit one from theme rendering).
		add_filter( 'wp_nav_menu_objects', [ $this, 'filter_nav_menu_objects' ], 999, 2 );

		// Final safety net: unregister the wpf-delete-account query var so
		// any code iterating WC endpoints directly (MyListing's fallback
		// appears to do this) cannot see it either.
		add_filter( 'woocommerce_get_query_vars', [ $this, 'filter_query_vars' ], PHP_INT_MAX );
	}

	public function enter_wc_nav() {
		$this->in_wc_nav = true;

		// Force MyListing's templates/dashboard/navigation.php into its
		// `else` branch on /my-account/ by making has_nav_menu() return
		// false for the `mylisting-user-menu` location just during this
		// template render. The header dropdown has already rendered by
		// the time this action fires, so it is unaffected.
		add_filter( 'theme_mod_nav_menu_locations', [ $this, 'unassign_mylisting_user_menu' ], PHP_INT_MAX );

		// Register the Delete Account stripper LAZILY, inside the render
		// span, so it is guaranteed to run after any filter WP Frontend
		// Delete Account (or similar) registered during plugins_loaded.
		// Ties at PHP_INT_MAX would otherwise resolve by registration
		// order, and we can't guarantee we load after other plugins.
		add_filter( 'woocommerce_account_menu_items', [ $this, 'strip_delete_account_item' ], PHP_INT_MAX );
	}

	public function exit_wc_nav() {
		$this->in_wc_nav = false;
		remove_filter( 'theme_mod_nav_menu_locations', [ $this, 'unassign_mylisting_user_menu' ], PHP_INT_MAX );
		remove_filter( 'woocommerce_account_menu_items', [ $this, 'strip_delete_account_item' ], PHP_INT_MAX );
	}

	/**
	 * Temporarily hide the `mylisting-user-menu` theme location assignment
	 * so MyListing's dashboard/navigation.php template falls through to
	 * its `wc_get_account_menu_items()` branch, which runs our filter.
	 *
	 * @param array|false $locations
	 * @return array|false
	 */
	public function unassign_mylisting_user_menu( $locations ) {
		if ( is_array( $locations ) ) {
			unset( $locations['mylisting-user-menu'] );
		}
		return $locations;
	}

	/**
	 * Are we currently inside WC's own account navigation render?
	 */
	public function is_in_wc_nav() {
		return $this->in_wc_nav;
	}

	/**
	 * Replace WooCommerce menu items with the configured list when rendering
	 * WC's own navigation; return empty everywhere else so MyListing's
	 * dropdown (which iterates `wc_get_account_menu_items()`) stays clean.
	 *
	 * @param array $items
	 * @return array
	 */
	public function filter_menu_items( $items ) {
		if ( ! $this->in_wc_nav ) {
			return [];
		}

		$configured = FSA_AMM_Plugin::get_items();
		if ( empty( $configured ) ) {
			return $items;
		}

		$rendered          = [];
		$this->custom_urls = [];

		foreach ( $configured as $item ) {
			$label = isset( $item['label'] ) ? (string) $item['label'] : '';
			$type  = isset( $item['type'] )  ? (string) $item['type']  : 'endpoint';
			$id    = isset( $item['id'] )    ? (string) $item['id']    : '';

			if ( '' === $label ) {
				continue;
			}

			if ( 'endpoint' === $type ) {
				$key = isset( $item['key'] ) ? (string) $item['key'] : '';
				if ( '' === $key ) {
					continue;
				}
				$rendered[ $key ] = $label;

			} elseif ( 'page' === $type ) {
				$page_id = isset( $item['page_id'] ) ? absint( $item['page_id'] ) : 0;
				if ( ! $page_id ) {
					continue;
				}
				$url = get_permalink( $page_id );
				if ( ! $url ) {
					continue;
				}
				$key                         = 'fsa_custom_' . $id;
				$rendered[ $key ]            = $label;
				$this->custom_urls[ $key ]   = $url;

			} elseif ( 'url' === $type ) {
				$url = isset( $item['url'] ) ? (string) $item['url'] : '';
				if ( '' === $url ) {
					continue;
				}
				$key                       = 'fsa_custom_' . $id;
				$rendered[ $key ]          = $label;
				$this->custom_urls[ $key ] = $url;
			}
		}

		return $rendered;
	}

	/**
	 * Swap in the real URL for our synthetic custom endpoint keys.
	 *
	 * @param string $url
	 * @param string $endpoint
	 * @param string $value
	 * @param string $permalink
	 * @return string
	 */
	public function filter_endpoint_url( $url, $endpoint, $value, $permalink ) {
		if ( isset( $this->custom_urls[ $endpoint ] ) ) {
			return $this->custom_urls[ $endpoint ];
		}
		return $url;
	}

	/**
	 * Tag custom items with a class and strip any accidental `is-active`
	 * state WC might have bolted on (since synthetic keys aren't real
	 * endpoints, active detection would be wrong).
	 *
	 * @param array  $classes
	 * @param string $endpoint
	 * @return array
	 */
	public function filter_item_classes( $classes, $endpoint ) {
		if ( 0 === strpos( (string) $endpoint, 'fsa_custom_' ) ) {
			$classes   = array_diff( (array) $classes, [ 'is-active' ] );
			$classes[] = 'fsa-amm-custom-item';
		}
		return $classes;
	}

	/**
	 * Strip items that should not appear in the WooCommerce account
	 * navigation on /my-account/. Runs only inside the WC nav render span
	 * (registered lazily in enter_wc_nav()).
	 *
	 * Role-gated:
	 *  - Dashboard       — hidden from non-providers (no listing dashboard)
	 *  - Add a Profile   — hidden from non-providers
	 *
	 * Always stripped (in WC nav context):
	 *  - Delete Account  — belongs in the main account menu, not here
	 *
	 * Matching is fuzzy on both array key and visible label so injected
	 * items from any source (WP Frontend Delete Account, MyListing, manual
	 * entries) are caught regardless of how they're keyed.
	 *
	 * @param array $items
	 * @return array
	 */
	public function strip_delete_account_item( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}

		$hide_provider_items = ! $this->user_is_provider();

		foreach ( $items as $key => $label ) {
			$key_lc   = strtolower( (string) $key );
			$label_lc = strtolower( trim( wp_strip_all_tags( (string) $label ) ) );

			// Dashboard — hidden from non-providers only.
			if ( $hide_provider_items && ( 'dashboard' === $key_lc || 'dashboard' === $label_lc ) ) {
				unset( $items[ $key ] );
				continue;
			}

			// Add a Profile / Add Listing — hidden from non-providers only.
			if ( $hide_provider_items && (
				false !== strpos( $key_lc, 'add-profile' )
				|| false !== strpos( $key_lc, 'add_profile' )
				|| false !== strpos( $key_lc, 'add-listing' )
				|| false !== strpos( $key_lc, 'add_listing' )
				|| false !== strpos( $label_lc, 'add a profile' )
				|| false !== strpos( $label_lc, 'add profile' )
				|| false !== strpos( $label_lc, 'add a listing' )
				|| false !== strpos( $label_lc, 'add listing' )
			) ) {
				unset( $items[ $key ] );
				continue;
			}

			// Delete Account — always stripped from WC nav (belongs in main menu).
			if ( false !== strpos( $key_lc, 'delete' ) && false !== strpos( $key_lc, 'account' ) ) {
				unset( $items[ $key ] );
				continue;
			}
			if ( false !== strpos( $label_lc, 'delete account' ) ) {
				unset( $items[ $key ] );
				continue;
			}
		}

		return $items;
	}

	/**
	 * Whether the current user is a provider (and should see provider-only
	 * menu items like Dashboard and Add a Profile). Admins always count as
	 * providers for visibility purposes.
	 *
	 * @return bool
	 */
	private function user_is_provider() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user = wp_get_current_user();
		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}

		foreach ( (array) $user->roles as $role ) {
			$role_lc = strtolower( (string) $role );

			// Admins always count as providers for menu visibility.
			if ( 'administrator' === $role_lc ) {
				return true;
			}

			// Any role with "provider" in the slug — covers
			// registered_provider, pending_provider, churned_provider, and
			// any future provider role added later without code changes.
			if ( false !== strpos( $role_lc, 'provider' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove wpf-delete-account from the list of registered WC query vars
	 * so MyListing's theme-location fallback (which appears to iterate
	 * query vars directly) cannot render it either.
	 *
	 * Note: this intentionally does NOT unregister the rewrite, so the
	 * /my-account/wpf-delete-account/ URL itself still resolves if a user
	 * navigates there directly. It only hides the entry from discovery.
	 *
	 * @param array $vars
	 * @return array
	 */
	public function filter_query_vars( $vars ) {
		if ( ! is_array( $vars ) ) {
			return $vars;
		}
		unset( $vars['wpf-delete-account'] );
		unset( $vars['delete-account'] );
		return $vars;
	}

	/**
	 * Strip nav menu items that should not appear in the WooCommerce
	 * account navigation area on /my-account/.
	 *
	 * IMPORTANT: this only fires inside the WC nav render span. The main
	 * account menu (header dropdown, mylisting-user-menu) renders OUTSIDE
	 * this span, so Delete Account, Dashboard, and Add a Profile remain
	 * available there.
	 *
	 * Stripped (when inside WC nav):
	 *  - URLs pointing at the WP Frontend Delete Account endpoint
	 *  - Items titled "Delete Account"
	 *  - Items titled "Dashboard" (WC default — no listing dashboard here)
	 *  - Items titled "Add a Profile" / "Add Profile" / "Add Listing"
	 *  - URLs pointing at the add-listing page
	 *
	 * @param array    $items Array of WP_Post nav menu item objects.
	 * @param stdClass $args  wp_nav_menu args.
	 * @return array
	 */
	public function filter_nav_menu_objects( $items, $args ) {
		if ( ! is_array( $items ) || empty( $items ) ) {
			return $items;
		}

		// Only strip inside the WC account nav render. Leaving the main
		// account menu untouched is intentional — Delete Account belongs
		// there.
		if ( ! $this->in_wc_nav ) {
			return $items;
		}

		$hide_provider_items = ! $this->user_is_provider();

		// Delete Account is always stripped from WC nav.
		$url_patterns = [
			'/wpf-delete-account',
			'/delete-account',
		];
		$title_matches = [
			'delete account',
		];

		// Provider-only items are stripped only for non-providers.
		if ( $hide_provider_items ) {
			$url_patterns = array_merge( $url_patterns, [
				'/add-listing',
				'/add-profile',
			] );
			$title_matches = array_merge( $title_matches, [
				'dashboard',
				'add a profile',
				'add profile',
				'add a listing',
				'add listing',
			] );
		}

		$filtered    = [];
		$removed_ids = [];

		foreach ( $items as $item ) {
			$url   = isset( $item->url )   ? (string) $item->url   : '';
			$title = isset( $item->title ) ? strtolower( trim( wp_strip_all_tags( (string) $item->title ) ) ) : '';

			$should_remove = false;

			if ( '' !== $url ) {
				foreach ( $url_patterns as $needle ) {
					if ( false !== stripos( $url, $needle ) ) {
						$should_remove = true;
						break;
					}
				}
			}

			if ( ! $should_remove && '' !== $title ) {
				if ( in_array( $title, $title_matches, true ) ) {
					$should_remove = true;
				}
			}

			if ( $should_remove ) {
				$removed_ids[] = isset( $item->ID ) ? (int) $item->ID : 0;
				continue;
			}

			$filtered[] = $item;
		}

		// Second pass: also remove any orphaned children of removed parents.
		if ( ! empty( $removed_ids ) ) {
			$filtered = array_values( array_filter( $filtered, function ( $item ) use ( $removed_ids ) {
				$parent = isset( $item->menu_item_parent ) ? (int) $item->menu_item_parent : 0;
				return ! in_array( $parent, $removed_ids, true );
			} ) );
		}

		return $filtered;
	}
}
