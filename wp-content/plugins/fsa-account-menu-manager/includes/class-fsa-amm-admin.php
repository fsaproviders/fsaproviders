<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSA_AMM_Admin {

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'add_menu' ] );
		add_action( 'admin_init',            [ $this, 'handle_save' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Account Menu Manager', 'fsa-amm' ),
			__( 'Account Menu', 'fsa-amm' ),
			'manage_woocommerce',
			'fsa-amm',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_fsa-amm' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'fsa-amm-admin',
			FSA_AMM_URL . 'assets/admin.css',
			[],
			FSA_AMM_VERSION
		);
		wp_enqueue_script(
			'fsa-amm-admin',
			FSA_AMM_URL . 'assets/admin.js',
			[ 'jquery' ],
			FSA_AMM_VERSION,
			true
		);
	}

	public function handle_save() {
		if ( ! isset( $_POST['fsa_amm_save'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'fsa-amm' ) );
		}
		check_admin_referer( 'fsa_amm_save', 'fsa_amm_nonce' );

		$raw   = isset( $_POST['fsa_amm_items'] ) ? (array) wp_unslash( $_POST['fsa_amm_items'] ) : [];
		$items = [];

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$type  = isset( $row['type'] )  ? sanitize_key( $row['type'] ) : 'endpoint';
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			$id    = isset( $row['id'] ) && $row['id'] !== ''
				? sanitize_key( $row['id'] )
				: uniqid( 'item_' );

			if ( '' === $label ) {
				continue;
			}

			$item = [
				'id'    => $id,
				'type'  => $type,
				'label' => $label,
			];

			if ( 'endpoint' === $type ) {
				$key = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
				if ( '' === $key ) {
					continue;
				}
				$item['key'] = $key;

			} elseif ( 'page' === $type ) {
				$page_id = isset( $row['page_id'] ) ? absint( $row['page_id'] ) : 0;
				if ( ! $page_id ) {
					continue;
				}
				$item['page_id'] = $page_id;

			} elseif ( 'url' === $type ) {
				$url = isset( $row['url'] ) ? esc_url_raw( trim( $row['url'] ) ) : '';
				if ( '' === $url ) {
					continue;
				}
				$item['url'] = $url;

			} else {
				continue;
			}

			$items[] = $item;
		}

		FSA_AMM_Plugin::save_items( $items );
		add_settings_error( 'fsa_amm', 'fsa_amm_saved', __( 'Menu saved.', 'fsa-amm' ), 'updated' );
	}

	public function render_page() {
		$items = FSA_AMM_Plugin::get_items();
		if ( empty( $items ) ) {
			$items = FSA_AMM_Plugin::default_items();
		}

		$wc_endpoints = $this->get_available_endpoints();
		$pages        = get_pages( [
			'sort_column' => 'post_title',
			'number'      => 500,
		] );
		if ( ! is_array( $pages ) ) {
			$pages = [];
		}
		?>
		<div class="wrap fsa-amm-wrap">
			<h1><?php esc_html_e( 'Account Menu Manager', 'fsa-amm' ); ?></h1>

			<div class="fsa-amm-intro">
				<p>
					<?php esc_html_e( 'Control the WooCommerce My Account menu shown on /my-account/. Items below replace the default WooCommerce menu.', 'fsa-amm' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'MyListing:', 'fsa-amm' ); ?></strong>
					<?php esc_html_e( 'WooCommerce endpoints are automatically stripped from the MyListing user dropdown in the site header. MyListing\'s own items (Bookmarks, Listings, etc.) are unaffected.', 'fsa-amm' ); ?>
				</p>
			</div>

			<?php settings_errors( 'fsa_amm' ); ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'fsa_amm_save', 'fsa_amm_nonce' ); ?>

				<div class="fsa-amm-items" id="fsa-amm-items">
					<?php foreach ( $items as $index => $item ) : ?>
						<?php $this->render_item_row( $item, (string) $index, $wc_endpoints, $pages ); ?>
					<?php endforeach; ?>
				</div>

				<div class="fsa-amm-add">
					<button type="button" class="button" id="fsa-amm-add-endpoint">
						<?php esc_html_e( '+ Add WooCommerce Endpoint', 'fsa-amm' ); ?>
					</button>
					<button type="button" class="button" id="fsa-amm-add-page">
						<?php esc_html_e( '+ Add Page', 'fsa-amm' ); ?>
					</button>
					<button type="button" class="button" id="fsa-amm-add-url">
						<?php esc_html_e( '+ Add Custom URL', 'fsa-amm' ); ?>
					</button>
				</div>

				<p class="submit">
					<button type="submit" name="fsa_amm_save" class="button button-primary button-large">
						<?php esc_html_e( 'Save Menu', 'fsa-amm' ); ?>
					</button>
				</p>
			</form>

			<template id="fsa-amm-tpl-endpoint"><?php
				$this->render_item_row(
					[ 'id' => '', 'type' => 'endpoint', 'key' => '', 'label' => '' ],
					'__INDEX__',
					$wc_endpoints,
					$pages
				);
			?></template>
			<template id="fsa-amm-tpl-page"><?php
				$this->render_item_row(
					[ 'id' => '', 'type' => 'page', 'page_id' => 0, 'label' => '' ],
					'__INDEX__',
					$wc_endpoints,
					$pages
				);
			?></template>
			<template id="fsa-amm-tpl-url"><?php
				$this->render_item_row(
					[ 'id' => '', 'type' => 'url', 'url' => '', 'label' => '' ],
					'__INDEX__',
					$wc_endpoints,
					$pages
				);
			?></template>
		</div>
		<?php
	}

	/**
	 * Render one row of the repeater.
	 */
	private function render_item_row( array $item, $index, array $wc_endpoints, array $pages ) {
		$type  = isset( $item['type'] )  ? $item['type']  : 'endpoint';
		$id    = isset( $item['id'] )    ? $item['id']    : '';
		$label = isset( $item['label'] ) ? $item['label'] : '';
		$name  = 'fsa_amm_items[' . $index . ']';
		?>
		<div class="fsa-amm-item" data-type="<?php echo esc_attr( $type ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]"   value="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[type]" value="<?php echo esc_attr( $type ); ?>">

			<div class="fsa-amm-handle">
				<button type="button" class="button fsa-amm-up"   title="<?php esc_attr_e( 'Move up', 'fsa-amm' ); ?>">&#9650;</button>
				<button type="button" class="button fsa-amm-down" title="<?php esc_attr_e( 'Move down', 'fsa-amm' ); ?>">&#9660;</button>
			</div>

			<div class="fsa-amm-type-badge"><?php echo esc_html( $this->type_label( $type ) ); ?></div>

			<div class="fsa-amm-fields">
				<label class="fsa-amm-label-field">
					<span><?php esc_html_e( 'Label', 'fsa-amm' ); ?></span>
					<input type="text"
					       name="<?php echo esc_attr( $name ); ?>[label]"
					       value="<?php echo esc_attr( $label ); ?>"
					       placeholder="<?php esc_attr_e( 'Menu label', 'fsa-amm' ); ?>">
				</label>

				<?php if ( 'endpoint' === $type ) : ?>
					<label class="fsa-amm-target-field">
						<span><?php esc_html_e( 'WooCommerce Endpoint', 'fsa-amm' ); ?></span>
						<select name="<?php echo esc_attr( $name ); ?>[key]">
							<option value=""><?php esc_html_e( '— Select endpoint —', 'fsa-amm' ); ?></option>
							<?php foreach ( $wc_endpoints as $ep_key => $ep_label ) : ?>
								<option value="<?php echo esc_attr( $ep_key ); ?>" <?php selected( isset( $item['key'] ) ? $item['key'] : '', $ep_key ); ?>>
									<?php echo esc_html( $ep_label ); ?> (<?php echo esc_html( $ep_key ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
					</label>

				<?php elseif ( 'page' === $type ) : ?>
					<label class="fsa-amm-target-field">
						<span><?php esc_html_e( 'Page', 'fsa-amm' ); ?></span>
						<select name="<?php echo esc_attr( $name ); ?>[page_id]">
							<option value=""><?php esc_html_e( '— Select page —', 'fsa-amm' ); ?></option>
							<?php foreach ( $pages as $page ) : ?>
								<option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( isset( $item['page_id'] ) ? $item['page_id'] : 0, $page->ID ); ?>>
									<?php echo esc_html( $page->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>

				<?php elseif ( 'url' === $type ) : ?>
					<label class="fsa-amm-target-field">
						<span><?php esc_html_e( 'URL', 'fsa-amm' ); ?></span>
						<input type="url"
						       name="<?php echo esc_attr( $name ); ?>[url]"
						       value="<?php echo esc_attr( isset( $item['url'] ) ? $item['url'] : '' ); ?>"
						       placeholder="https://example.com/path">
					</label>
				<?php endif; ?>
			</div>

			<div class="fsa-amm-actions">
				<button type="button" class="button-link-delete fsa-amm-remove">
					<?php esc_html_e( 'Remove', 'fsa-amm' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	private function type_label( $type ) {
		switch ( $type ) {
			case 'endpoint': return __( 'Endpoint', 'fsa-amm' );
			case 'page':     return __( 'Page', 'fsa-amm' );
			case 'url':      return __( 'URL', 'fsa-amm' );
		}
		return $type;
	}

	/**
	 * Discover every WooCommerce account endpoint currently registered,
	 * including those added by extensions (Subscriptions, Memberships,
	 * Role History, etc.). We temporarily detach our own filter so we
	 * get the real list with human-readable labels.
	 *
	 * @return array key => label
	 */
	private function get_available_endpoints() {
		if ( ! function_exists( 'wc_get_account_menu_items' ) ) {
			return [];
		}

		$renderer = FSA_AMM_Plugin::instance()->renderer;
		remove_filter( 'woocommerce_account_menu_items', [ $renderer, 'filter_menu_items' ], 999 );

		// Temporarily flag as in-nav so any other context-aware filters behave.
		$was_in_nav = $renderer->is_in_wc_nav();
		$renderer->enter_wc_nav();

		$items = wc_get_account_menu_items();

		if ( ! $was_in_nav ) {
			$renderer->exit_wc_nav();
		}
		add_filter( 'woocommerce_account_menu_items', [ $renderer, 'filter_menu_items' ], 999 );

		return is_array( $items ) ? $items : [];
	}
}
