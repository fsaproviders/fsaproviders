<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FSA_IC_Service_Area_Renderer {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    private function get_terms_list( $listing_id, $taxonomy ) {
        if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) return [];
        $terms = wp_get_post_terms( $listing_id, $taxonomy );
        if ( is_wp_error( $terms ) ) return [];
        return $terms;
    }

    public function render( $listing_id ) {
        if ( ! $listing_id || get_post_type( $listing_id ) !== 'job_listing' ) return '';

        $telehealth = $this->get_terms_list( $listing_id, FSA_IC_Settings::get( 'service_area_telehealth_taxonomy' ) );
        $regions    = $this->get_terms_list( $listing_id, FSA_IC_Settings::get( 'service_area_regions_taxonomy' ) );

        if ( empty( $telehealth ) && empty( $regions ) ) return '';

        ob_start();
        ?>
        <div class="fsa-ic-card fsa-ic-benefits-card">
            <div class="fsa-ic-benefits-body">
                <h2 class="fsa-ic-benefits-title">Service Area</h2>

                <?php if ( ! empty( $telehealth ) ) : ?>
                    <div class="fsa-ic-benefits-row">
                        <div class="fsa-ic-benefits-label">Telehealth Provider</div>
                        <div class="fsa-ic-benefits-value">
                            <?php foreach ( $telehealth as $t ) : ?>
                                <span class="fsa-ic-pill"><?php echo esc_html( $t->name ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $regions ) ) : ?>
                    <div class="fsa-ic-benefits-row">
                        <div class="fsa-ic-benefits-label">Regions Served</div>
                        <div class="fsa-ic-benefits-value">
                            <?php foreach ( $regions as $t ) : ?>
                                <span class="fsa-ic-pill"><?php echo esc_html( $t->name ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
