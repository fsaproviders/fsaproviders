<?php
if (!defined('ABSPATH')) { exit; }

function fsahsa_sync_default_settings() {
    return [
        'secret' => 'fsahsa_sync_2026_zapier_secret',
        'zapier_webhook_url' => '',
        // Optional: route outbound webhooks to different Zaps.
        // Example: {"chiropractor": "https://hooks.zapier.com/...", "dentist": "https://hooks.zapier.com/..."}
        'zapier_webhook_map' => [],
        // Routing mode for outbound webhooks:
        // - default: always use zapier_webhook_url
        // - listing_type_slug: use MyListing Listing Type slug as the route key
        // - post_type: use WP post_type as the route key
        // - sync_action: use the sync action key (wp_to_sf_create/wp_to_sf_update/sf_to_wp_create/sf_to_wp_update)
        // - sync_action_plus: try {sync_action}__{listing_type_slug}, then {sync_action}__{post_status}, then {sync_action}
        'zapier_webhook_route_mode' => 'default',
        'post_type' => 'job_listing',
        // WP post meta key used to store the Salesforce record Id for a listing.
        // Keep this short and obvious so it's easy to map in Zapier/Salesforce.
        'sf_id_meta_key' => 'sf_id',

        // Inbound behavior
        // If enabled, SF -> WP requests will create a new listing when no matching post is found.
        'create_if_missing' => false,
        // When create_if_missing is enabled, new listings will be created with this status.
        // Recommended: draft (safe) then publish after review.
        'create_post_status' => 'draft',
        // If you have meta keys you want to appear in the WP Field picker even before any post has them,
        // add them here (comma-separated, one per line is fine). Example: address_mapping
        'extra_meta_keys' => "address_mapping",

        // Optional: include additional WP meta keys in the OUTBOUND webhook payload automatically.
        // One key per line. These are exported under wp.meta.<key> and as a convenience under wp.<key>.
        // Example:
        // telehealth_provider
        // job_location
        'extra_payload_meta_keys' => "",
        // Allow inbound requests to write exact WP meta keys via {meta:{...}} payload.
        'allow_direct_meta_keys' => true,

        // Diagnostics
        'debug_logging' => false,
        'debug_log_limit' => 50,

        // Server-side geocoding API key.
        // This must be a separate Google API key restricted to your server IP
        // (not the browser/referer key used by MyListing for the frontend map).
        // Required for Zapier/REST address imports to geocode correctly.
        'geocoding_api_key' => '',

        // mapping rows: each row is associative array
        // wp_field examples: post_title, post_status, permalink, slug, listing_type_slugs, meta:_job_phone, taxonomy:job_listing_type
        'field_map' => [
            ['wp_field'=>'post_title','sf_field'=>'Provider_Name__c','direction'=>'bidir','transform'=>'trim','conflict'=>'newer_wins'],
            ['wp_field'=>'meta:_job_website','sf_field'=>'Website__c','direction'=>'bidir','transform'=>'trim','conflict'=>'newer_wins'],
            ['wp_field'=>'meta:_job_phone','sf_field'=>'Phone__c','direction'=>'bidir','transform'=>'trim','conflict'=>'newer_wins'],
            ['wp_field'=>'meta:_job_email','sf_field'=>'Email__c','direction'=>'bidir','transform'=>'trim,lower','conflict'=>'newer_wins'],
            ['wp_field'=>'permalink','sf_field'=>'Provider_Profile_URL__c','direction'=>'wp_to_sf','transform'=>'','conflict'=>'sf_wins'],
            ['wp_field'=>'slug','sf_field'=>'Slug__c','direction'=>'wp_to_sf','transform'=>'','conflict'=>'sf_wins'],
            // NOTE: Salesforce field is Post_ID__c (NOT WP_Post_ID__c)
            ['wp_field'=>'post_id','sf_field'=>'Post_ID__c','direction'=>'wp_to_sf','transform'=>'','conflict'=>'sf_wins'],
            ['wp_field'=>'post_status','sf_field'=>'WP_Post_Status__c','direction'=>'wp_to_sf','transform'=>'','conflict'=>'sf_wins'],
            // MyListing Listing Type slug (single). Useful to drive Record Type automation in Salesforce.
            ['wp_field'=>'listing_type_slug','sf_field'=>'WP_Listing_Type__c','direction'=>'wp_to_sf','transform'=>'trim','conflict'=>'sf_wins'],
            // Standard SF Id mapping (inbound only): store to meta key
            ['wp_field'=>'meta:sf_id','sf_field'=>'sf_id','direction'=>'sf_to_wp','transform'=>'','conflict'=>'sf_wins'],
        ],
        // optional mapping for listing_type slug -> RecordTypeId
        'record_type_map' => [
            // 'chiropractor' => '012xxxxxxxxxxxx',
        ],
    ];
}

function fsahsa_sync_get_settings() {
    $raw = get_option(FSAHSA_SYNC_OPTION, null);

    if (!is_array($raw)) {
        // Option corrupted or not set.
        $raw = [];
    }
    $defaults = fsahsa_sync_default_settings();
    // Deep merge for known keys.
    $settings = $defaults;
    foreach ($raw as $k=>$v) {
        $settings[$k] = $v;
    }
    if (!isset($settings['field_map']) || !is_array($settings['field_map'])) {
        $settings['field_map'] = $defaults['field_map'];
    }
    return $settings;
}

function fsahsa_sync_update_settings($new) {
    if (!is_array($new)) $new = [];
    $current = fsahsa_sync_get_settings();
    $merged = $current;
    foreach ($new as $k=>$v) {
        $merged[$k] = $v;
    }
    // sanitize simple fields
    $merged['secret'] = sanitize_text_field($merged['secret'] ?? '');
    $merged['zapier_webhook_url'] = esc_url_raw($merged['zapier_webhook_url'] ?? '');
    $mode = sanitize_key($merged['zapier_webhook_route_mode'] ?? 'default');
    if (!in_array($mode, ['default','listing_type_slug','post_type','sync_action','sync_action_plus'], true)) {
        $mode = 'default';
    }
    $merged['zapier_webhook_route_mode'] = $mode;
    $merged['post_type'] = sanitize_key($merged['post_type'] ?? 'job_listing');
    $merged['sf_id_meta_key'] = sanitize_key($merged['sf_id_meta_key'] ?? 'sf_id');

    // diagnostics
    $merged['debug_logging'] = !empty($merged['debug_logging']);
    $merged['debug_log_limit'] = intval($merged['debug_log_limit'] ?? 50);
    if ($merged['debug_log_limit'] <= 0) $merged['debug_log_limit'] = 50;
    if ($merged['debug_log_limit'] > 200) $merged['debug_log_limit'] = 200;

    // Sanitize zapier webhook map.
    // Accept either associative form (zapier_webhook_map[key]=url) or parallel arrays from admin UI.
    if (!isset($merged['zapier_webhook_map']) || !is_array($merged['zapier_webhook_map'])) {
        $merged['zapier_webhook_map'] = [];
    }
    if (!empty($merged['zapier_webhook_map_key']) && is_array($merged['zapier_webhook_map_key'])
        && !empty($merged['zapier_webhook_map_url']) && is_array($merged['zapier_webhook_map_url'])) {
        $assoc = [];
        $keys = array_values($merged['zapier_webhook_map_key']);
        $urls = array_values($merged['zapier_webhook_map_url']);
        $n = min(count($keys), count($urls));
        for ($i=0; $i<$n; $i++) {
            $k = sanitize_key((string)$keys[$i]);
            $u = esc_url_raw((string)$urls[$i]);
            if ($k === '' || $u === '') continue;
            $assoc[$k] = $u;
        }
        $merged['zapier_webhook_map'] = $assoc;
        unset($merged['zapier_webhook_map_key']);
        unset($merged['zapier_webhook_map_url']);
    }
    $clean_map = [];
    foreach (($merged['zapier_webhook_map'] ?? []) as $k => $u) {
        $k2 = sanitize_key((string)$k);
        $u2 = esc_url_raw((string)$u);
        if ($k2 === '' || $u2 === '') continue;
        $clean_map[$k2] = $u2;
    }
    $merged['zapier_webhook_map'] = $clean_map;

    // Checkbox: if unchecked, the field is omitted from POST. Treat omission as false.
    $merged['create_if_missing'] = isset($new['create_if_missing']) && !empty($new['create_if_missing']);
    $allowed_status = ['draft','publish','pending','private'];
    $merged['create_post_status'] = sanitize_key($merged['create_post_status'] ?? 'draft');
    if (!in_array($merged['create_post_status'], $allowed_status, true)) {
        $merged['create_post_status'] = 'draft';
    }

    if (!isset($merged['field_map']) || !is_array($merged['field_map'])) $merged['field_map'] = [];
    // Sanitize rows
    $rows = [];
    foreach ($merged['field_map'] as $row) {
        if (!is_array($row)) continue;
        $rows[] = [
            'wp_field' => sanitize_text_field($row['wp_field'] ?? ''),
            'sf_field' => sanitize_text_field($row['sf_field'] ?? ''),
            'direction' => sanitize_text_field($row['direction'] ?? 'off'),
            'transform' => sanitize_text_field($row['transform'] ?? ''),
            'conflict' => sanitize_text_field($row['conflict'] ?? 'newer_wins'),
        ];
    }
    $merged['field_map'] = $rows;

    
    $merged['extra_meta_keys'] = sanitize_textarea_field($merged['extra_meta_keys'] ?? '');

    // Extra outbound payload meta keys
    $merged['extra_payload_meta_keys'] = sanitize_textarea_field($merged['extra_payload_meta_keys'] ?? '');
    // Checkbox: if unchecked, the field is omitted from POST. Treat omission as false.
    $merged['allow_direct_meta_keys'] = isset($new['allow_direct_meta_keys']) && !empty($new['allow_direct_meta_keys']);

if (!isset($merged['record_type_map']) || !is_array($merged['record_type_map'])) {
        $merged['record_type_map'] = [];
    }
    // Sanitize record type map: listing_type_slug => RecordTypeId
    // Accept either associative array form (record_type_map[slug]=id) or parallel arrays from the admin UI.
    if (!empty($merged['record_type_map_slug']) && is_array($merged['record_type_map_slug']) && !empty($merged['record_type_map_id']) && is_array($merged['record_type_map_id'])) {
        $assoc = [];
        $slugs = array_values($merged['record_type_map_slug']);
        $ids = array_values($merged['record_type_map_id']);
        $n = min(count($slugs), count($ids));
        for ($i=0; $i<$n; $i++) {
            $k = sanitize_key((string)$slugs[$i]);
            $v = sanitize_text_field((string)$ids[$i]);
            if ($k === '' || $v === '') continue;
            $assoc[$k] = $v;
        }
        $merged['record_type_map'] = $assoc;
        unset($merged['record_type_map_slug']);
        unset($merged['record_type_map_id']);
    }

	$rtm = [];
	$record_type_map = isset($merged['record_type_map']) && is_array($merged['record_type_map'])
		? $merged['record_type_map']
		: [];
	foreach ($record_type_map as $k => $v) {
        $k2 = sanitize_key((string)$k);
        $v2 = sanitize_text_field((string)$v);
        if ($k2 === '' || $v2 === '') continue;
        $rtm[$k2] = $v2;
    }
	$merged['record_type_map'] = $rtm;

	// IMPORTANT:
    // This function is used as the Settings API "sanitize_callback".
    // WordPress will call this function and then persist the returned value.
    // If we call update_option() here, it can cause recursion / fatal errors.
    return $merged;
}