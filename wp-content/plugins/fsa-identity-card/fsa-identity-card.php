<?php
/**
 * Plugin Name: FSA Identity Card
 * Description: Renders a Psychology Today-style identity card on MyListing single listing pages. Pulls Name, Credentials, Pronouns, Provider Category, Photo, Tagline, and Verification badge from configurable fields.
 * Version: 1.2.0
 * Author: FSA Providers
 * Text Domain: fsa-identity-card
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FSA_IC_VERSION', '1.2.0' );
define( 'FSA_IC_PATH', plugin_dir_path( __FILE__ ) );
define( 'FSA_IC_URL', plugin_dir_url( __FILE__ ) );

require_once FSA_IC_PATH . 'includes/class-fsa-ic-settings.php';
require_once FSA_IC_PATH . 'includes/class-fsa-ic-renderer.php';
require_once FSA_IC_PATH . 'includes/class-fsa-ic-benefits-renderer.php';
require_once FSA_IC_PATH . 'includes/class-fsa-ic-service-area-renderer.php';
require_once FSA_IC_PATH . 'includes/class-fsa-ic-services-renderer.php';
require_once FSA_IC_PATH . 'includes/class-fsa-ic-social-renderer.php';
require_once FSA_IC_PATH . 'includes/class-fsa-ic-injector.php';

add_action( 'plugins_loaded', function () {
    FSA_IC_Settings::instance();
    FSA_IC_Renderer::instance();
    FSA_IC_Benefits_Renderer::instance();
    FSA_IC_Service_Area_Renderer::instance();
    FSA_IC_Services_Renderer::instance();
    FSA_IC_Social_Renderer::instance();
    FSA_IC_Injector::instance();
} );

add_action( 'wp_enqueue_scripts', function () {
    if ( is_singular( 'job_listing' ) ) {
        wp_enqueue_style(
            'fsa-identity-card',
            FSA_IC_URL . 'assets/identity-card.css',
            [],
            FSA_IC_VERSION
        );
    }
} );

// Shortcode registration
add_shortcode( 'fsa_identity_card', function ( $atts ) {
    $atts = shortcode_atts( [ 'listing_id' => 0 ], $atts );
    $listing_id = (int) $atts['listing_id'] ?: get_the_ID();
    return FSA_IC_Renderer::instance()->render( $listing_id );
} );

add_shortcode( 'fsa_benefits_card', function ( $atts ) {
    $atts = shortcode_atts( [ 'listing_id' => 0 ], $atts );
    $listing_id = (int) $atts['listing_id'] ?: get_the_ID();
    return FSA_IC_Benefits_Renderer::instance()->render( $listing_id );
} );

add_shortcode( 'fsa_service_area_card', function ( $atts ) {
    $atts = shortcode_atts( [ 'listing_id' => 0 ], $atts );
    $listing_id = (int) $atts['listing_id'] ?: get_the_ID();
    return FSA_IC_Service_Area_Renderer::instance()->render( $listing_id );
} );

add_shortcode( 'fsa_services_card', function ( $atts ) {
    $atts = shortcode_atts( [ 'listing_id' => 0 ], $atts );
    $listing_id = (int) $atts['listing_id'] ?: get_the_ID();
    return FSA_IC_Services_Renderer::instance()->render( $listing_id );
} );

add_shortcode( 'fsa_social_card', function ( $atts ) {
    $atts = shortcode_atts( [ 'listing_id' => 0 ], $atts );
    $listing_id = (int) $atts['listing_id'] ?: get_the_ID();
    return FSA_IC_Social_Renderer::instance()->render( $listing_id );
} );
