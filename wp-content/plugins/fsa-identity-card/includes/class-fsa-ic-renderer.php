<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FSA_IC_Renderer {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Resolve a photo meta value to a usable URL.
     * Handles: attachment ID, raw URL, serialized array, JSON.
     */
    private function resolve_photo_url( $raw ) {
        if ( empty( $raw ) ) return '';

        // Attachment ID
        if ( is_numeric( $raw ) ) {
            $url = wp_get_attachment_image_url( (int) $raw, 'medium' );
            if ( $url ) return $url;
        }

        // Direct URL
        if ( is_string( $raw ) && filter_var( $raw, FILTER_VALIDATE_URL ) ) {
            return $raw;
        }

        // Serialized
        if ( is_string( $raw ) && is_serialized( $raw ) ) {
            $unser = maybe_unserialize( $raw );
            if ( is_array( $unser ) ) {
                $first = reset( $unser );
                if ( is_numeric( $first ) ) return wp_get_attachment_image_url( (int) $first, 'medium' );
                if ( is_string( $first ) && filter_var( $first, FILTER_VALIDATE_URL ) ) return $first;
            }
        }

        // JSON
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $first = reset( $decoded );
                if ( is_numeric( $first ) ) return wp_get_attachment_image_url( (int) $first, 'medium' );
                if ( is_string( $first ) && filter_var( $first, FILTER_VALIDATE_URL ) ) return $first;
            }
        }

        // Array
        if ( is_array( $raw ) ) {
            $first = reset( $raw );
            if ( is_numeric( $first ) ) return wp_get_attachment_image_url( (int) $first, 'medium' );
            if ( is_string( $first ) && filter_var( $first, FILTER_VALIDATE_URL ) ) return $first;
        }

        return '';
    }

    private function get_meta( $listing_id, $key ) {
        if ( empty( $key ) ) return '';
        // MyListing fields are stored with leading underscore
        $val = get_post_meta( $listing_id, '_' . ltrim( $key, '_' ), true );
        if ( '' === $val || null === $val ) {
            $val = get_post_meta( $listing_id, $key, true );
        }
        return $val;
    }

    private function get_terms_list( $listing_id, $taxonomy ) {
        if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) return [];
        $terms = wp_get_post_terms( $listing_id, $taxonomy );
        if ( is_wp_error( $terms ) ) return [];
        return $terms;
    }

    private function is_verified( $listing_id ) {
        $source = FSA_IC_Settings::get( 'verification_source' );
        if ( 'meta_key' === $source ) {
            $key = FSA_IC_Settings::get( 'verification_meta_key' );
            if ( empty( $key ) ) return false;
            $val = $this->get_meta( $listing_id, $key );
            return ! empty( $val ) && $val !== '0' && strtolower( (string) $val ) !== 'false';
        }
        // MyListing native: stored as _case27_listing_verified = 'on' or '1'
        $native = get_post_meta( $listing_id, '_case27_listing_verified', true );
        return ! empty( $native );
    }

    public function render( $listing_id ) {
        if ( ! $listing_id || get_post_type( $listing_id ) !== 'job_listing' ) return '';

        $first   = trim( (string) $this->get_meta( $listing_id, FSA_IC_Settings::get( 'first_name_key' ) ) );
        $last    = trim( (string) $this->get_meta( $listing_id, FSA_IC_Settings::get( 'last_name_key' ) ) );
        $creds   = trim( (string) $this->get_meta( $listing_id, FSA_IC_Settings::get( 'credentials_key' ) ) );
        $tagline = trim( (string) $this->get_meta( $listing_id, FSA_IC_Settings::get( 'tagline_key' ) ) );
        $photo_raw = $this->get_meta( $listing_id, FSA_IC_Settings::get( 'photo_key' ) );
        $photo_url = $this->resolve_photo_url( $photo_raw );

        $pronoun_terms  = $this->get_terms_list( $listing_id, FSA_IC_Settings::get( 'pronouns_taxonomy' ) );
        $category_terms = $this->get_terms_list( $listing_id, FSA_IC_Settings::get( 'category_taxonomy' ) );

        $name = trim( $first . ' ' . $last );
        if ( empty( $name ) ) {
            // Fallback to listing title so the card never renders nameless
            $name = get_the_title( $listing_id );
        }

        $verified = $this->is_verified( $listing_id );

        ob_start();
        ?>
        <div class="fsa-ic-card">
            <?php if ( $photo_url ) : ?>
                <div class="fsa-ic-photo">
                    <img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" />
                </div>
            <?php else : ?>
                <div class="fsa-ic-photo fsa-ic-photo--placeholder" aria-hidden="true">
                    <span><?php echo esc_html( mb_substr( $name, 0, 1 ) ); ?></span>
                </div>
            <?php endif; ?>

            <div class="fsa-ic-body">
                <div class="fsa-ic-name-row">
                    <h2 class="fsa-ic-name"><?php echo esc_html( $name ); ?></h2>
                    <?php if ( $verified ) : ?>
                        <span class="fsa-ic-badge" title="Verified">
                            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
                                <path fill="#1d9bf0" d="M22.5 12l-2.3-2.6.3-3.5-3.4-.8L15.3 2 12 3.4 8.7 2 6.9 5.1l-3.4.8.3 3.5L1.5 12l2.3 2.6-.3 3.5 3.4.8L8.7 22 12 20.6 15.3 22l1.8-3.1 3.4-.8-.3-3.5L22.5 12zM10.6 16.6L6.4 12.4l1.4-1.4 2.8 2.8 6-6 1.4 1.4-7.4 7.4z"/>
                            </svg>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ( $creds || ! empty( $pronoun_terms ) ) : ?>
                    <div class="fsa-ic-meta-row">
                        <?php if ( $creds ) : ?>
                            <span class="fsa-ic-credentials"><?php echo esc_html( $creds ); ?></span>
                        <?php endif; ?>
                        <?php if ( ! empty( $pronoun_terms ) ) : ?>
                            <span class="fsa-ic-pronouns">(<?php echo esc_html( $pronoun_terms[0]->name ); ?>)</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $category_terms ) ) : ?>
                    <div class="fsa-ic-category-row">
                        <?php foreach ( $category_terms as $term ) : ?>
                            <span class="fsa-ic-category-pill"><?php echo esc_html( $term->name ); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ( $tagline ) : ?>
                    <p class="fsa-ic-tagline"><?php echo esc_html( $tagline ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
