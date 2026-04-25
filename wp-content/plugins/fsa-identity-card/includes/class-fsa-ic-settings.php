<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FSA_IC_Settings {

    const OPTION_KEY = 'fsa_ic_settings';
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public static function defaults() {
        return [
            'first_name_key'    => 'provider-first-name',
            'last_name_key'     => 'provider-last-name',
            'credentials_key'   => 'credentials',
            'pronouns_taxonomy' => 'pronouns',
            'tagline_key'       => 'identity-card-tagline',
            'photo_key'         => 'identity-card-profile-photo',
            'category_taxonomy' => 'provider-category',
            'verification_source' => 'mylisting_native', // mylisting_native | meta_key
            'verification_meta_key' => '',
            'auto_inject'       => 1,
            // Benefits & Insurance card
            'benefits_fsa_taxonomy'                 => 'fsa-hsa-benefits-accepted',
            'benefits_accepts_insurance_taxonomy'   => 'accepts-insurance',
            'benefits_insurance_companies_taxonomy' => 'insurance-companies-accepted',
            'benefits_payment_types_taxonomy'       => 'payment-types-accepted',
            'benefits_disclaimer_key'               => 'insurance-disclaimer',
            // Service Area card
            'service_area_telehealth_taxonomy' => 'telehealth-provider',
            'service_area_regions_taxonomy'    => 'regions',
            // Services card
            'services_eligibility_taxonomy'             => 'eligibility-category',
            'services_additional_categories_taxonomy'   => 'additional-provider-categories',
            'services_treatments_taxonomy'              => 'services',
            // Social URLs card
            'social_instagram_key' => 'instagram',
            'social_facebook_key'  => 'facebook',
            'social_youtube_key'   => 'youtube',
            'social_tiktok_key'    => 'tiktok',
            'social_linkedin_key'  => 'linkedin',
            'social_yelp_key'      => 'yelp',
            'social_google_key'    => 'google',
        ];
    }

    public static function get( $key ) {
        $opts = wp_parse_args( get_option( self::OPTION_KEY, [] ), self::defaults() );
        return isset( $opts[ $key ] ) ? $opts[ $key ] : '';
    }

    public function add_menu() {
        add_options_page(
            'FSA Identity Card',
            'FSA Identity Card',
            'manage_options',
            'fsa-identity-card',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'fsa_ic_group', self::OPTION_KEY, [ $this, 'sanitize' ] );
    }

    public function sanitize( $input ) {
        $clean = [];
        foreach ( self::defaults() as $k => $default ) {
            if ( $k === 'auto_inject' ) {
                $clean[ $k ] = ! empty( $input[ $k ] ) ? 1 : 0;
            } else {
                $clean[ $k ] = isset( $input[ $k ] ) ? sanitize_text_field( $input[ $k ] ) : $default;
            }
        }
        return $clean;
    }

    private function discover_meta_keys() {
        global $wpdb;
        $keys = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_key
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'job_listing'
             AND pm.meta_key NOT LIKE '\_oembed%'
             ORDER BY pm.meta_key ASC"
        );
        return $keys ?: [];
    }

    private function discover_taxonomies() {
        $taxes = get_object_taxonomies( 'job_listing', 'objects' );
        $list = [];
        foreach ( $taxes as $slug => $tax ) {
            $list[ $slug ] = $tax->label . ' (' . $slug . ')';
        }
        return $list;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $opts = wp_parse_args( get_option( self::OPTION_KEY, [] ), self::defaults() );
        $meta_keys = $this->discover_meta_keys();
        $taxes = $this->discover_taxonomies();
        ?>
        <div class="wrap">
            <h1>FSA Identity Card Settings</h1>
            <p>Map the fields used to render the identity card on single listing pages. The card replaces nothing automatically — to remove the Provider Category card, edit your MyListing layout. Use the shortcode <code>[fsa_identity_card]</code> for manual placement.</p>

            <form method="post" action="options.php">
                <?php settings_fields( 'fsa_ic_group' ); ?>

                <h2 class="title">Field Mapping</h2>
                <table class="form-table" role="presentation">
                    <?php
                    $text_fields = [
                        'first_name_key'  => 'Provider First Name (meta key)',
                        'last_name_key'   => 'Provider Last Name (meta key)',
                        'credentials_key' => 'Credentials (meta key)',
                        'tagline_key'     => 'Identity Card Tagline (meta key)',
                        'photo_key'       => 'Profile Photo (meta key)',
                    ];
                    foreach ( $text_fields as $key => $label ) :
                        ?>
                        <tr>
                            <th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                            <td>
                                <input list="fsa-ic-meta-keys" type="text" id="<?php echo esc_attr( $key ); ?>"
                                       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]"
                                       value="<?php echo esc_attr( $opts[ $key ] ); ?>" class="regular-text" />
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr>
                        <th scope="row"><label for="pronouns_taxonomy">Pronouns (taxonomy)</label></th>
                        <td>
                            <select id="pronouns_taxonomy" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[pronouns_taxonomy]">
                                <option value="">— None —</option>
                                <?php foreach ( $taxes as $slug => $label ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $opts['pronouns_taxonomy'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="category_taxonomy">Provider Category (taxonomy)</label></th>
                        <td>
                            <select id="category_taxonomy" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[category_taxonomy]">
                                <option value="">— None —</option>
                                <?php foreach ( $taxes as $slug => $label ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $opts['category_taxonomy'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Verification Badge</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Verification source</th>
                        <td>
                            <label>
                                <input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[verification_source]" value="mylisting_native" <?php checked( $opts['verification_source'], 'mylisting_native' ); ?> />
                                MyListing native verification (recommended — uses the built-in "Verified Listing" status)
                            </label><br>
                            <label>
                                <input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[verification_source]" value="meta_key" <?php checked( $opts['verification_source'], 'meta_key' ); ?> />
                                Custom meta key (truthy value renders badge)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="verification_meta_key">Verification meta key</label></th>
                        <td>
                            <input list="fsa-ic-meta-keys" type="text" id="verification_meta_key"
                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[verification_meta_key]"
                                   value="<?php echo esc_attr( $opts['verification_meta_key'] ); ?>" class="regular-text" />
                            <p class="description">Only used if "Custom meta key" is selected above.</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Benefits &amp; Insurance Card</h2>
                <p>Used by the <code>[fsa_benefits_card]</code> shortcode. The card hides automatically if all five fields are empty.</p>
                <table class="form-table" role="presentation">
                    <?php
                    $benefits_taxes = [
                        'benefits_fsa_taxonomy'                 => 'FSA/HSA Benefits Accepted (taxonomy)',
                        'benefits_accepts_insurance_taxonomy'   => 'Accepts Insurance (taxonomy)',
                        'benefits_insurance_companies_taxonomy' => 'Insurance Companies Accepted (taxonomy)',
                        'benefits_payment_types_taxonomy'       => 'Payment Types Accepted (taxonomy)',
                    ];
                    foreach ( $benefits_taxes as $key => $label ) :
                        ?>
                        <tr>
                            <th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                            <td>
                                <select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]">
                                    <option value="">— None —</option>
                                    <?php foreach ( $taxes as $slug => $tax_label ) : ?>
                                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $opts[ $key ], $slug ); ?>><?php echo esc_html( $tax_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <tr>
                        <th scope="row"><label for="benefits_disclaimer_key">Insurance Disclaimer (meta key)</label></th>
                        <td>
                            <input list="fsa-ic-meta-keys" type="text" id="benefits_disclaimer_key"
                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[benefits_disclaimer_key]"
                                   value="<?php echo esc_attr( $opts['benefits_disclaimer_key'] ); ?>" class="regular-text" />
                        </td>
                    </tr>
                </table>

                <h2 class="title">Service Area Card</h2>
                <p>Used by the <code>[fsa_service_area_card]</code> shortcode. Hides if both fields are empty.</p>
                <table class="form-table" role="presentation">
                    <?php
                    $service_area_taxes = [
                        'service_area_telehealth_taxonomy' => 'Telehealth Provider (taxonomy)',
                        'service_area_regions_taxonomy'    => 'Regions (taxonomy)',
                    ];
                    foreach ( $service_area_taxes as $key => $label ) :
                        ?>
                        <tr>
                            <th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                            <td>
                                <select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]">
                                    <option value="">— None —</option>
                                    <?php foreach ( $taxes as $slug => $tax_label ) : ?>
                                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $opts[ $key ], $slug ); ?>><?php echo esc_html( $tax_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2 class="title">Services Card</h2>
                <p>Used by the <code>[fsa_services_card]</code> shortcode. Hides if all fields are empty.</p>
                <table class="form-table" role="presentation">
                    <?php
                    $services_taxes = [
                        'services_eligibility_taxonomy'           => 'Eligibility Category (taxonomy)',
                        'services_additional_categories_taxonomy' => 'Additional Provider Categories (taxonomy)',
                        'services_treatments_taxonomy'            => 'Treatments &amp; Services (taxonomy)',
                    ];
                    foreach ( $services_taxes as $key => $label ) :
                        ?>
                        <tr>
                            <th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo $label; ?></label></th>
                            <td>
                                <select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]">
                                    <option value="">— None —</option>
                                    <?php foreach ( $taxes as $slug => $tax_label ) : ?>
                                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $opts[ $key ], $slug ); ?>><?php echo esc_html( $tax_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2 class="title">Social URLs Card</h2>
                <p>Used by the <code>[fsa_social_card]</code> shortcode. Hides if all URLs are empty. Only platforms with a URL will render a button.</p>
                <table class="form-table" role="presentation">
                    <?php
                    $social_fields = [
                        'social_instagram_key' => 'Instagram (meta key)',
                        'social_facebook_key'  => 'Facebook (meta key)',
                        'social_youtube_key'   => 'YouTube (meta key)',
                        'social_tiktok_key'    => 'TikTok (meta key)',
                        'social_linkedin_key'  => 'LinkedIn (meta key)',
                        'social_yelp_key'      => 'Yelp (meta key)',
                        'social_google_key'    => 'Google (meta key)',
                    ];
                    foreach ( $social_fields as $key => $label ) :
                        ?>
                        <tr>
                            <th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                            <td>
                                <input list="fsa-ic-meta-keys" type="text" id="<?php echo esc_attr( $key ); ?>"
                                       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]"
                                       value="<?php echo esc_attr( $opts[ $key ] ); ?>" class="regular-text" />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2 class="title">Display</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Auto-inject</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_inject]" value="1" <?php checked( $opts['auto_inject'], 1 ); ?> />
                                Automatically render the identity card at the top of the Provider Details tab on all listing types
                            </label>
                            <p class="description">Disable if you'd rather place it manually with the <code>[fsa_identity_card]</code> shortcode.</p>
                        </td>
                    </tr>
                </table>

                <datalist id="fsa-ic-meta-keys">
                    <?php foreach ( $meta_keys as $mk ) : ?>
                        <option value="<?php echo esc_attr( $mk ); ?>"></option>
                    <?php endforeach; ?>
                </datalist>

                <?php submit_button(); ?>
            </form>

            <h2>Discovered meta keys (reference)</h2>
            <p>These are all meta keys currently in use on <code>job_listing</code> posts. Use them as a reference when filling in the field mappings above.</p>
            <details>
                <summary>Show <?php echo count( $meta_keys ); ?> keys</summary>
                <pre style="max-height:300px;overflow:auto;background:#f6f7f7;padding:10px;"><?php echo esc_html( implode( "\n", $meta_keys ) ); ?></pre>
            </details>
        </div>
        <?php
    }
}
