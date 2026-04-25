<?php
/**
 * MyListing Child Theme functions
 *
 * All child theme functionality should live here or in files included from here.
 * Business logic (roles, WC hooks, Salesforce sync, etc.) belongs in plugins, NOT this file.
 *
 * @package MyListing_Child
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueue parent and child theme styles.
 *
 * Loads the parent's style.css by file path rather than handle name, so this
 * works regardless of how the parent registers its stylesheet. Child styles
 * are split across multiple files in assets/css/ for maintainability.
 */
add_action( 'wp_enqueue_scripts', function () {
    $child_theme  = wp_get_theme();
    $theme_version = $child_theme->get( 'Version' );
    $theme_uri    = get_stylesheet_directory_uri();
    $theme_path   = get_stylesheet_directory();

    // Parent stylesheet
    wp_enqueue_style(
        'mylisting-parent-style',
        get_template_directory_uri() . '/style.css',
        array(),
        null
    );

    // Child theme header (contains theme metadata only, no actual rules)
    wp_enqueue_style(
        'mylisting-child-style',
        get_stylesheet_uri(),
        array( 'mylisting-parent-style' ),
        $theme_version
    );

    // Design tokens (CSS variables) — must load before anything that uses them
    $tokens_path = $theme_path . '/assets/css/tokens.css';
    if ( file_exists( $tokens_path ) ) {
        wp_enqueue_style(
            'fsa-tokens',
            $theme_uri . '/assets/css/tokens.css',
            array( 'mylisting-child-style' ),
            filemtime( $tokens_path )
        );
    }

    // Base styles (typography, resets)
    $base_path = $theme_path . '/assets/css/base.css';
    if ( file_exists( $base_path ) ) {
        wp_enqueue_style(
            'fsa-base',
            $theme_uri . '/assets/css/base.css',
            array( 'fsa-tokens' ),
            filemtime( $base_path )
        );
    }
}, 20 );

/**
 * Load child theme includes.
 *
 * Add per-feature files to wp-content/themes/mylisting-child/inc/ and require them here.
 * Keep this file itself minimal — it's the manifest, not the implementation.
 */
$includes_dir = get_stylesheet_directory() . '/inc';
if ( is_dir( $includes_dir ) ) {
    foreach ( glob( $includes_dir . '/*.php' ) as $include ) {
        require_once $include;
    }
}
