<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FSA_IC_Services_Renderer {

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

        $eligibility = $this->get_terms_list( $listing_id, FSA_IC_Settings::get( 'services_eligibility_taxonomy' ) );
        $additional  = $this->get_terms_list( $listing_id, FSA_IC_Settings::get( 'services_additional_categories_taxonomy' ) );
        $treatments  = $this->get_terms_list( $listing_id, FSA_IC_Settings::get( 'services_treatments_taxonomy' ) );

        if ( empty( $eligibility ) && empty( $additional ) && empty( $treatments ) ) return '';

        ob_start();
        ?>
        <div class="fsa-ic-card fsa-ic-benefits-card">
            <div class="fsa-ic-benefits-body">
                <h2 class="fsa-ic-benefits-title">Services</h2>

                <?php if ( ! empty( $eligibility ) ) : ?>
                    <div class="fsa-ic-benefits-row">
                        <div class="fsa-ic-benefits-label">Eligibility Category</div>
                        <div class="fsa-ic-benefits-value">
                            <?php foreach ( $eligibility as $t ) : ?>
                                <span class="fsa-ic-pill fsa-ic-pill--fsa"><?php echo esc_html( $t->name ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $additional ) ) : ?>
                    <div class="fsa-ic-benefits-row">
                        <div class="fsa-ic-benefits-label">Additional Provider Categories</div>
                        <div class="fsa-ic-benefits-value">
                            <?php foreach ( $additional as $t ) : ?>
                                <span class="fsa-ic-pill"><?php echo esc_html( $t->name ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $treatments ) ) : ?>
                    <div class="fsa-ic-benefits-row">
                        <div class="fsa-ic-benefits-label">Treatments &amp; Services</div>
                        <div class="fsa-ic-benefits-value">
                            <?php foreach ( $treatments as $t ) : ?>
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
