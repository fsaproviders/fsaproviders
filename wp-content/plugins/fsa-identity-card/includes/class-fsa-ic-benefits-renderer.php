<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FSA_IC_Benefits_Renderer {

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

    private function get_meta( $listing_id, $key ) {
        if ( empty( $key ) ) return '';
        $val = get_post_meta( $listing_id, '_' . ltrim( $key, '_' ), true );
        if ( '' === $val || null === $val ) {
            $val = get_post_meta( $listing_id, $key, true );
        }
        return $val;
    }

    public function render( $listing_id ) {
        if ( ! $listing_id || get_post_type( $listing_id ) !== 'job_listing' ) return '';

        $fsa_terms       = $this->get_terms_list( $listing_id, FSA_IC_Settings::get( 'benefits_fsa_taxonomy' ) );
        $accepts_terms   = $this->get_terms_list( $listing_id, FSA_IC_Settings::get( 'benefits_accepts_insurance_taxonomy' ) );
        $insurance_terms = $this->get_terms_list( $listing_id, FSA_IC_Settings::get( 'benefits_insurance_companies_taxonomy' ) );
        $payment_terms   = $this->get_terms_list( $listing_id, FSA_IC_Settings::get( 'benefits_payment_types_taxonomy' ) );
        $disclaimer      = trim( (string) $this->get_meta( $listing_id, FSA_IC_Settings::get( 'benefits_disclaimer_key' ) ) );

        // Hide entire card if all five fields are empty
        if ( empty( $fsa_terms ) && empty( $accepts_terms ) && empty( $insurance_terms ) && empty( $payment_terms ) && empty( $disclaimer ) ) {
            return '';
        }

        ob_start();
        ?>
        <div class="fsa-ic-card fsa-ic-benefits-card">
            <div class="fsa-ic-benefits-body">
                <h2 class="fsa-ic-benefits-title">Benefits &amp; Insurance</h2>

                <?php if ( ! empty( $fsa_terms ) ) : ?>
                    <div class="fsa-ic-benefits-row">
                        <div class="fsa-ic-benefits-label">FSA/HSA Benefits Accepted</div>
                        <div class="fsa-ic-benefits-value">
                            <?php foreach ( $fsa_terms as $t ) : ?>
                                <span class="fsa-ic-pill fsa-ic-pill--fsa"><?php echo esc_html( $t->name ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $accepts_terms ) ) : ?>
                    <div class="fsa-ic-benefits-row">
                        <div class="fsa-ic-benefits-label">Accepts Insurance</div>
                        <div class="fsa-ic-benefits-value">
                            <?php foreach ( $accepts_terms as $t ) : ?>
                                <span class="fsa-ic-pill"><?php echo esc_html( $t->name ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $insurance_terms ) ) : ?>
                    <div class="fsa-ic-benefits-row">
                        <div class="fsa-ic-benefits-label">Insurance Companies Accepted</div>
                        <div class="fsa-ic-benefits-value">
                            <?php foreach ( $insurance_terms as $t ) : ?>
                                <span class="fsa-ic-pill"><?php echo esc_html( $t->name ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $payment_terms ) ) : ?>
                    <div class="fsa-ic-benefits-row">
                        <div class="fsa-ic-benefits-label">Payment Types Accepted</div>
                        <div class="fsa-ic-benefits-value">
                            <?php foreach ( $payment_terms as $t ) : ?>
                                <span class="fsa-ic-pill"><?php echo esc_html( $t->name ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $disclaimer ) ) : ?>
                    <div class="fsa-ic-benefits-disclaimer">
                        <?php echo wp_kses_post( wpautop( $disclaimer ) ); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
