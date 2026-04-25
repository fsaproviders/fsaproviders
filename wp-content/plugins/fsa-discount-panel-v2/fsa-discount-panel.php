<?php
/**
 * Plugin Name: FSA Discount Panel
 * Description: Displays discount panels on MyListing listing pages based on user role. Supports separate shortcodes for individual and corporate pricing.
 * Version: 1.4.2
 * Author: FSA/HSA Providers
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// -------------------------------------------------------
// Admin Settings Page
// -------------------------------------------------------

add_action( 'admin_menu', 'fdp_register_settings_page' );
function fdp_register_settings_page() {
    add_options_page(
        'FSA Discount Panel Settings',
        'FSA Discount Panel',
        'manage_options',
        'fsa-discount-panel',
        'fdp_render_settings_page'
    );
}

add_action( 'admin_init', 'fdp_register_settings' );
function fdp_register_settings() {
    // URLs
    register_setting( 'fdp_settings_group', 'fdp_upgrade_url' );
    register_setting( 'fdp_settings_group', 'fdp_claim_url' );

    // Meta keys
    register_setting( 'fdp_settings_group', 'fdp_meta_key_individual' );
    register_setting( 'fdp_settings_group', 'fdp_meta_key_corporate' );

    // Role lists
    register_setting( 'fdp_settings_group', 'fdp_roles_individual' );
    register_setting( 'fdp_settings_group', 'fdp_roles_corporate' );
    register_setting( 'fdp_settings_group', 'fdp_roles_provider' );
    register_setting( 'fdp_settings_group', 'fdp_roles_free' );

    // Panel titles
    register_setting( 'fdp_settings_group', 'fdp_title_individual_panel' );
    register_setting( 'fdp_settings_group', 'fdp_title_corporate_panel' );

    // Panel text
    register_setting( 'fdp_settings_group', 'fdp_text_individual_heading' );
    register_setting( 'fdp_settings_group', 'fdp_text_corporate_heading' );
    register_setting( 'fdp_settings_group', 'fdp_text_paid_unclaimed_message' );
    register_setting( 'fdp_settings_group', 'fdp_text_paid_unclaimed_button' );
    register_setting( 'fdp_settings_group', 'fdp_text_free_message' );
    register_setting( 'fdp_settings_group', 'fdp_text_free_button' );
    register_setting( 'fdp_settings_group', 'fdp_text_provider_message' );
    register_setting( 'fdp_settings_group', 'fdp_text_provider_button' );

    // Tab visibility
    register_setting( 'fdp_settings_group', 'fdp_hide_tabs_for_free_users' );
}

function fdp_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>FSA Discount Panel Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'fdp_settings_group' ); ?>

            <h2 class="title">URLs</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="fdp_upgrade_url">Upgrade Button URL</label></th>
                    <td>
                        <input type="url" id="fdp_upgrade_url" name="fdp_upgrade_url"
                            value="<?php echo esc_attr( get_option( 'fdp_upgrade_url', '' ) ); ?>"
                            class="regular-text" placeholder="https://yoursite.com/upgrade" />
                        <p class="description">Where free users are sent when they click the upgrade button.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fdp_claim_url">Claim Profile Button URL</label></th>
                    <td>
                        <input type="text" id="fdp_claim_url" name="fdp_claim_url"
                            value="<?php echo esc_attr( get_option( 'fdp_claim_url', '' ) ); ?>"
                            class="regular-text" placeholder="https://yoursite.com/claim?listing={post_id}" />
                        <p class="description">Use <code>{post_id}</code> to insert the listing's Post ID.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Field Keys</h2>
            <p>Enter the meta keys for your MyListing General Repeater fields. <strong>Include the leading underscore.</strong> Use <code>?fdp_debug=1</code> on any listing to see all available meta keys.</p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="fdp_meta_key_individual">Individual Pricing Meta Key</label></th>
                    <td>
                        <input type="text" id="fdp_meta_key_individual" name="fdp_meta_key_individual"
                            value="<?php echo esc_attr( get_option( 'fdp_meta_key_individual', '_individual-subscriber-products-pricing' ) ); ?>"
                            class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fdp_meta_key_corporate">Corporate Pricing Meta Key</label></th>
                    <td>
                        <input type="text" id="fdp_meta_key_corporate" name="fdp_meta_key_corporate"
                            value="<?php echo esc_attr( get_option( 'fdp_meta_key_corporate', '_corporate-products-pricing' ) ); ?>"
                            class="regular-text" />
                    </td>
                </tr>
            </table>

            <h2 class="title">Role Mapping</h2>
            <p>Configure which WordPress roles see each panel. Comma-separated. Admins always see all panels.</p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="fdp_roles_individual">Individual Subscriber Roles</label></th>
                    <td>
                        <input type="text" id="fdp_roles_individual" name="fdp_roles_individual"
                            value="<?php echo esc_attr( get_option( 'fdp_roles_individual', 'individual-subscriber' ) ); ?>"
                            class="large-text" />
                        <p class="description">See individual pricing panel.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fdp_roles_corporate">Corporate Subscriber Roles</label></th>
                    <td>
                        <input type="text" id="fdp_roles_corporate" name="fdp_roles_corporate"
                            value="<?php echo esc_attr( get_option( 'fdp_roles_corporate', 'employee, corporate-provider, corporate-subscriber' ) ); ?>"
                            class="large-text" />
                        <p class="description">See both individual AND corporate pricing panels.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fdp_roles_provider">Provider Roles</label></th>
                    <td>
                        <input type="text" id="fdp_roles_provider" name="fdp_roles_provider"
                            value="<?php echo esc_attr( get_option( 'fdp_roles_provider', 'provider, registered_provider, pending_provider, churned_provider, free_provider' ) ); ?>"
                            class="large-text" />
                        <p class="description">See claim profile CTA.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fdp_roles_free">Free User Roles</label></th>
                    <td>
                        <input type="text" id="fdp_roles_free" name="fdp_roles_free"
                            value="<?php echo esc_attr( get_option( 'fdp_roles_free', 'individual, subscriber, customer' ) ); ?>"
                            class="large-text" />
                        <p class="description">See upgrade CTA.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Panel Titles</h2>
            <p>These titles appear at the top of each pricing panel.</p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="fdp_title_individual_panel">Individual Panel Title</label></th>
                    <td>
                        <input type="text" id="fdp_title_individual_panel" name="fdp_title_individual_panel"
                            value="<?php echo esc_attr( get_option( 'fdp_title_individual_panel', 'Your Member Discounts' ) ); ?>"
                            class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fdp_title_corporate_panel">Corporate Panel Title</label></th>
                    <td>
                        <input type="text" id="fdp_title_corporate_panel" name="fdp_title_corporate_panel"
                            value="<?php echo esc_attr( get_option( 'fdp_title_corporate_panel', 'Corporate Member Discounts' ) ); ?>"
                            class="regular-text" />
                    </td>
                </tr>
            </table>

            <h2 class="title">CTA Messages</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Unclaimed Profile (Paid Users)</th>
                    <td>
                        <input type="text" name="fdp_text_paid_unclaimed_message"
                            value="<?php echo esc_attr( get_option( 'fdp_text_paid_unclaimed_message', "This provider hasn't claimed their profile yet." ) ); ?>"
                            class="large-text" style="margin-bottom:8px;" />
                        <input type="text" name="fdp_text_paid_unclaimed_button"
                            value="<?php echo esc_attr( get_option( 'fdp_text_paid_unclaimed_button', 'Claim Your Profile' ) ); ?>"
                            class="regular-text" placeholder="Button text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Free User CTA</th>
                    <td>
                        <input type="text" name="fdp_text_free_message"
                            value="<?php echo esc_attr( get_option( 'fdp_text_free_message', 'This provider offers exclusive discounts to members.' ) ); ?>"
                            class="large-text" style="margin-bottom:8px;" />
                        <input type="text" name="fdp_text_free_button"
                            value="<?php echo esc_attr( get_option( 'fdp_text_free_button', 'Subscribe to access discounts' ) ); ?>"
                            class="regular-text" placeholder="Button text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Provider CTA</th>
                    <td>
                        <input type="text" name="fdp_text_provider_message"
                            value="<?php echo esc_attr( get_option( 'fdp_text_provider_message', 'Is this your practice? Claim your profile to add your FSA/HSA discount.' ) ); ?>"
                            class="large-text" style="margin-bottom:8px;" />
                        <input type="text" name="fdp_text_provider_button"
                            value="<?php echo esc_attr( get_option( 'fdp_text_provider_button', 'Claim Your Profile' ) ); ?>"
                            class="regular-text" placeholder="Button text" />
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr />
        <h2 class="title">Shortcodes</h2>
        <p>Use these shortcodes in your MyListing Single Listing page template:</p>
        <table class="widefat" style="max-width:700px;">
            <thead>
                <tr><th>Shortcode</th><th>Description</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>[fsa_discount_panel]</code></td>
                    <td>Shows the appropriate panel(s) based on user role. Use in a single tab.</td>
                </tr>
                <tr>
                    <td><code>[fsa_individual_panel]</code></td>
                    <td>Shows individual pricing <strong>OR upgrade CTA</strong> for non-subscribers. Place in the Individual Subscriber tab.</td>
                </tr>
                <tr>
                    <td><code>[fsa_corporate_panel]</code></td>
                    <td>Shows corporate pricing <strong>OR upgrade CTA</strong> for non-corporate users. Place in the Corporate tab.</td>
                </tr>
            </tbody>
        </table>
        
        <div style="background:#fff8e6;border:1px solid #ffe082;border-radius:4px;padding:12px 16px;margin:16px 0 0;max-width:700px;">
            <strong>⚠️ Tab Visibility in MyListing:</strong><br>
            If free users can't see your pricing tabs, adjust visibility using the <strong>{jejesuspended} Display Rules</strong> plugin in your Listing Type settings.
        </div>

        <hr />
        <h2 class="title">Debug: Current User</h2>
        <?php fdp_render_debug_info(); ?>
    </div>
    <?php
}

function fdp_render_debug_info() {
    if ( ! is_user_logged_in() ) {
        echo '<p><strong>Status:</strong> Not logged in → Upgrade CTA</p>';
        return;
    }

    $user = wp_get_current_user();
    $roles = (array) $user->roles;
    $panel_state = fdp_get_user_panel_state();

    $state_labels = [
        'admin'      => '👑 Admin (sees all panels)',
        'corporate'  => '🏢 Corporate (sees both panels)',
        'individual' => '👤 Individual (sees individual panel)',
        'provider'   => '🏥 Provider (sees claim CTA)',
        'free'       => '🆓 Free (sees upgrade CTA)',
        'guest'      => '👋 Guest (sees upgrade CTA)',
    ];

    echo '<table class="widefat" style="max-width:600px;">';
    echo '<tr><th style="width:180px;">Username</th><td>' . esc_html( $user->user_login ) . '</td></tr>';
    echo '<tr><th>Roles</th><td><code>' . esc_html( implode( ', ', $roles ) ) . '</code></td></tr>';
    echo '<tr style="background:#f0f6fc;"><th><strong>Panel State</strong></th><td><strong>' . esc_html( $state_labels[ $panel_state ] ?? $panel_state ) . '</strong></td></tr>';
    echo '</table>';

    // Show all registered roles
    echo '<h3 style="margin-top:20px;">All WordPress Roles</h3>';
    global $wp_roles;
    echo '<p><code>' . esc_html( implode( ', ', array_keys( $wp_roles->get_names() ) ) ) . '</code></p>';
}

// -------------------------------------------------------
// Role Helpers
// -------------------------------------------------------

function fdp_parse_roles( $option_key, $default = '' ) {
    $raw = get_option( $option_key, $default );
    if ( empty( $raw ) ) return [];
    $roles = array_map( 'trim', explode( ',', $raw ) );
    $roles = array_map( 'strtolower', $roles );
    return array_filter( $roles );
}

function fdp_user_has_role( array $roles ) {
    if ( ! is_user_logged_in() ) return false;
    if ( empty( $roles ) ) return false;
    $user = wp_get_current_user();
    $user_roles = array_map( 'strtolower', (array) $user->roles );
    return (bool) array_intersect( $roles, $user_roles );
}

function fdp_is_admin_user() {
    if ( ! is_user_logged_in() ) return false;
    return current_user_can( 'manage_options' );
}

function fdp_is_individual_subscriber() {
    $roles = fdp_parse_roles( 'fdp_roles_individual', 'individual-subscriber' );
    return fdp_user_has_role( $roles );
}

function fdp_is_corporate_subscriber() {
    $roles = fdp_parse_roles( 'fdp_roles_corporate', 'employee, corporate-provider, corporate-subscriber' );
    return fdp_user_has_role( $roles );
}

function fdp_is_provider_user() {
    $roles = fdp_parse_roles( 'fdp_roles_provider', 'provider, registered_provider, pending_provider, churned_provider, free_provider' );
    return fdp_user_has_role( $roles );
}

function fdp_is_free_user() {
    $roles = fdp_parse_roles( 'fdp_roles_free', 'individual, subscriber, customer' );
    return fdp_user_has_role( $roles );
}

/**
 * Determine panel state for current user.
 * Priority: admin > corporate > individual > provider > free > guest
 */
function fdp_get_user_panel_state() {
    if ( ! is_user_logged_in() ) return 'guest';
    if ( fdp_is_admin_user() ) return 'admin';
    if ( fdp_is_corporate_subscriber() ) return 'corporate';
    if ( fdp_is_individual_subscriber() ) return 'individual';
    if ( fdp_is_provider_user() ) return 'provider';
    if ( fdp_is_free_user() ) return 'free';
    return 'free'; // fallback
}

/**
 * Check if user can see individual pricing.
 */
function fdp_can_see_individual() {
    $state = fdp_get_user_panel_state();
    return in_array( $state, [ 'admin', 'corporate', 'individual' ], true );
}

/**
 * Check if user can see corporate pricing.
 */
function fdp_can_see_corporate() {
    $state = fdp_get_user_panel_state();
    return in_array( $state, [ 'admin', 'corporate' ], true );
}

// -------------------------------------------------------
// Listing Helpers
// -------------------------------------------------------

function fdp_is_unclaimed_listing( $post_id ) {
    $listing_type = get_post_meta( $post_id, '_case27_listing_type', true );
    return ( $listing_type === 'unclaimed-profiles' );
}

function fdp_resolve_claim_url( $post_id ) {
    $raw = get_option( 'fdp_claim_url', '#' );
    return str_replace( '{post_id}', absint( $post_id ), $raw );
}

function fdp_get_repeater_rows( $post_id, $meta_key ) {
    $raw = get_post_meta( $post_id, $meta_key, true );
    if ( empty( $raw ) ) return [];
    if ( is_array( $raw ) ) return $raw;
    $data = maybe_unserialize( $raw );
    return is_array( $data ) ? $data : [];
}

// -------------------------------------------------------
// Debug: Show all meta keys
// -------------------------------------------------------

add_action( 'mylisting/single/before-content', 'fdp_maybe_dump_meta' );
add_action( 'wp_head', 'fdp_maybe_dump_meta_fallback', 999 );

function fdp_maybe_dump_meta_fallback() {
    if ( ! is_singular( 'job_listing' ) ) return;
    fdp_maybe_dump_meta();
}

function fdp_maybe_dump_meta() {
    static $already_ran = false;
    if ( $already_ran ) return;
    
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( empty( $_GET['fdp_debug'] ) ) return;

    $post_id = get_the_ID();
    if ( ! $post_id ) {
        global $post;
        $post_id = $post ? $post->ID : 0;
    }
    if ( ! $post_id ) return;
    
    $already_ran = true;

    $meta = get_post_meta( $post_id );
    
    // Get configured keys
    $meta_key_individual = get_option( 'fdp_meta_key_individual', '_individual-subscriber-products-pricing' );
    $meta_key_corporate = get_option( 'fdp_meta_key_corporate', '_corporate-products-pricing' );
    
    echo '<div style="position:relative;z-index:99999;background:#fff;border:3px solid #0073aa;padding:20px;margin:20px;font-family:monospace;font-size:12px;max-height:800px;overflow:auto;">';
    echo '<strong style="font-size:16px;color:#0073aa;">🔧 FDP Debug — Post ID: ' . $post_id . '</strong><br><br>';
    
    // Show configured keys
    echo '<strong>Configured Meta Keys:</strong><br>';
    echo 'Individual: <code style="background:#f0f0f0;padding:2px 6px;">' . esc_html( $meta_key_individual ) . '</code><br>';
    echo 'Corporate: <code style="background:#f0f0f0;padding:2px 6px;">' . esc_html( $meta_key_corporate ) . '</code><br><br>';
    
    // Show all meta keys (highlight pricing-related)
    echo '<strong>All Meta Keys (pricing-related highlighted in yellow):</strong><br>';
    echo '<div style="background:#f9f9f9;padding:10px;max-height:200px;overflow:auto;border:1px solid #ddd;margin:5px 0 15px;">';
    foreach ( $meta as $key => $values ) {
        $highlight = ( stripos( $key, 'pricing' ) !== false || stripos( $key, 'product' ) !== false || stripos( $key, 'corporate' ) !== false || stripos( $key, 'individual' ) !== false || stripos( $key, 'discount' ) !== false );
        $style = $highlight ? 'background:#ff0;font-weight:bold;padding:2px 4px;' : '';
        echo '<span style="' . $style . '">' . esc_html( $key ) . '</span><br>';
    }
    echo '</div>';
    
    // Dump actual repeater data for individual
    echo '<strong style="color:#0073aa;">📋 Individual Pricing Data (' . esc_html( $meta_key_individual ) . '):</strong><br>';
    $ind_rows = fdp_get_repeater_rows( $post_id, $meta_key_individual );
    if ( $ind_rows ) {
        echo '<pre style="background:#e8f5e9;padding:10px;overflow:auto;max-height:300px;border:1px solid #a5d6a7;white-space:pre-wrap;word-wrap:break-word;">' . esc_html( print_r( $ind_rows, true ) ) . '</pre>';
    } else {
        echo '<p style="color:#c00;"><em>No data found at this key</em></p>';
    }
    
    // Dump actual repeater data for corporate
    echo '<br><strong style="color:#0073aa;">📋 Corporate Pricing Data (' . esc_html( $meta_key_corporate ) . '):</strong><br>';
    $corp_rows = fdp_get_repeater_rows( $post_id, $meta_key_corporate );
    if ( $corp_rows ) {
        echo '<pre style="background:#e3f2fd;padding:10px;overflow:auto;max-height:300px;border:1px solid #90caf9;white-space:pre-wrap;word-wrap:break-word;">' . esc_html( print_r( $corp_rows, true ) ) . '</pre>';
    } else {
        echo '<p style="color:#c00;"><em>No data found at this key</em></p>';
    }
    
    echo '<br><p style="color:#666;font-size:11px;">Remove <code>?fdp_debug=1</code> from URL to hide this panel.</p>';
    echo '</div>';
}

// -------------------------------------------------------
// Repeater Renderer — All fields with live links
// -------------------------------------------------------

function fdp_render_repeater_rows( array $rows ) {
    if ( empty( $rows ) ) return '';

    ob_start();
    foreach ( $rows as $row ) {
        // MyListing General Repeater field mapping
        // Based on actual data structure from debug output
        $name        = isset( $row['menu-label'] )       ? trim( $row['menu-label'] )       : '';
        $price       = isset( $row['menu-price'] )       ? trim( $row['menu-price'] )       : '';
        $desc        = isset( $row['menu-description'] ) ? trim( $row['menu-description'] ) : '';
        $photo       = isset( $row['mylisting_accordion_photo'] ) ? trim( $row['mylisting_accordion_photo'] ) : '';
        
        // URL can be in either field - check both
        $url         = isset( $row['link-label'] ) && filter_var( trim( $row['link-label'] ), FILTER_VALIDATE_URL ) 
                        ? trim( $row['link-label'] ) 
                        : ( isset( $row['menu-url'] ) ? trim( $row['menu-url'] ) : '' );
        
        // Second URL field (if link-label is used for URL, menu-url might have second link)
        $url2        = isset( $row['menu-url'] ) && filter_var( trim( $row['menu-url'] ), FILTER_VALIDATE_URL )
                        ? trim( $row['menu-url'] )
                        : '';
        
        // Handle photo as array (MyListing sometimes stores as array with 'url' key)
        if ( is_array( $photo ) && isset( $photo['url'] ) ) {
            $photo = $photo['url'];
        } elseif ( is_numeric( $photo ) ) {
            $photo = wp_get_attachment_url( $photo );
        }

        if ( ! $name && ! $price ) continue;
        ?>
        <div class="fdp-item">
            <?php if ( $photo ) : ?>
                <div class="fdp-item__image">
                    <img src="<?php echo esc_url( $photo ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy" />
                </div>
            <?php endif; ?>
            <div class="fdp-item__body">
                <div class="fdp-item__header">
                    <?php if ( $name ) : ?>
                        <span class="fdp-item__name"><?php echo esc_html( $name ); ?></span>
                    <?php endif; ?>
                    <?php if ( $price ) : ?>
                        <span class="fdp-item__price"><?php echo esc_html( $price ); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ( $desc ) : ?>
                    <p class="fdp-item__desc"><?php echo wp_kses_post( $desc ); ?></p>
                <?php endif; ?>
                <?php if ( $url || $url2 ) : ?>
                    <div class="fdp-item__links">
                        <?php if ( $url ) : ?>
                            <a href="<?php echo esc_url( $url ); ?>" class="fdp-item__link" target="_blank" rel="noopener noreferrer">
                                Book Now
                            </a>
                        <?php endif; ?>
                        <?php if ( $url2 ) : ?>
                            <a href="<?php echo esc_url( $url2 ); ?>" class="fdp-item__link fdp-item__link--secondary" target="_blank" rel="noopener noreferrer">
                                Learn More
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    return ob_get_clean();
}

// -------------------------------------------------------
// Panel Renderers
// -------------------------------------------------------

/**
 * Render the individual pricing panel.
 */
function fdp_render_individual_panel( $post_id, $show_admin_label = false ) {
    $meta_key = get_option( 'fdp_meta_key_individual', '_individual-subscriber-products-pricing' );
    $title = get_option( 'fdp_title_individual_panel', 'Your Member Discounts' );
    $rows = fdp_get_repeater_rows( $post_id, $meta_key );

    if ( ! $rows && ! fdp_is_admin_user() ) return '';

    ob_start();
    
    if ( $show_admin_label && fdp_is_admin_user() ) {
        echo '<div class="fdp-admin-label">👤 Individual Subscriber View</div>';
    }
    
    if ( $rows ) {
        ?>
        <div class="fdp-panel fdp-panel--pricing">
            <?php if ( $title ) : ?>
                <h3 class="fdp-panel__title"><?php echo esc_html( $title ); ?></h3>
            <?php endif; ?>
            <div class="fdp-items">
                <?php echo fdp_render_repeater_rows( $rows ); ?>
            </div>
        </div>
        <?php
    } elseif ( fdp_is_admin_user() ) {
        ?>
        <div class="fdp-panel fdp-panel--notice">
            <p>No individual pricing data found. Meta key: <code><?php echo esc_html( $meta_key ); ?></code></p>
        </div>
        <?php
    }
    
    return ob_get_clean();
}

/**
 * Render the corporate pricing panel.
 */
function fdp_render_corporate_panel( $post_id, $show_admin_label = false ) {
    $meta_key = get_option( 'fdp_meta_key_corporate', '_corporate-products-pricing' );
    $title = get_option( 'fdp_title_corporate_panel', 'Corporate Member Discounts' );
    $rows = fdp_get_repeater_rows( $post_id, $meta_key );

    if ( ! $rows && ! fdp_is_admin_user() ) return '';

    ob_start();
    
    if ( $show_admin_label && fdp_is_admin_user() ) {
        echo '<div class="fdp-admin-label">🏢 Corporate Subscriber View</div>';
    }
    
    if ( $rows ) {
        ?>
        <div class="fdp-panel fdp-panel--pricing">
            <?php if ( $title ) : ?>
                <h3 class="fdp-panel__title"><?php echo esc_html( $title ); ?></h3>
            <?php endif; ?>
            <div class="fdp-items">
                <?php echo fdp_render_repeater_rows( $rows ); ?>
            </div>
        </div>
        <?php
    } elseif ( fdp_is_admin_user() ) {
        ?>
        <div class="fdp-panel fdp-panel--notice">
            <p>No corporate pricing data found. Meta key: <code><?php echo esc_html( $meta_key ); ?></code></p>
        </div>
        <?php
    }
    
    return ob_get_clean();
}

/**
 * Render upgrade CTA panel.
 */
function fdp_render_upgrade_cta( $post_id ) {
    $url = get_option( 'fdp_upgrade_url', '#' );
    $message = get_option( 'fdp_text_free_message', 'This provider offers exclusive discounts to members.' );
    $button = get_option( 'fdp_text_free_button', 'Subscribe to access discounts' );

    ob_start();
    ?>
    <div class="fdp-panel fdp-panel--cta fdp-panel--upgrade">
        <p class="fdp-panel__message"><?php echo esc_html( $message ); ?></p>
        <a href="<?php echo esc_url( $url ); ?>" class="fdp-panel__button">
            <?php echo esc_html( $button ); ?>
        </a>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render claim CTA panel.
 */
function fdp_render_claim_cta( $post_id ) {
    $url = fdp_resolve_claim_url( $post_id );
    $message = get_option( 'fdp_text_provider_message', 'Is this your practice? Claim your profile to add your FSA/HSA discount.' );
    $button = get_option( 'fdp_text_provider_button', 'Claim Your Profile' );

    ob_start();
    ?>
    <div class="fdp-panel fdp-panel--cta fdp-panel--claim">
        <p class="fdp-panel__message"><?php echo esc_html( $message ); ?></p>
        <a href="<?php echo esc_url( $url ); ?>" class="fdp-panel__button">
            <?php echo esc_html( $button ); ?>
        </a>
    </div>
    <?php
    return ob_get_clean();
}

// -------------------------------------------------------
// Main Panel Logic
// -------------------------------------------------------

function fdp_render_panel( $post_id ) {
    $state = fdp_get_user_panel_state();
    $is_unclaimed = fdp_is_unclaimed_listing( $post_id );

    ob_start();

    switch ( $state ) {
        case 'admin':
            // Admins see all panels
            echo fdp_render_individual_panel( $post_id, true );
            echo fdp_render_corporate_panel( $post_id, true );
            break;

        case 'corporate':
            // Corporate users see both panels
            if ( $is_unclaimed ) {
                echo fdp_render_claim_cta( $post_id );
            } else {
                echo fdp_render_individual_panel( $post_id );
                echo fdp_render_corporate_panel( $post_id );
            }
            break;

        case 'individual':
            // Individual subscribers see individual panel only
            if ( $is_unclaimed ) {
                echo fdp_render_claim_cta( $post_id );
            } else {
                echo fdp_render_individual_panel( $post_id );
            }
            break;

        case 'provider':
            // Providers see claim CTA
            echo fdp_render_claim_cta( $post_id );
            break;

        case 'free':
        case 'guest':
        default:
            // Free users and guests see upgrade CTA
            echo fdp_render_upgrade_cta( $post_id );
            break;
    }

    return ob_get_clean();
}

// -------------------------------------------------------
// Shortcodes
// -------------------------------------------------------

// Main shortcode — shows appropriate panel(s) based on role
add_shortcode( 'fsa_discount_panel', function( $atts ) {
    $post_id = get_the_ID();
    if ( ! $post_id ) return '';
    return fdp_render_panel( $post_id );
});

// Individual panel only — for placing in dedicated tab
add_shortcode( 'fsa_individual_panel', function( $atts ) {
    $post_id = get_the_ID();
    if ( ! $post_id ) return '';
    
    // Check if user can see this panel
    if ( ! fdp_can_see_individual() ) {
        return fdp_render_upgrade_cta( $post_id );
    }
    
    if ( fdp_is_unclaimed_listing( $post_id ) ) {
        $msg = get_option( 'fdp_text_paid_unclaimed_message', "This provider hasn't claimed their profile yet." );
        return '<div class="fdp-panel fdp-panel--notice"><p>' . esc_html( $msg ) . '</p></div>';
    }
    
    return fdp_render_individual_panel( $post_id );
});

// Corporate panel only — for placing in dedicated tab
add_shortcode( 'fsa_corporate_panel', function( $atts ) {
    $post_id = get_the_ID();
    if ( ! $post_id ) return '';
    
    // Check if user can see this panel
    if ( ! fdp_can_see_corporate() ) {
        return fdp_render_upgrade_cta( $post_id );
    }
    
    if ( fdp_is_unclaimed_listing( $post_id ) ) {
        $msg = get_option( 'fdp_text_paid_unclaimed_message', "This provider hasn't claimed their profile yet." );
        return '<div class="fdp-panel fdp-panel--notice"><p>' . esc_html( $msg ) . '</p></div>';
    }
    
    return fdp_render_corporate_panel( $post_id );
});

// -------------------------------------------------------
// Tab Visibility — Tabs are ALWAYS visible
// -------------------------------------------------------

// NOTE: We intentionally do NOT hide tabs. Free users should see the tabs
// but get the upgrade CTA when they click into them. The tabs are part of
// the conversion funnel to encourage signups.

// -------------------------------------------------------
// Styles — Theme-compatible
// -------------------------------------------------------

add_action( 'wp_head', 'fdp_output_styles', 99 );
function fdp_output_styles() {
    ?>
    <style id="fdp-styles">
    /* FSA Providers Brand Colors */
    :root {
        --fdp-primary: #1a2e3b;      /* Dark navy blue */
        --fdp-secondary: #2d8a6e;    /* Green accent */
        --fdp-secondary-light: #34a07e;
        --fdp-text: #1a2e3b;
        --fdp-text-muted: #5a6872;
        --fdp-bg-light: #f8f9fa;
        --fdp-border: #e2e6ea;
    }
    
    /* Panel container */
    .fdp-panel {
        border-radius: 8px;
        margin: 0 0 20px;
        font-family: inherit;
    }
    
    .fdp-panel--pricing {
        background: #fff;
        border: 1px solid var(--fdp-border);
        padding: 20px;
    }
    
    .fdp-panel--cta {
        padding: 24px;
        text-align: center;
    }
    
    .fdp-panel--upgrade {
        background: linear-gradient(135deg, #f0f7f5 0%, #e8f4f0 100%);
        border: 1px solid #c8e6dc;
    }
    
    .fdp-panel--claim {
        background: linear-gradient(135deg, #f5f7f9 0%, #eef2f5 100%);
        border: 1px solid #d0d8e0;
    }
    
    .fdp-panel--notice {
        background: #fff8e6;
        border: 1px solid #ffe082;
        padding: 16px 20px;
        color: #8a6d3b;
    }
    
    .fdp-panel--notice p {
        margin: 0;
    }
    
    .fdp-panel--notice code {
        background: rgba(0,0,0,0.06);
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 12px;
    }
    
    /* Panel title */
    .fdp-panel__title {
        font-size: 16px;
        font-weight: 600;
        margin: 0 0 16px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--fdp-border);
        color: var(--fdp-primary);
    }
    
    /* CTA elements */
    .fdp-panel__message {
        margin: 0 0 16px;
        font-size: 15px;
        color: var(--fdp-text);
        line-height: 1.5;
    }
    
    .fdp-panel__button {
        display: inline-block;
        padding: 12px 28px;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s ease;
        background: var(--fdp-secondary);
        color: #fff !important;
    }
    
    .fdp-panel__button:hover {
        background: var(--fdp-secondary-light);
        transform: translateY(-1px);
        text-decoration: none;
    }
    
    .fdp-panel--claim .fdp-panel__button {
        background: var(--fdp-primary);
    }
    
    .fdp-panel--claim .fdp-panel__button:hover {
        background: #243d4d;
    }
    
    /* Items list */
    .fdp-items {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }
    
    .fdp-item {
        display: flex;
        gap: 16px;
        padding: 16px;
        background: var(--fdp-bg-light);
        border-radius: 8px;
        border: 1px solid var(--fdp-border);
    }
    
    .fdp-item__image {
        flex-shrink: 0;
        width: 80px;
        height: 80px;
        border-radius: 8px;
        overflow: hidden;
        background: #eee;
    }
    
    .fdp-item__image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    
    .fdp-item__body {
        flex: 1;
        min-width: 0;
    }
    
    .fdp-item__header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 8px;
        flex-wrap: wrap;
    }
    
    .fdp-item__name {
        font-weight: 600;
        font-size: 15px;
        color: var(--fdp-primary);
    }
    
    .fdp-item__price {
        font-weight: 700;
        font-size: 15px;
        color: var(--fdp-secondary);
        white-space: nowrap;
    }
    
    .fdp-item__desc {
        margin: 0 0 12px;
        font-size: 14px;
        color: var(--fdp-text-muted);
        line-height: 1.5;
    }
    
    .fdp-item__links {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .fdp-item__link {
        display: inline-block;
        padding: 8px 16px;
        background: var(--fdp-secondary);
        color: #fff !important;
        font-size: 13px;
        font-weight: 600;
        border-radius: 6px;
        text-decoration: none;
        transition: all 0.2s ease;
    }
    
    .fdp-item__link:hover {
        background: var(--fdp-secondary-light);
        text-decoration: none;
    }
    
    .fdp-item__link--secondary {
        background: transparent;
        color: var(--fdp-secondary) !important;
        border: 1px solid var(--fdp-secondary);
    }
    
    .fdp-item__link--secondary:hover {
        background: var(--fdp-secondary);
        color: #fff !important;
    }
    
    /* Admin labels */
    .fdp-admin-label {
        background: #e7f3ff;
        color: #0073aa;
        padding: 8px 12px;
        font-size: 12px;
        font-weight: 600;
        border-radius: 4px 4px 0 0;
        margin-bottom: -1px;
        border: 1px solid #c8ddf4;
        border-bottom: none;
    }
    
    /* Responsive */
    @media (max-width: 600px) {
        .fdp-item {
            flex-direction: column;
        }
        .fdp-item__image {
            width: 100%;
            height: 180px;
        }
        .fdp-item__header {
            flex-direction: column;
            gap: 4px;
        }
    }
    </style>
    <?php
}
