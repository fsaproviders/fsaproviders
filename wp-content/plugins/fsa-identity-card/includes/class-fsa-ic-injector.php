<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Auto-injects the identity card at the top of the single listing content.
 *
 * MyListing renders single listing pages via the theme's Single Listing Page Builder.
 * The most reliable hook is `the_content` filtered when on a singular job_listing,
 * combined with a body-class check. We also expose a filter
 * `fsa_ic_disable_auto_inject` to allow per-context opt-out.
 */
class FSA_IC_Injector {

    private static $instance = null;
    private $injected = false;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Inject at the top of the_content() on single job_listing pages.
        add_filter( 'the_content', [ $this, 'maybe_inject' ], 5 );

        // MyListing-specific: many MyListing single layouts render the_content via shortcode-like blocks
        // inside the Page Builder. We also hook a high-priority action that the theme exposes.
        add_action( 'mylisting_single_listing_before', [ $this, 'inject_action' ] );
    }

    private function should_inject() {
        if ( ! is_singular( 'job_listing' ) ) return false;
        if ( ! in_the_loop() || ! is_main_query() ) return false;
        if ( ! FSA_IC_Settings::get( 'auto_inject' ) ) return false;
        if ( $this->injected ) return false;
        if ( apply_filters( 'fsa_ic_disable_auto_inject', false, get_the_ID() ) ) return false;
        return true;
    }

    public function maybe_inject( $content ) {
        if ( ! $this->should_inject() ) return $content;
        $card = FSA_IC_Renderer::instance()->render( get_the_ID() );
        if ( empty( $card ) ) return $content;
        $this->injected = true;
        return $card . $content;
    }

    public function inject_action() {
        if ( ! $this->should_inject() ) return;
        $card = FSA_IC_Renderer::instance()->render( get_the_ID() );
        if ( empty( $card ) ) return;
        $this->injected = true;
        echo $card; // Already escaped inside renderer
    }
}
