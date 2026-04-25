<?php
/**
 * FSAHSA Sync - Location Sync Helper
 *
 * Writes address data to wp_mylisting_locations table whenever
 * _job_location is set via Zapier/REST, bypassing MyListing's form flow.
 *
 * MyListing does NOT use _job_location meta for map display. It uses a
 * dedicated wp_mylisting_locations table (listing_id, address, lat, lng).
 * Location_Field->update() writes to that table, but only fires through
 * MyListing's own form. This file bridges the gap for REST/Zapier imports.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Writes a full address row (address + geocoded lat/lng) into
 * wp_mylisting_locations for a given listing ID.
 *
 * Call this any time you write _job_location via update_post_meta.
 *
 * @param int    $post_id  The listing post ID.
 * @param string $address  Plain text address string.
 * @return bool  True on success (geocoded + table written), false otherwise.
 */
function fsahsa_sync_write_listing_location( $post_id, $address ) {
    global $wpdb;

    $post_id = (int) $post_id;

    // Unwrap array — update_post_meta sometimes stores the value as
    // array( 0 => 'address string' ) depending on how the caller passed it.
    if ( is_array( $address ) ) {
        $address = reset( $address ); // take first element
    }

    $address = trim( (string) $address );

    if ( ! $address || ! $post_id ) {
        return false;
    }

    // Step 1: Always write _job_location meta so legacy tools and the
    // geolocate_listings snippet can find it.
    update_post_meta( $post_id, '_job_location', $address );

    // Step 2: Geocode via MyListing's own geocoder.
    $lat = null;
    $lng = null;

    // Step 2: Geocode using plugin's own server key if configured,
    // otherwise fall back to MyListing's geocoder.
    // Using a dedicated server key avoids the browser/referer restriction
    // that blocks PHP curl calls from the server.
    $lat = null;
    $lng = null;
    $geocode_error = '';

    $s = fsahsa_sync_get_settings();
    $server_key = trim( (string) ( $s['geocoding_api_key'] ?? '' ) );

    if ( $server_key ) {
        // Direct Google Geocoding API call with server key.
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query( [
            'address'  => $address,
            'key'      => $server_key,
            'language' => 'en',
        ] );

        $response = wp_remote_get( $url, [ 'httpversion' => '1.1', 'sslverify' => false, 'timeout' => 10 ] );

        if ( is_wp_error( $response ) ) {
            $geocode_error = $response->get_error_message();
        } else {
            $body = json_decode( wp_remote_retrieve_body( $response ) );
            if ( isset( $body->status ) && $body->status === 'OK' && ! empty( $body->results[0] ) ) {
                $lat = round( floatval( $body->results[0]->geometry->location->lat ), 5 );
                $lng = round( floatval( $body->results[0]->geometry->location->lng ), 5 );
            } else {
                $geocode_error = sprintf( '(%s) %s',
                    $body->status ?? 'REQUEST_FAILED',
                    $body->error_message ?? 'Geocoding request failed.'
                );
            }
        }
    } elseif ( class_exists( '\MyListing\Src\Geocoder\Geocoder' ) ) {
        // Fallback: use MyListing's geocoder (only works if key has no referer restriction).
        $geocoder = \MyListing\Src\Geocoder\Geocoder::get();
        if ( $geocoder ) {
            try {
                $feature = $geocoder->geocode( $address );
                $lat = round( floatval( $feature['latitude'] ), 5 );
                $lng = round( floatval( $feature['longitude'] ), 5 );
            } catch ( \Exception $e ) {
                $geocode_error = $e->getMessage();
            }
        } else {
            $geocode_error = 'Geocoder::get() returned null — check Maps provider in MyListing theme options.';
        }
    } else {
        $geocode_error = 'No geocoding method available. Add a Server Geocoding API Key in FSAHSA Sync settings.';
    }

    if ( $geocode_error ) {
        update_post_meta( $post_id, '_fsahsa_geocode_error', $geocode_error );
        update_post_meta( $post_id, '_fsahsa_geocode_address_attempted', $address );
        fsahsa_sync_debug_log( 'warn', 'Geocoding failed for listing #' . $post_id, [
            'address' => $address,
            'error'   => $geocode_error,
        ] );
    } else {
        // Clear any previous error on success.
        delete_post_meta( $post_id, '_fsahsa_geocode_error' );
        delete_post_meta( $post_id, '_fsahsa_geocode_address_attempted' );
        update_post_meta( $post_id, 'geolocation_lat',  $lat );
        update_post_meta( $post_id, 'geolocation_long', $lng );
        update_post_meta( $post_id, 'geolocation_formatted_address', $address );
    }

    // Step 3: Write into wp_mylisting_locations — the table MyListing
    // actually uses for map display and proximity search.
    // Delete existing rows first (mirrors Location_Field->update() behavior).
    $wpdb->delete(
        $wpdb->prefix . 'mylisting_locations',
        [ 'listing_id' => $post_id ],
        [ '%d' ]
    );

    if ( $address && $lat && $lng ) {
        $wpdb->insert(
            $wpdb->prefix . 'mylisting_locations',
            [
                'listing_id' => $post_id,
                'address'    => $address,
                'lat'        => $lat,
                'lng'        => $lng,
            ],
            [ '%d', '%s', '%f', '%f' ]
        );
    }

    clean_post_cache( $post_id );
    wp_cache_delete( $post_id, 'post_meta' );

    return ( $lat !== null && $lng !== null );
}


/**
 * Trigger geocoding directly after a v2 REST upsert completes.
 *
 * The updated_post_meta hook is unreliable for this because:
 * 1. Empty string values in the Zapier payload write "" to _job_location first,
 *    causing the hook to fire and exit early before the real address arrives.
 * 2. The hook only fires if update_post_meta detects a change.
 *
 * Instead, rest.php calls this directly after all meta has been written,
 * passing the address value it just persisted.
 *
 * @param int    $post_id  The listing post ID.
 * @param string $address  The address value that was just written to _job_location.
 */
function fsahsa_sync_trigger_location_sync( $post_id, $address ) {
    $address = trim( (string) $address );
    if ( ! $address || ! $post_id ) return;
    fsahsa_sync_write_listing_location( $post_id, $address );
}

/**
 * Hook: belt-and-suspenders fallback for any code path that writes
 * _job_location via update_post_meta outside of the REST handler
 * (e.g. WP All Import, manual admin saves, other plugins).
 */
add_action( 'updated_post_meta', function( $meta_id, $post_id, $meta_key, $meta_value ) {
    if ( $meta_key !== '_job_location' ) return;
    if ( get_post_type( $post_id ) !== 'job_listing' ) return;
    if ( empty( trim( (string) $meta_value ) ) ) return;

    static $running = false;
    if ( $running ) return;
    $running = true;

    fsahsa_sync_write_listing_location( $post_id, $meta_value );

    $running = false;
}, 10, 4 );

add_action( 'added_post_meta', function( $meta_id, $post_id, $meta_key, $meta_value ) {
    if ( $meta_key !== '_job_location' ) return;
    if ( get_post_type( $post_id ) !== 'job_listing' ) return;
    if ( empty( trim( (string) $meta_value ) ) ) return;

    static $running = false;
    if ( $running ) return;
    $running = true;

    fsahsa_sync_write_listing_location( $post_id, $meta_value );

    $running = false;
}, 10, 4 );
