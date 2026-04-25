<?php
if (!defined('ABSPATH')) { exit; }


// --- Delayed outbound scheduling (avoids race conditions on publish/status/meta) ---
if (!defined('FSAHSA_SYNC_OUTBOUND_CRON_HOOK')) {
    define('FSAHSA_SYNC_OUTBOUND_CRON_HOOK', 'fsahsa_sync_delayed_outbound_send');
}
if (!defined('FSAHSA_SYNC_OUTBOUND_DELAY')) {
    // Seconds to wait before sending outbound webhook.
    define('FSAHSA_SYNC_OUTBOUND_DELAY', 10);
}

function fsahsa_sync_schedule_post_to_zapier($post_id, $sync_action = 'wp_to_sf_update') {
    $post_id = absint($post_id);
    if (!$post_id) return;

    $sync_action = sanitize_key((string)$sync_action);
    if (!$sync_action) $sync_action = 'wp_to_sf_update';

    // Store the *latest* requested action so rapid saves collapse into one outbound ping.
    update_post_meta($post_id, '_fsahsa_pending_outbound_action', $sync_action);
    update_post_meta($post_id, '_fsahsa_pending_outbound_scheduled_at', function_exists('current_time') ? current_time('c') : gmdate('c'));

    // Ensure only one scheduled event per post ID.
    wp_clear_scheduled_hook(FSAHSA_SYNC_OUTBOUND_CRON_HOOK, [$post_id]);
    wp_schedule_single_event(time() + (int)FSAHSA_SYNC_OUTBOUND_DELAY, FSAHSA_SYNC_OUTBOUND_CRON_HOOK, [$post_id]);
}

add_action(FSAHSA_SYNC_OUTBOUND_CRON_HOOK, function($post_id) {
    $post_id = absint($post_id);
    if (!$post_id) return;

    // Re-read the latest desired action and send using fresh DB reads.
    $action = (string)get_post_meta($post_id, '_fsahsa_pending_outbound_action', true);
    if (!$action) $action = 'wp_to_sf_update';

    // Clean up the pending meta now that we're actually sending.
    delete_post_meta($post_id, '_fsahsa_pending_outbound_action');
    delete_post_meta($post_id, '_fsahsa_pending_outbound_scheduled_at');

    fsahsa_sync_send_post_to_zapier($post_id, $action);
}, 10, 1);

add_action('save_post', function($post_id, $post, $update){
    // Prevent noisy/empty outbound events.
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if (!$post instanceof WP_Post) { $post = get_post($post_id); }
    if (!$post) return;
    if ($post->post_status === 'auto-draft') return;

    $s = fsahsa_sync_get_settings();
    $post_type = $s['post_type'] ?? 'job_listing';
    if ($post->post_type !== $post_type) return;

    // Only send if at least one mapping needs WP->SF
    $needs = false;
    foreach (($s['field_map'] ?? []) as $row) {
        $dir = $row['direction'] ?? 'off';
        if (in_array($dir, ['wp_to_sf','bidir'], true)) { $needs = true; break; }
    }
    if (!$needs) return;

    // Determine sync action for routing.
    // If this save was triggered inside the plugin's inbound REST handler, it sets
    // $GLOBALS['fsahsa_sync_inbound_context']['action'] to one of:
    // - sf_to_wp_create
    // - sf_to_wp_update
    // Otherwise, treat as a WP-origin UPDATE.
    // IMPORTANT: "create" is handled via transition_post_status when the listing becomes pending/publish,
    // to avoid sending empty auto-draft payloads.
    $action = '';
    if (!empty($GLOBALS['fsahsa_sync_inbound_context']) && is_array($GLOBALS['fsahsa_sync_inbound_context'])) {
        $action = (string)($GLOBALS['fsahsa_sync_inbound_context']['action'] ?? '');
    }
    if (!$action) {
        if (!$update) return; // don't emit wp_to_sf_create here
        $action = 'wp_to_sf_update';
    }

    // Stamp "last touched" markers for WP-origin changes so Zapier can filter loops.
    // IMPORTANT: Do NOT overwrite these during inbound SF->WP syncs.
    if (strpos($action, 'wp_to_sf') === 0) {
        update_post_meta($post_id, '_fsahsa_last_synced_by', 'WordPress');
        update_post_meta($post_id, '_fsahsa_last_synced_at', function_exists('current_time') ? current_time('c') : gmdate('c'));
    }

    fsahsa_sync_schedule_post_to_zapier($post_id, $action);
}, 99, 3);

add_action('transition_post_status', function($new_status, $old_status, $post){
    if (!$post instanceof WP_Post) return;
    // Prevent noisy/empty outbound events.
    if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) return;
    if ($new_status === 'auto-draft') return;

    $s = fsahsa_sync_get_settings();
    $post_type = $s['post_type'] ?? 'job_listing';
    if ($post->post_type !== $post_type) return;

    // Only send if at least one mapping needs WP->SF
    $needs = false;
    foreach (($s['field_map'] ?? []) as $row) {
        $dir = $row['direction'] ?? 'off';
        if (in_array($dir, ['wp_to_sf','bidir'], true)) { $needs = true; break; }
    }
    if (!$needs) return;

    // If this transition is happening as part of an inbound SF->WP sync, don't emit WP->SF create.
    if (!empty($GLOBALS['fsahsa_sync_inbound_context']) && is_array($GLOBALS['fsahsa_sync_inbound_context'])) {
        $in_action = (string)($GLOBALS['fsahsa_sync_inbound_context']['action'] ?? '');
        if (strpos($in_action, 'sf_to_wp') === 0) return;
    }

    // Treat "create" as the first time a listing becomes real (pending/publish).
    $create_targets = ['pending','publish'];
    $create_sources = ['auto-draft','draft','new'];
    if (in_array($new_status, $create_targets, true) && in_array($old_status, $create_sources, true)) {
        $action = 'wp_to_sf_create';

        // Stamp markers so Zapier can filter loops.
        update_post_meta($post->ID, '_fsahsa_last_synced_by', 'WordPress');
        update_post_meta($post->ID, '_fsahsa_last_synced_at', function_exists('current_time') ? current_time('c') : gmdate('c'));

        fsahsa_sync_schedule_post_to_zapier($post->ID, $action);
    }
}, 10, 3);


function fsahsa_sync_read_wp_field($post_id, $wp_field) {
    if ($wp_field === 'post_id') return $post_id;
    if ($wp_field === 'post_title') return get_the_title($post_id);
    if ($wp_field === 'post_status') {
        $p = get_post($post_id);
        return $p ? $p->post_status : '';
    }
    if ($wp_field === 'permalink') return get_permalink($post_id);
    if ($wp_field === 'slug') {
        $p = get_post($post_id);
        return $p ? $p->post_name : '';
    }
    if ($wp_field === 'modified_gmt') {
        $p = get_post($post_id);
        return $p ? get_post_modified_time('c', true, $p) : '';
    }
    if ($wp_field === 'listing_type_slug') {
        // MyListing stores Listing Type in meta key _case27_listing_type.
        // In your install this is often stored as an ARRAY with the slug (e.g. ['unclaimed-profiles']).
        // Some installs store a numeric Listing Type post ID. We handle both.
        $raw = get_post_meta($post_id, '_case27_listing_type', true);

        // If it's an array, prefer first non-empty element.
        if (is_array($raw) && !empty($raw)) {
            foreach ($raw as $v) {
                if (is_string($v) && $v !== '') return sanitize_title($v);
                if (is_numeric($v) && (int)$v > 0) {
                    $t = get_post((int)$v);
                    if ($t && !empty($t->post_name)) return (string)$t->post_name;
                }
            }
        }

        // If it's a string slug, use it.
        if (is_string($raw) && $raw !== '' && !is_numeric($raw)) {
            return sanitize_title($raw);
        }

        // If it's a numeric ID, resolve to slug.
        if (is_numeric($raw) && (int)$raw > 0) {
            $t = get_post((int)$raw);
            if ($t && !empty($t->post_name)) return (string)$t->post_name;
        }

        // Fallback: attempt to read any listing-type related taxonomy terms if present.
        $taxes = get_object_taxonomies(get_post_type($post_id));
        foreach (['job_listing_type','case27_job_listing_type','listing_type'] as $tx) {
            if (in_array($tx, $taxes, true)) {
                $terms = wp_get_object_terms($post_id, $tx, ['fields'=>'slugs']);
                if (!is_wp_error($terms) && !empty($terms)) return (string)$terms[0];
            }
        }
        return '';
    }
    if ($wp_field === 'listing_type_slugs') {
        // Prefer MyListing meta key _case27_listing_type if present.
        $raw = get_post_meta($post_id, '_case27_listing_type', true);
        $out = [];
        if (is_array($raw) && !empty($raw)) {
            foreach ($raw as $v) {
                if (is_string($v) && $v !== '') $out[] = sanitize_title($v);
                if (is_numeric($v) && (int)$v > 0) {
                    $t = get_post((int)$v);
                    if ($t && !empty($t->post_name)) $out[] = (string)$t->post_name;
                }
            }
        } elseif (is_string($raw) && $raw !== '') {
            $out[] = is_numeric($raw) ? '' : sanitize_title($raw);
        } elseif (is_numeric($raw) && (int)$raw > 0) {
            $t = get_post((int)$raw);
            if ($t && !empty($t->post_name)) $out[] = (string)$t->post_name;
        }
        $out = array_values(array_filter(array_unique($out)));
        if (!empty($out)) return $out;

        // Fallback: common MyListing taxonomies
        $taxes = get_object_taxonomies(get_post_type($post_id));
        $preferred = null;
        foreach (['job_listing_type','case27_job_listing_type','listing_type'] as $t) {
            if (in_array($t, $taxes, true)) { $preferred = $t; break; }
        }
        if (!$preferred && !empty($taxes)) $preferred = $taxes[0];
        if (!$preferred) return [];
        $terms = wp_get_object_terms($post_id, $preferred, ['fields'=>'slugs']);
        return is_wp_error($terms) ? [] : $terms;
    }
    if (str_starts_with($wp_field, 'meta:')) {
        $key = substr($wp_field, 5);
        return get_post_meta($post_id, $key, true);
    }
    if (str_starts_with($wp_field, 'taxonomy:')) {
        $tax = substr($wp_field, 9);
        $terms = wp_get_object_terms($post_id, $tax, ['fields'=>'slugs']);
        return is_wp_error($terms) ? [] : $terms;
    }
    return null;
}

function fsahsa_sync_send_post_to_zapier($post_id, $sync_action = 'wp_to_sf_update') {
    $s = fsahsa_sync_get_settings();
    $route = fsahsa_sync_resolve_outbound_webhook($post_id, $s, $sync_action);
    $url = $route['url'] ?? '';
    if (!$url) return;

    // Normalize action early so we can use it while building computed fields.
    $sync_action = sanitize_key((string)$sync_action);
    if (!$sync_action) $sync_action = 'wp_to_sf_update';
    $is_wp_origin = (strpos($sync_action, 'wp_to_sf') === 0);
    $is_sf_origin = (strpos($sync_action, 'sf_to_wp') === 0);

    // Compute "stamp" values that we want available BOTH in Salesforce fields and in the webhook payload
    // so Zapier can filter without having to peek into SF field mappings.
    $stamp_by = $is_wp_origin ? 'WordPress' : ($is_sf_origin ? 'Salesforce' : 'WordPress');
    $stamp_at = function_exists('current_time') ? current_time('c') : gmdate('c');

    // "Last synced" is what you should filter on in Zapier to prevent ping-pong.
    // Prefer stored meta (set on WP saves and inbound SF syncs). Fall back to the current stamp.
    $synced_by = (string)get_post_meta($post_id, '_fsahsa_last_synced_by', true);
    $synced_at = (string)get_post_meta($post_id, '_fsahsa_last_synced_at', true);
    if (!$synced_by) $synced_by = $stamp_by;
    if (!$synced_at) $synced_at = $stamp_at;

    $sf_fields = [];
    foreach (($s['field_map'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $dir = $row['direction'] ?? 'off';
        if (!in_array($dir, ['wp_to_sf','bidir'], true)) continue;

        $wp_field = $row['wp_field'] ?? '';
        $sf_field = $row['sf_field'] ?? '';
        $transform = $row['transform'] ?? '';
        if (!$sf_field) continue;

        // Don't outbound-write SF Id
        if ($sf_field === 'Id') continue;

        // Special-case: Last_Synced_By__c / Last_Synced_At__c are system stamps.
        // Users commonly expect these to be set without needing a WP meta source.
        // If the mapping row exists, we populate them based on the origin of the change.
        if ($sf_field === 'Last_Synced_By__c') {
            $sf_fields[$sf_field] = $stamp_by;
            continue;
        }
        if ($sf_field === 'Last_Synced_At__c') {
            // ISO 8601 using WordPress site timezone ("system" time from WP settings).
            // This lets you display it in local time by setting Settings -> General -> Timezone.
            $sf_fields[$sf_field] = $stamp_at;
            continue;
        }

        $val = null;
        if ($wp_field) {
            $val = fsahsa_sync_read_wp_field($post_id, $wp_field);
            if ($val === null) continue;
        }

        // If no WP field was provided, allow transform-only rows to compute values
        // from post context (e.g. RecordTypeId from MyListing listing type).
        if (!$wp_field && $transform) {
            if (strpos((string)$transform, 'record_type_id_from_listing_type') !== false) {
                $val = fsahsa_sync_read_wp_field($post_id, 'listing_type_slug');
            }
        }

        $val = fsahsa_sync_apply_transforms($val, $transform, [], [], $s);
        $sf_fields[$sf_field] = $val;
    }

    // NOTE: Expose ONLY one canonical pair of "last synced" fields in the webhook payload.
    // Zapier schema caching can make duplicate-ish keys feel like "new fields".
    // These values reflect the stored WP meta keys:
    //   _fsahsa_last_synced_by / _fsahsa_last_synced_at
    // Optionally include extra WP meta keys in the outbound payload so new WP fields
    // automatically become available in Zapier without a plugin update.
    $extra_meta_payload = [];
    $extra_keys_raw = (string)($s['extra_payload_meta_keys'] ?? '');
    if ($extra_keys_raw !== '') {
        $lines = preg_split('/[\r\n,]+/', $extra_keys_raw);
        foreach ($lines as $line) {
            $k = trim((string)$line);
            if ($k === '') continue;
            // Read single meta value; if arrays are stored, normalize to JSON.
            $v = get_post_meta($post_id, $k, true);
            if (is_array($v) || is_object($v)) {
                $v = wp_json_encode($v);
            }
            if (is_bool($v)) $v = $v ? '1' : '0';
            if ($v === null) $v = '';
            if (!is_scalar($v)) $v = (string)$v;
            $extra_meta_payload[$k] = $v;
        }
    }

    $payload = [
        'event' => 'provider_profile.' . $sync_action,
        'source' => 'wordpress',
        'object' => 'Provider_Profile__c',
        'zap_route_key' => $route['key'] ?? '',
        'sync_action' => $sync_action,
        // Zapier filter fields:
        'last_synced_by' => $synced_by,
        'last_synced_at' => $synced_at,
        'wp' => [
            'post_id' => $post_id,
            'post_type' => get_post_type($post_id),
            'post_status' => get_post_status($post_id),
            'listing_type' => fsahsa_sync_read_wp_field($post_id, 'listing_type_slug'),
            'listing_type_slugs' => fsahsa_sync_read_wp_field($post_id, 'listing_type_slugs'),
            'permalink' => get_permalink($post_id),
            'modified_gmt' => fsahsa_sync_read_wp_field($post_id, 'modified_gmt'),
            'meta' => $extra_meta_payload,
        ],
        'sf' => [
            'id' => (string)get_post_meta($post_id, $s['sf_id_meta_key'], true),
            'fields' => $sf_fields,
        ],
    ];

    // Debug log outbound payload + response
    fsahsa_sync_debug_log('OUT', 'Outbound '.$payload['event'], [
        'url' => $url,
        'route' => $route,
        'payload' => $payload,
    ], (string)($payload['wp']['post_id'] ?? ''));

    $resp = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode($payload),
        'timeout' => 20,
    ]);

    $code = is_wp_error($resp) ? 'ERR' : (string)wp_remote_retrieve_response_code($resp);
    $body = is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_body($resp);
    fsahsa_sync_debug_log($code, 'Outbound response '.$payload['event'], [
        'url' => $url,
        'code' => $code,
        'body' => $body,
    ], (string)($payload['wp']['post_id'] ?? ''));
}

/**
 * Resolve the outbound Zapier webhook URL.
 *
 * Supports routing to different Zaps based on settings:
 * - zapier_webhook_route_mode = default|listing_type_slug|post_type
 * - zapier_webhook_map = [route_key => webhook_url]
 *
 * Always falls back to zapier_webhook_url.
 */
function fsahsa_sync_resolve_outbound_webhook($post_id, $settings, $sync_action = '') {
    $fallback = $settings['zapier_webhook_url'] ?? '';
    $mode = $settings['zapier_webhook_route_mode'] ?? 'default';
    $map = $settings['zapier_webhook_map'] ?? [];
    if (!is_array($map)) $map = [];

    $sync_action = sanitize_key((string)$sync_action);

    // Build one or more candidate route keys depending on mode.
    // Keys are sanitized slugs, so use underscores between parts.
    $candidates = [];

    if ($mode === 'listing_type_slug') {
        $candidates[] = (string) fsahsa_sync_read_wp_field($post_id, 'listing_type_slug');
    } elseif ($mode === 'post_type') {
        $candidates[] = (string) get_post_type($post_id);
    } elseif ($mode === 'sync_action') {
        $candidates[] = (string) $sync_action;
    } elseif ($mode === 'sync_action_plus') {
        // Crisp WP-side routing:
        // 1) {sync_action}__{listing_type_slug}
        // 2) {sync_action}__{post_status}
        // 3) {sync_action}
        $lt = (string) fsahsa_sync_read_wp_field($post_id, 'listing_type_slug');
        $st = (string) get_post_status($post_id);
        if ($sync_action && $lt) $candidates[] = $sync_action . '__' . $lt;
        if ($sync_action && $st) $candidates[] = $sync_action . '__' . $st;
        if ($sync_action) $candidates[] = $sync_action;
    }

    // Sanitize candidates and find first matching URL.
    $key = '';
    $url = '';
    foreach ($candidates as $cand) {
        $cand = sanitize_key((string)$cand);
        if (!$cand) continue;
        if (isset($map[$cand]) && $map[$cand]) {
            $key = $cand;
            $url = esc_url_raw((string)$map[$cand]);
            break;
        }
    }

    // Allow dev overrides and always fall back to default.
    $final = apply_filters('fsahsa_sync_outbound_webhook_url', $url ?: $fallback, $post_id, $key, $settings);

    return [
        'key' => $key,
        'url' => $final,
        'fallback' => $fallback,
    ];
}
