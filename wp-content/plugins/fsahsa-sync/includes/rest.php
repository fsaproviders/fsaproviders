<?php
if (!defined('ABSPATH')) { exit; }

add_action('rest_api_init', function() {
    register_rest_route('fsahsa-sync/v1', '/provider-profile', [
        'methods'  => 'POST',
        'callback' => 'fsahsa_sync_rest_provider_profile',
        'permission_callback' => '__return_true',
    ]);


    register_rest_route('fsahsa-sync/v2', '/upsert-profile', [
        'methods'  => 'POST',
        'callback' => 'fsahsa_sync_rest_upsert_profile_v2',
        'permission_callback' => '__return_true',
    ]);

});

function fsahsa_sync_rest_provider_profile($request) {
    $s = fsahsa_sync_get_settings();

    // Auth
    $secret = $request->get_header('x-fsahsa-sync-secret');
    if (!$secret) { $secret = $request->get_header('X-FSAHSA-SYNC-SECRET'); }
    if (!$secret || !hash_equals((string)$s['secret'], (string)$secret)) {
        return new WP_REST_Response(['ok'=>false,'error'=>'unauthorized'], 401);
    }

    // Parse body robustly
    $payload = $request->get_json_params();
    if (empty($payload)) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) $payload = $decoded;
        }
    }
    // Unwrap array
    if (is_array($payload) && isset($payload[0]) && is_array($payload[0])) {
        $payload = $payload[0];
    }
    // Zapier sometimes nests under data
    if (is_array($payload) && isset($payload['data']) && is_string($payload['data'])) {
        $maybe = json_decode($payload['data'], true);
        if (json_last_error() === JSON_ERROR_NONE) $payload = $maybe;
    }

    if (is_string($payload)) {
        $maybe = json_decode($payload, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $payload = $maybe;
        }
    }
    if (!is_array($payload)) {
        return new WP_REST_Response(['ok'=>false,'error'=>'invalid_json'], 400);
    }

    // Zapier typically sends fields under { data: { ... } }
    $data = [];
    if (isset($payload['data']) && is_array($payload['data'])) {
        $data = $payload['data'];
    }

    // Normalize sf
    $sf = $payload['sf'] ?? null;
    if (is_string($sf)) {
        $sf_dec = json_decode($sf, true);
        if (json_last_error() === JSON_ERROR_NONE) $sf = $sf_dec;
    }
    if (!is_array($sf)) $sf = [];

    // Prefer sf_id (clear, consistent) but accept a few legacy aliases.
    $sfid = $sf['id'] ?? ($data['sf_id'] ?? ($payload['sf_id'] ?? ($payload['sfid'] ?? ($data['Id'] ?? ($payload['Id'] ?? null)))));

    $fields = $sf['fields'] ?? ($payload['fields'] ?? []);
    if (is_string($fields)) {
        $fields_dec = json_decode($fields, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $fields = $fields_dec;
        }
    }
    if (!is_array($fields)) { $fields = []; }

    // Merge Zapier-style {data:{...}} fields into our fields bag.
    if (!empty($data) && is_array($data)) {
        $fields = array_merge($fields, $data);
    }

    // Zapier payloads commonly look like:
    // { data: { sf_id: "...", record_type_id: "...", fields: { Name: "...", Phone__c: "..." } } }
    // We want those inner Salesforce fields to be available at the top level of $fields
    // so mapping rows can reference them directly.
    if (isset($fields['fields']) && is_array($fields['fields'])) {
        $inner = $fields['fields'];
        unset($fields['fields']);
        // Inner fields win on collisions.
        $fields = array_merge($fields, $inner);
    }

    // Also merge top-level scalar keys into fields so Zapier can send
    // Salesforce field API names (e.g. Phone__c) directly without nesting
    // under fields/data.
    // Reserved top-level keys are ignored.
    $reserved = ['sf','fields','data','wp','ok','error'];
    foreach ($payload as $k => $v) {
        if (!is_string($k)) continue;
        if (in_array($k, $reserved, true)) continue;
        if (array_key_exists($k, $fields)) continue;
        if (is_scalar($v) || $v === null) {
            $fields[$k] = $v;
        }
    }

    // Optional explicit meta bag: { meta: { _job_phone: "..." } }
    // If present, merge into fields as well so mapping rows can reference it.
    if (isset($payload['meta']) && is_array($payload['meta'])) {
        foreach ($payload['meta'] as $mk => $mv) {
            if (!is_string($mk)) continue;
            $fields[$mk] = $mv;
        }
    }
    

    // If the record id is inside fields, treat it as the record id.
    if (!$sfid && !empty($fields['sf_id'])) { $sfid = $fields['sf_id']; }
    if (!$sfid && !empty($fields['Id'])) { $sfid = $fields['Id']; }

    // Provide helpful aliases so mapping can use either sf_id or Id.
    if ($sfid) {
        $fields['sf_id'] = $sfid;
        $fields['Id'] = $sfid;
    }

    // We require at least an sfid OR at least one field to write.
    if (!$sfid && empty($fields)) {
        return new WP_REST_Response([
            'ok'=>false,
            'error'=>'missing_sf_id_or_fields',
            'debug'=>[
                'top_level_keys'=>array_keys($payload),
                'sf_is_array'=>is_array($sf),
                'sf_keys'=>is_array($sf) ? array_keys($sf) : [],
            ]
        ], 400);
    }

    // Find target post
    $post_id = fsahsa_sync_find_target_post_id($payload, $fields, $sfid);
    $created = false;
    $create_error = '';

    if (!$post_id && !empty($s['create_if_missing'])) {
        $post_id = fsahsa_sync_create_listing_from_sf($sfid, $fields, $s);
        if ($post_id) {
            $created = true;
            $payload['wp']['post_id'] = $post_id;
        }
        // If we attempted to create but got nothing back, include a useful note.
        if (!$post_id && !empty($GLOBALS['fsahsa_sync_last_create_error'])) {
            $create_error = (string)$GLOBALS['fsahsa_sync_last_create_error'];
        }
    }

    if (!$post_id) {
        // If no post found (or created), return ok with info (so Zapier doesn't retry forever)
        $note = $create_error ? 'create_failed' : 'no_matching_post';
        $resp = ['ok'=>true,'linked'=>false,'sf_id'=>$sfid,'note'=>$note];
        if ($create_error) {
            $resp['error'] = $create_error;
        }
        // Include minimal debug so you can tell if create_if_missing is truly enabled.
        $resp['debug'] = [
            'create_if_missing' => !empty($s['create_if_missing']),
            'post_type' => $s['post_type'] ?? 'job_listing',
        ];
        return new WP_REST_Response($resp, 200);
    }


    // Mark this request as an inbound SF→WP update so save_post outbound logic can avoid loop-stamping.
    $GLOBALS['fsahsa_sync_inbound_context'] = [
        'action' => $created ? 'sf_to_wp_create' : 'sf_to_wp_update',
        'source' => 'salesforce',
        'ref' => 'v1',
    ];

    // Always store SF id in meta.
    // Canonical: sf_id_meta_key (default: sf_id)
    // UI: sfdc_provider_profile_id (keeps the MyListing field populated)
    // Legacy: _sf_provider_profile_id is removed/migrated.
    if ($sfid) {
        $canonical_key = $s['sf_id_meta_key'] ?? 'sf_id';
        update_post_meta($post_id, $canonical_key, $sfid);
        update_post_meta($post_id, 'sfdc_provider_profile_id', $sfid);
        delete_post_meta($post_id, '_sf_provider_profile_id');
    }

    // Direct meta updates (optional but very useful for Zapier):
    // Allow callers to pass an explicit meta object, e.g.
    // { "sf_id": "...", "meta": { "_job_phone": "...", "_job_website": "..." } }
    // This bypasses any ambiguity about WP field names.
    $meta_obj = null;
    if (isset($payload['meta']) && is_array($payload['meta'])) {
        $meta_obj = $payload['meta'];
    } elseif (isset($fields['meta']) && is_array($fields['meta'])) {
        $meta_obj = $fields['meta'];
    }
    if (is_array($meta_obj)) {
        foreach ($meta_obj as $mk => $mv) {
            if (!is_string($mk) || $mk === '') continue;
            // Allow either "meta:_key" or "_key".
            if (str_starts_with($mk, 'meta:')) {
                $mk = substr($mk, 5);
            }
            // Basic safety: only simple meta keys.
            if (!preg_match('/^[A-Za-z0-9_:-]+$/', $mk)) continue;
            update_post_meta($post_id, $mk, $mv);
        }
    }

    // Apply mappings SF -> WP
    $applied = [];

    // Optional direct taxonomy writes for Zapier simplicity.
    // Payload shapes supported:
    //  - { "taxonomies": { "your_tax_slug": "A;B;C" } }
    //  - { "taxonomies": { "your_tax_slug": ["A","B","C"] } }
    //  - Inside fields/data: { taxonomies: {...} }
    // Each taxonomy value can be a Salesforce multi-select string (;) or comma/pipe separated.
    $tax_obj = null;
    if (isset($payload['taxonomies']) && is_array($payload['taxonomies'])) {
        $tax_obj = $payload['taxonomies'];
    } elseif (isset($fields['taxonomies']) && is_array($fields['taxonomies'])) {
        $tax_obj = $fields['taxonomies'];
    }
    if (is_array($tax_obj)) {
        foreach ($tax_obj as $tax_slug => $tax_val) {
            if (!is_string($tax_slug) || $tax_slug === '') continue;
            // Basic safety: only simple taxonomy slugs.
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $tax_slug)) continue;
            $terms = fsahsa_sync_parse_terms($tax_val);
            if (empty($terms)) continue;
            wp_set_object_terms($post_id, $terms, $tax_slug, false);
            $applied[] = ['wp'=>"taxonomy:$tax_slug",'sf'=>'(direct)'];
        }
    }

    foreach (($s['field_map'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $dir = $row['direction'] ?? 'off';
        if (!in_array($dir, ['sf_to_wp','bidir'], true)) continue;

        $wp_field = $row['wp_field'] ?? '';
        $sf_field = $row['sf_field'] ?? '';
        if (!$wp_field || !$sf_field) continue;

        $val = $fields[$sf_field] ?? null;
        if ($sf_field === 'Id' || $sf_field === 'sf_id') $val = $sfid;

        if ($val === null) continue;

        $val = fsahsa_sync_apply_transforms($val, $row['transform'] ?? '', $payload, $fields, $s);

        if (fsahsa_sync_write_wp_field($post_id, $wp_field, $val)) {
            $applied[] = ['wp'=>$wp_field,'sf'=>$sf_field];
        }
    }

    // Direct meta writes (optional): when a caller sends exact WP meta keys.
    // This is intended for Zapier simplicity. It is gated by the shared secret.
    // Payload shapes supported:
    //  - { meta: { _job_phone: "...", _job_website: "..." } }
    //  - top-level { _job_phone: "..." } (already merged into $fields above)
    if (!empty($s['allow_direct_meta_keys'])) {
        // If explicit meta bag exists, write it.
        if (isset($payload['meta']) && is_array($payload['meta'])) {
            foreach ($payload['meta'] as $mk => $mv) {
                if (!is_string($mk) || $mk === '') continue;
                update_post_meta($post_id, $mk, $mv);
            }
        }
    }

    // Inbound sync stamp (Salesforce -> WordPress)
    // Store in internal meta so you can display/audit it on the WP side.
    update_post_meta($post_id, '_fsahsa_last_synced_by', 'Salesforce');
    update_post_meta($post_id, '_fsahsa_last_synced_at', function_exists('current_time') ? current_time('c') : gmdate('c'));

    // Cache busting for MyListing/WordPress.
    clean_post_cache($post_id);
    wp_cache_delete($post_id, 'post_meta');

    // Clear inbound context for safety.
    unset($GLOBALS['fsahsa_sync_inbound_context']);

    return new WP_REST_Response(['ok'=>true,'linked'=>true,'created'=>$created,'post_id'=>$post_id,'sf_id'=>$sfid,'applied'=>$applied], 200);
}

/**
 * Create a new listing post from Salesforce data (when enabled).
 * Returns new post ID or 0.
 */
function fsahsa_sync_create_listing_from_sf($sfid, $fields, $settings) {
    // Used by the REST handler to report why a create failed.
    $GLOBALS['fsahsa_sync_last_create_error'] = '';

    $post_type = $settings['post_type'] ?? 'job_listing';
    $status = $settings['create_post_status'] ?? 'draft';
    $title = (string)($fields['Provider_Name__c'] ?? ($fields['Name'] ?? 'New Provider'));
    $slug = !empty($fields['Slug__c']) ? sanitize_title((string)$fields['Slug__c']) : '';

    // Avoid duplicate slug creation.
    if ($slug) {
        $existing = get_page_by_path($slug, OBJECT, $post_type);
        if ($existing) return (int)$existing->ID;
    }

    $postarr = [
        'post_type' => $post_type,
        'post_status' => $status,
        'post_title' => $title,
        'post_content' => '',
    ];
    if ($slug) {
        $postarr['post_name'] = $slug;
    }

    $new_id = wp_insert_post($postarr, true);
    if (is_wp_error($new_id) || !$new_id) {
        if (is_wp_error($new_id)) {
            $GLOBALS['fsahsa_sync_last_create_error'] = $new_id->get_error_message();
        } else {
            $GLOBALS['fsahsa_sync_last_create_error'] = 'wp_insert_post_failed';
        }
        return 0;
    }

    // Store SF id immediately so subsequent matching works.
    if ($sfid) {
        $meta_key = $settings['sf_id_meta_key'] ?? 'sf_id';
        update_post_meta($new_id, $meta_key, $sfid);
        update_post_meta($new_id, 'sfdc_provider_profile_id', $sfid);
        delete_post_meta($new_id, '_sf_provider_profile_id');
    }

    // Apply Listing Type from Salesforce RecordTypeId when possible.
    // If SF provides record_type_id / RecordTypeId and you configured record_type_map in settings,
    // we reverse-map RecordTypeId -> Listing Type slug and set MyListing's _case27_listing_type.
    $rtid = $fields['record_type_id'] ?? ($fields['RecordTypeId'] ?? ($fields['recordTypeId'] ?? null));
    if ($rtid) {
        $lt_slug = fsahsa_sync_listing_type_slug_from_record_type_id($rtid, $settings);
        if ($lt_slug) {
            fsahsa_sync_set_listing_type_by_slug($new_id, $lt_slug);
        }
    }

    return (int)$new_id;
}

function fsahsa_sync_find_target_post_id($payload, $fields, $sfid = null) {
    $s = fsahsa_sync_get_settings();
    $post_type = $s['post_type'] ?? 'job_listing';

    // Meta keys:
    // - $s['sf_id_meta_key'] (default: sf_id) is the plugin's canonical Salesforce record Id storage.
    // - sfdc_provider_profile_id is the MyListing UI field meta key (kept in sync for visibility).
    // - _sf_provider_profile_id is legacy and will be migrated forward when encountered.
    $ui_meta_key = 'sfdc_provider_profile_id';
    $legacy_meta_key = '_sf_provider_profile_id';

    // 0) Preferred: match by Salesforce Id stored in post meta.
    if ($sfid) {
        $meta_key = $s['sf_id_meta_key'] ?? 'sf_id';

        // Current configured key.
        $q1 = new WP_Query([
            'post_type' => $post_type,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => [[
                'key' => $meta_key,
                'value' => $sfid,
                'compare' => '='
            ]],
        ]);
        if (!empty($q1->posts)) {
            return (int)$q1->posts[0];
        }

        // Also match by the UI meta key (in case Zapier mapped there).
        if ($meta_key !== $ui_meta_key) {
            $q_ui = new WP_Query([
                'post_type' => $post_type,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_query' => [[
                    'key' => $ui_meta_key,
                    'value' => $sfid,
                    'compare' => '='
                ]],
            ]);
            if (!empty($q_ui->posts)) {
                $id = (int)$q_ui->posts[0];
                // Backfill canonical meta key so future matches are consistent.
                update_post_meta($id, $meta_key, $sfid);
                return $id;
            }
        }

        // Legacy key support (migrate on hit, then delete legacy to prevent duplicate matching/mapping confusion).
        if ($meta_key !== $legacy_meta_key) {
            $q2 = new WP_Query([
                'post_type' => $post_type,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_query' => [[
                    'key' => $legacy_meta_key,
                    'value' => $sfid,
                    'compare' => '='
                ]],
            ]);
            if (!empty($q2->posts)) {
                $id = (int)$q2->posts[0];
                // Migrate forward so future matches hit the canonical key.
                update_post_meta($id, $meta_key, $sfid);
                update_post_meta($id, $ui_meta_key, $sfid);
                delete_post_meta($id, $legacy_meta_key);
                return $id;
            }
        }
    }

    // 1) Explicit wp.post_id
    if (isset($payload['wp']['post_id']) && is_numeric($payload['wp']['post_id'])) {
        $candidate = (int)$payload['wp']['post_id'];
        $p = get_post($candidate);
        if ($p && $p->post_type === $post_type) {
            return $candidate;
        }
    }
    // 1) Explicit wp.post_id
    if (isset($fields['Post_ID__c']) && is_numeric($fields['Post_ID__c'])) {
        $candidate = (int)$fields['Post_ID__c'];
        $p = get_post($candidate);
        if ($p && $p->post_type === $post_type) {
            return $candidate;
        }
    }
    // 2) From SF field Post_ID__c (WordPress Post ID)
    if (!empty($fields['Slug__c']) && is_string($fields['Slug__c'])) {
        $slug = sanitize_title($fields['Slug__c']);
        $p = get_page_by_path($slug, OBJECT, $post_type);
        if ($p) return (int)$p->ID;
    }
    // 3) From Slug__c
    // 4) From Provider_Profile_URL__c permalink
    if (!empty($fields['Provider_Profile_URL__c']) && is_string($fields['Provider_Profile_URL__c'])) {
        $id = url_to_postid($fields['Provider_Profile_URL__c']);
        if ($id) return (int)$id;
    }
    return 0;
}

function fsahsa_sync_apply_transforms($value, $transform_csv, $payload, $fields, $settings) {
    $t = array_filter(array_map('trim', explode(',', (string)$transform_csv)));
    foreach ($t as $cmd) {
        // Parametric join: join(;), join(|), join(\n), etc.
        // Helpful for Salesforce Multi-Select Picklists (semicolon-delimited).
        if (preg_match('/^join\((.*)\)$/', $cmd, $m)) {
            if (is_array($value)) {
                $delim = (string)$m[1];
                $delim = str_replace(['\\n','\\t'], ["\n","\t"], $delim);
                $value = implode($delim, $value);
            }
            continue;
        }
        switch ($cmd) {
            case 'trim':
                if (is_string($value)) $value = trim($value);
                break;
            case 'lower':
                if (is_string($value)) $value = strtolower($value);
                break;
            case 'upper':
                if (is_string($value)) $value = strtoupper($value);
                break;
            case 'join_comma':
                if (is_array($value)) $value = implode(',', $value);
                break;
            case 'join_semicolon':
                // Salesforce Multi-Select Picklists use semicolons.
                if (is_array($value)) $value = implode(';', $value);
                break;
            case 'record_type_id_from_listing_type':
                // expects $value to be slug or list of slugs
                $map = $settings['record_type_map'] ?? [];
                if (is_array($value)) {
                    foreach ($value as $candidate) {
                        $slug = (string)$candidate;
                        if ($slug && isset($map[$slug])) { $value = $map[$slug]; break; }
                    }
                } else {
                    $slug = (string)$value;
                    if ($slug && isset($map[$slug])) $value = $map[$slug];
                }
                break;
        }
    }
    return $value;
}



function fsahsa_sync_set_listing_type_by_slug($post_id, $slug_or_id) {
    // MyListing "Listing Type" is finicky:
    // - Some installs read from _case27_listing_type (common)
    // - Some read from case27_listing_type (seen in certain versions/metaboxes)
    // - The stored value should be the *post ID* of a case27_listing_type post
    //
    // To make the WP admin UI + front-end reliably reflect changes, we:
    // 1) resolve slug -> listing type post ID
    // 2) write BOTH meta keys (_case27_listing_type and case27_listing_type)
    // 3) clear post/meta caches

    // IMPORTANT (your install): the admin "Listing Type" dropdown uses option values that are
    // the LISTING TYPE SLUG (not the post ID). So we must store the slug in `_case27_listing_type`
    // for the UI to reflect the selected type.
    //
    // We still resolve the slug to the Listing Type post ID for diagnostics and compatibility,
    // but we store that ID in a separate meta key.

    $type_id = 0;
    $slug_to_store = '';

    if (is_numeric($slug_or_id)) {
        // If someone passed an ID, try to resolve it back to a slug for the UI.
        $type_id = (int) $slug_or_id;
        if ($type_id > 0) {
            $p = get_post($type_id);
            if ($p && $p->post_type === 'case27_listing_type') {
                $slug_to_store = (string) $p->post_name;
            }
        }
    } else {
        $slug = sanitize_title((string) $slug_or_id);
        if (!$slug) return false;
        $slug_to_store = $slug;

        // Primary: lookup by slug in the listing type CPT.
        $type_post = get_page_by_path($slug, OBJECT, 'case27_listing_type');

        // Fallback: query by 'name' within the CPT.
        if (!$type_post || empty($type_post->ID)) {
            $q = new WP_Query([
                'post_type'      => 'case27_listing_type',
                'posts_per_page' => 1,
                'post_status'    => 'any',
                'name'           => $slug,
                'fields'         => 'ids',
            ]);
            if (!empty($q->posts[0])) {
                $type_id = (int) $q->posts[0];
            }
            wp_reset_postdata();
        } else {
            $type_id = (int) $type_post->ID;
        }
    }

    // We require a slug for the admin dropdown.
    if (empty($slug_to_store)) return false;

    // Write both keys (covers different MyListing versions/metaboxes).
    // Delete existing rows first to avoid multiple values.
    delete_post_meta($post_id, '_case27_listing_type');
    delete_post_meta($post_id, 'case27_listing_type');
    delete_post_meta($post_id, '_case27_listing_type_id');
    delete_post_meta($post_id, 'case27_listing_type_id');

    // UI + MyListing metabox expects slug.
    update_post_meta($post_id, '_case27_listing_type', $slug_to_store);
    update_post_meta($post_id, 'case27_listing_type', $slug_to_store);

    // Store the numeric ID separately for debugging/compatibility.
    if ($type_id > 0) {
        update_post_meta($post_id, '_case27_listing_type_id', $type_id);
        update_post_meta($post_id, 'case27_listing_type_id', $type_id);
    }

    // Clear caches so the admin UI/front-end reflects the new type immediately.
    clean_post_cache($post_id);
    wp_cache_delete($post_id, 'post_meta');

    return true;
}



function fsahsa_sync_listing_type_slug_from_record_type_id($record_type_id, $settings) {
    $rt = (string)$record_type_id;
    if (!$rt) return '';
    $map = $settings['record_type_map'] ?? [];
    if (!is_array($map) || empty($map)) return '';
    // record_type_map is stored as listing_type_slug => RecordTypeId
    foreach ($map as $slug => $rid) {
        if ((string)$rid === $rt) return (string)$slug;
    }
    return '';
}


/**
 * Parse taxonomy/list term inputs coming from Zapier/Salesforce.
 * Accepts:
 *  - Array of terms
 *  - Salesforce multi-select string "A;B;C"
 *  - Comma string "A, B, C"
 *  - Pipe string "A|B|C"
 * Returns a clean array of term strings.
 */
function fsahsa_sync_parse_terms($value) {
    if ($value === null) return [];
    // If Zapier sends an object/array already
    if (is_array($value)) {
        $out = [];
        foreach ($value as $v) {
            if ($v === null) continue;
            if (is_string($v) || is_numeric($v)) {
                $s = trim((string)$v);
                if ($s !== '') $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }

    // If it's a JSON array string, decode it.
    if (is_string($value)) {
        $raw = trim($value);
        if ($raw === '') return [];
        if ((str_starts_with($raw, '[') && str_ends_with($raw, ']')) || (str_starts_with($raw, '{') && str_ends_with($raw, '}'))) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return fsahsa_sync_parse_terms($decoded);
            }
        }

        // Salesforce multi-select uses semicolons. Accept comma/pipe too.
        $delimiter = null;
        if (str_contains($raw, ';')) $delimiter = ';';
        elseif (str_contains($raw, '|')) $delimiter = '|';
        elseif (str_contains($raw, ',')) $delimiter = ',';

        if ($delimiter) {
            $parts = array_map('trim', explode($delimiter, $raw));
            $parts = array_filter($parts, function($x){ return $x !== ''; });
            return array_values(array_unique($parts));
        }

        // Single term
        return [$raw];
    }

    // Numbers/bools etc.
    if (is_scalar($value)) {
        $s = trim((string)$value);
        return $s === '' ? [] : [$s];
    }

    return [];
}

function fsahsa_sync_write_wp_field($post_id, $wp_field, $value) {
    if ($wp_field === 'post_title') {
        wp_update_post(['ID'=>$post_id,'post_title'=> (string)$value]);
        return true;
    }
    if ($wp_field === 'post_status') {
        wp_update_post(['ID'=>$post_id,'post_status'=> (string)$value]);
        return true;
    }
    if ($wp_field === 'slug') {
        wp_update_post(['ID'=>$post_id,'post_name'=> sanitize_title((string)$value)]);
        return true;
    }
if ($wp_field === 'listing_type_slug') {
        return fsahsa_sync_set_listing_type_by_slug($post_id, $value);
    }
    if ($wp_field === 'listing_type_slugs') {
        // Accept Salesforce multi-select (;) or comma/pipe separated or array; apply the first as primary listing type.
        $slugs = fsahsa_sync_parse_terms($value);
        if (!empty($slugs)) {
            return fsahsa_sync_set_listing_type_by_slug($post_id, $slugs[0]);
        }
        return false;
    }
    if (str_starts_with($wp_field, 'meta:')) {
        $key = substr($wp_field, 5);
        update_post_meta($post_id, $key, $value);
        return true;
    }

    // Convenience: allow using exact meta keys directly (no "meta:").
    // Example: wp_field = _job_phone.
    // We only allow "meta-like" keys (start with '_' or contain '_') to
    // avoid accidentally treating pseudo-fields as meta.
    $reserved = ['post_title','post_status','slug','permalink','post_id','listing_type_slug','listing_type_slugs'];
    if (!in_array($wp_field, $reserved, true)
        && preg_match('/^[A-Za-z0-9_:-]+$/', $wp_field)
        && (str_starts_with($wp_field, '_') || str_contains($wp_field, '_'))
    ) {
        update_post_meta($post_id, $wp_field, $value);
        return true;
    }
    if (str_starts_with($wp_field, 'taxonomy:')) {
        $tax = substr($wp_field, 9);
        $terms = fsahsa_sync_parse_terms($value);
        if (empty($terms)) return false;
        // set terms by slug/name
        wp_set_object_terms($post_id, $terms, $tax, false);
        return true;
    }
    // read-only computed fields ignored inbound
    return false;
}



function fsahsa_sync_debug_log($status, $msg, $data = null, $ref = '') {
    $s = fsahsa_sync_get_settings();
    if (empty($s['debug_logging'])) return;

    // Redact obvious secrets
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match('/secret|token|authorization/i', $k)) {
                $data[$k] = 'REDACTED';
            }
        }
    }

    // Prevent memory blow-ups: cap stored debug payload size.
    // We still write a short line to PHP error_log for quick triage.
    $max_bytes = 8000; // stored in DB; keep small to avoid autoload/memory issues
    $encoded = null;
    if ($data !== null) {
        $encoded = wp_json_encode($data);
        if (is_string($encoded) && strlen($encoded) > $max_bytes) {
            $data = [
                '_truncated' => true,
                'bytes' => strlen($encoded),
                'hash' => hash('sha256', $encoded),
                'top_keys' => is_array($data) ? array_slice(array_keys($data), 0, 25) : null,
            ];
        }
    }

    $entry = [
        'ts' => gmdate('c'),
        'status' => (string)$status,
        'msg' => (string)$msg,
        'ref' => (string)$ref,
        'data' => $data,
    ];

    $logs = get_option(FSAHSA_SYNC_LOG_OPTION, []);
    if (!is_array($logs)) $logs = [];
    array_unshift($logs, $entry);

    $limit = intval($s['debug_log_limit'] ?? 50);
    if ($limit <= 0) $limit = 50;
    $logs = array_slice($logs, 0, $limit);

    update_option(FSAHSA_SYNC_LOG_OPTION, $logs, false);

    if (function_exists('error_log')) {
        error_log('[FSAHSA Sync] '.$status.' '.$msg.' '.$ref);
    }
}

function fsahsa_sync_v2_payload_hash($payload) {
    return hash('sha256', wp_json_encode($payload));
}

function fsahsa_sync_v2_find_post_id($sf_id, $post_id_in, $slug_in, $settings) {
    $post_type = $settings['post_type'] ?? 'job_listing';

    // 1) sf_id via canonical key, UI key, legacy key
    if (!empty($sf_id)) {
        $meta_key = $settings['sf_id_meta_key'] ?? 'sf_id';
        $ui_meta_key = 'sfdc_provider_profile_id';
        $legacy_meta_key = '_sf_provider_profile_id';

        foreach ([$meta_key, $ui_meta_key, $legacy_meta_key] as $k) {
            $q = new WP_Query([
                'post_type' => $post_type,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_query' => [[
                    'key' => $k,
                    'value' => $sf_id,
                    'compare' => '='
                ]],
            ]);
            if (!empty($q->posts)) {
                $id = (int)$q->posts[0];
                // Migrate to canonical + UI key for consistency
                update_post_meta($id, $meta_key, $sf_id);
                update_post_meta($id, $ui_meta_key, $sf_id);
                delete_post_meta($id, $legacy_meta_key);
                return $id;
            }
        }
    }

    // 2) post_id
    if (!empty($post_id_in)) {
        $candidate = (int)$post_id_in;
        $p = get_post($candidate);
        if ($p && $p->post_type === $post_type) return $candidate;
    }

    // 3) slug
    if (!empty($slug_in)) {
        $slug = sanitize_title((string)$slug_in);
        $p = get_page_by_path($slug, OBJECT, $post_type);
        if ($p) return (int)$p->ID;
    }

    return 0;
}

function fsahsa_sync_v2_write_meta_exact($post_id, $meta) {
    $written = [];
    if (!is_array($meta)) return $written;

    foreach ($meta as $k => $v) {
        if (!is_string($k) || $k === '') continue;

        // Taxonomy writes (use exact taxonomy slug after "taxonomy:")
        if (str_starts_with($k, 'taxonomy:')) {
            $tax = substr($k, 9);

            // null => clear terms
            if ($v === null) {
                wp_set_object_terms($post_id, [], $tax, false);
                $written[] = $k.' (cleared)';
                continue;
            }

            // Normalize term input. Supports arrays and delimiter-separated strings
            // including Salesforce multi-select format (semicolon).
            $terms = fsahsa_sync_parse_terms($v);
            if (empty($terms)) {
                wp_set_object_terms($post_id, [], $tax, false);
                $written[] = $k.' (cleared)';
                continue;
            }

            // best effort: set by slug/name/ID
            wp_set_object_terms($post_id, $terms, $tax, false);
            $written[] = $k;
            continue;
        }

        // null => delete meta
        if ($v === null) {
            delete_post_meta($post_id, $k);
            $written[] = $k.' (deleted)';
            continue;
        }

        // Empty string => skip. Don't overwrite a real value with blank.
        // This prevents Zapier sending "" for unmapped fields from clearing
        // existing data (e.g. _job_location getting blanked before address arrives).
        if ($v === '') {
            $written[] = $k.' (skipped-empty)';
            continue;
        }

        if (is_array($v) || is_object($v)) {
            // Single-element arrays are almost always an accidental wrap —
            // unwrap them so _job_location stores a plain string, not array(0=>'...').
            if ( is_array($v) && count($v) === 1 && isset($v[0]) && is_scalar($v[0]) ) {
                $v = $v[0];
            } else {
                $v = wp_json_encode($v);
            }
        }
        if ($v === true) $v = '1';
        if ($v === false) $v = '0';

        // Hours fields must be stored as PHP arrays so the MyListing theme
        // can read them via get_post_meta(). If a JSON string arrives, decode
        // it into a native array before saving.
        if ( ( $k === '_work_hours' || $k === '_job_hours' ) && is_string( $v ) ) {
            $decoded = json_decode( $v, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                $v = $decoded;
            }
        }

        update_post_meta($post_id, $k, $v);
        $written[] = $k;

        // For _job_location specifically, ensure it's always stored as a plain
        // string. If it somehow ends up as an array, rewrite it immediately.
        if ( $k === '_job_location' && is_array( get_post_meta( $post_id, '_job_location', true ) ) ) {
            $flat = trim( (string) reset( get_post_meta( $post_id, '_job_location', true ) ) );
            if ( $flat ) update_post_meta( $post_id, '_job_location', $flat );
        }
    }
    return $written;
}


function fsahsa_sync_rest_upsert_profile_v2($request) {
    $s = fsahsa_sync_get_settings();

    // Auth (same as v1)
    $secret = $request->get_header('x-fsahsa-sync-secret');
    if (!$secret) { $secret = $request->get_header('X-FSAHSA-SYNC-SECRET'); }
    if (!$secret || !hash_equals((string)$s['secret'], (string)$secret)) {
        fsahsa_sync_debug_log('401', 'Unauthorized (v2).', null, '');
        return new WP_REST_Response(['ok'=>false,'error'=>'unauthorized'], 401);
    }

    $payload = $request->get_json_params();
    if (!is_array($payload)) {
        return new WP_REST_Response(['ok'=>false,'error'=>'invalid_json'], 400);
    }

    $ref = substr(hash('sha256', wp_json_encode($payload).microtime(true)), 0, 10);

    $sf_id = isset($payload['sf_id']) ? (string)$payload['sf_id'] : '';
    $post_id_in = isset($payload['post_id']) ? (int)$payload['post_id'] : 0;
    $slug_in = isset($payload['slug']) ? (string)$payload['slug'] : '';

    $create_if_missing = array_key_exists('create_if_missing', $payload) ? (bool)$payload['create_if_missing'] : !empty($s['create_if_missing']);
    $source = isset($payload['source']) ? sanitize_text_field($payload['source']) : 'zapier';
    $event_id = isset($payload['event_id']) ? sanitize_text_field($payload['event_id']) : '';
    $updated_at = isset($payload['updated_at']) ? sanitize_text_field($payload['updated_at']) : '';
    $listing_type = isset($payload['listing_type']) ? sanitize_title((string)$payload['listing_type']) : '';

    // Record Type → Listing Type support (works in v2 as well as v1).
    // If Zapier/Salesforce sends a RecordTypeId (or record_type_id), and you configured the
    // Record Type Mapper (Listing Type slug → RecordTypeId), we can reverse-map that
    // RecordTypeId back into a Listing Type slug automatically.
    $record_type_id = '';
    if (isset($payload['record_type_id'])) $record_type_id = (string)$payload['record_type_id'];
    if (!$record_type_id && isset($payload['RecordTypeId'])) $record_type_id = (string)$payload['RecordTypeId'];
    if (!$record_type_id && isset($payload['recordTypeId'])) $record_type_id = (string)$payload['recordTypeId'];

    $fields = (isset($payload['fields']) && is_array($payload['fields'])) ? $payload['fields'] : [];

    if (!$record_type_id && isset($fields['record_type_id'])) $record_type_id = (string)$fields['record_type_id'];
    if (!$record_type_id && isset($fields['RecordTypeId'])) $record_type_id = (string)$fields['RecordTypeId'];
    if (!$record_type_id && isset($fields['recordTypeId'])) $record_type_id = (string)$fields['recordTypeId'];

    $listing_type_derived = false;
    if (empty($listing_type) && !empty($record_type_id) && function_exists('fsahsa_sync_listing_type_slug_from_record_type_id')) {
        $lt = fsahsa_sync_listing_type_slug_from_record_type_id($record_type_id, $s);
        if (!empty($lt)) {
            $listing_type = sanitize_title((string)$lt);
            $listing_type_derived = true;
        }
    }

    // Find post
    $post_id = fsahsa_sync_v2_find_post_id($sf_id, $post_id_in, $slug_in, $s);
    $created = false;

    if (!$post_id && $create_if_missing) {
        // Create minimal post
        $post_type = $s['post_type'] ?? 'job_listing';
        $status = $s['create_post_status'] ?? 'draft';
        $title = (string)($fields['post_title'] ?? ($payload['post_title'] ?? 'New Provider Profile'));

        $postarr = [
            'post_type' => $post_type,
            'post_status' => $status,
            'post_title' => wp_strip_all_tags($title),
        ];
        if (!empty($slug_in)) {
            $postarr['post_name'] = sanitize_title($slug_in);
        }

        // Mark this request as an inbound SF→WP create so outbound routing can choose the right Zap.
        $GLOBALS['fsahsa_sync_inbound_context'] = [
            'action' => 'sf_to_wp_create',
            'source' => $source,
            'event_id' => $event_id,
            'ref' => $ref,
        ];

        $new_id = wp_insert_post($postarr, true);
        if (is_wp_error($new_id) || !$new_id) {
            $msg = is_wp_error($new_id) ? $new_id->get_error_message() : 'wp_insert_post_failed';
            fsahsa_sync_debug_log('500', 'Create failed (v2).', ['message'=>$msg], $ref);
            return new WP_REST_Response(['ok'=>false,'error'=>'create_failed','message'=>$msg,'ref'=>$ref], 500);
        }
        $post_id = (int)$new_id;
        $created = true;
    }

    if (!$post_id) {
        fsahsa_sync_debug_log('404', 'No matching post (v2).', ['sf_id'=>$sf_id,'post_id'=>$post_id_in,'slug'=>$slug_in,'create_if_missing'=>$create_if_missing], $ref);
        return new WP_REST_Response([
            'ok' => false,
            'linked' => false,
            'created' => false,
            'post_id' => null,
            'sf_id' => $sf_id,
            'note' => 'no_matching_post',
            'ref' => $ref,
        ], 404);
    }

    // Replay guard (optional)
    $incoming_hash = fsahsa_sync_v2_payload_hash($payload);
    $last_hash = (string)get_post_meta($post_id, '_fsahsa_last_sync_hash', true);
    $last_source = (string)get_post_meta($post_id, '_fsahsa_last_sync_source', true);
    $last_event = (string)get_post_meta($post_id, '_fsahsa_last_sync_event_id', true);

    if (!empty($event_id) && $event_id === $last_event) {
        fsahsa_sync_debug_log('200', 'Skipped (same event_id, v2).', ['post_id'=>$post_id,'event_id'=>$event_id], $ref);
        return new WP_REST_Response([
            'ok'=>true,'linked'=>true,'created'=>$created,'skipped'=>true,'reason'=>'duplicate_event_id',
            'post_id'=>$post_id,'slug'=>get_post_field('post_name',$post_id),'link'=>get_permalink($post_id),'ref'=>$ref
        ], 200);
    }
    if ($incoming_hash === $last_hash && $source === $last_source) {
        fsahsa_sync_debug_log('200', 'Skipped (same payload hash + source, v2).', ['post_id'=>$post_id,'source'=>$source], $ref);
        return new WP_REST_Response([
            'ok'=>true,'linked'=>true,'created'=>$created,'skipped'=>true,'reason'=>'duplicate_payload',
            'post_id'=>$post_id,'slug'=>get_post_field('post_name',$post_id),'link'=>get_permalink($post_id),'ref'=>$ref
        ], 200);
    }

    // Apply post field updates
    // Mark this request as an inbound SF→WP update (even if the payload only updates meta).
    if (empty($created)) {
        $GLOBALS['fsahsa_sync_inbound_context'] = [
            'action' => 'sf_to_wp_update',
            'source' => $source,
            'event_id' => $event_id,
            'ref' => $ref,
        ];
    }
    $post_update = ['ID' => $post_id];
    if (isset($fields['post_title'])) $post_update['post_title'] = wp_strip_all_tags((string)$fields['post_title']);
    if (isset($fields['post_content'])) $post_update['post_content'] = (string)$fields['post_content'];
    if (isset($fields['post_excerpt'])) $post_update['post_excerpt'] = (string)$fields['post_excerpt'];
    if (!empty($slug_in)) $post_update['post_name'] = sanitize_title($slug_in);
    if (count($post_update) > 1) {
        $res = wp_update_post($post_update, true);
        if (is_wp_error($res)) {
            fsahsa_sync_debug_log('500', 'wp_update_post failed (v2).', ['message'=>$res->get_error_message()], $ref);
            return new WP_REST_Response(['ok'=>false,'error'=>'update_failed','message'=>$res->get_error_message(),'ref'=>$ref], 500);
        }
    }

    // Always stamp Salesforce id in both canonical + UI key; delete legacy
    if (!empty($sf_id)) {
        $canonical_key = $s['sf_id_meta_key'] ?? 'sf_id';
        update_post_meta($post_id, $canonical_key, $sf_id);
        update_post_meta($post_id, 'sfdc_provider_profile_id', $sf_id);
        delete_post_meta($post_id, '_sf_provider_profile_id');
    }

    // Gather meta bag (payload.meta + fields.meta)
    $meta = [];
    if (isset($payload['meta']) && is_array($payload['meta'])) $meta = array_merge($meta, $payload['meta']);
    if (isset($fields['meta']) && is_array($fields['meta'])) $meta = array_merge($meta, $fields['meta']);

    // Taxonomies (v2): you can pass taxonomies either as payload.taxonomies or fields.taxonomies
    // Example:
    // "taxonomies": { "job_listing_category": ["acupuncture","massage"], "region": "dallas" }
    // Internally we normalize these into meta keys like: "taxonomy:job_listing_category" => [...]
    if (isset($payload['taxonomies']) && is_array($payload['taxonomies'])) {
        foreach ($payload['taxonomies'] as $tax => $terms) {
            if (!is_string($tax) || $tax === '') continue;
            $meta['taxonomy:'.$tax] = $terms;
        }
    }
    if (isset($fields['taxonomies']) && is_array($fields['taxonomies'])) {
        foreach ($fields['taxonomies'] as $tax => $terms) {
            if (!is_string($tax) || $tax === '') continue;
            $meta['taxonomy:'.$tax] = $terms;
        }
    }


    // Optionally write other fields as meta (when you want dead-simple payloads)
    if (!empty($payload['write_fields_as_meta']) && is_array($fields)) {
        foreach ($fields as $k => $v) {
            if ($k === 'meta') continue;
            if (in_array($k, ['post_title','post_content','post_excerpt'], true)) continue;
            if (!array_key_exists($k, $meta)) $meta[$k] = $v;
        }
    }

    // Write meta exactly
    $meta_written = fsahsa_sync_v2_write_meta_exact($post_id, $meta);

    // Location sync: if _job_location was in the meta bag with a real value,
    // call the geocoder directly now that all meta has been written.
    // We do this here (not just via updated_post_meta hook) because the hook
    // can miss when empty strings arrive first and suppress the trigger.
    $location_synced = false;
    $address_raw = $meta['_job_location'] ?? '';
    if ( is_array( $address_raw ) ) $address_raw = reset( $address_raw );
    $address_value = trim( (string) $address_raw );
    if ( $address_value && function_exists( 'fsahsa_sync_trigger_location_sync' ) ) {
        fsahsa_sync_trigger_location_sync( $post_id, $address_value );
        $location_synced = true;
    }

    // Listing type (MyListing)
    // IMPORTANT (your MyListing metabox): the admin dropdown uses SLUG option values.
    // So we store the slug in `_case27_listing_type` for the UI to reflect it.
    // We also store the resolved Listing Type post ID in `_case27_listing_type_id`.
    // We accept a listing type slug OR numeric ID, attempt to set it, and report whether it stuck.
    $listing_type_set = false;
    $listing_type_post_id = 0;
    if (!empty($listing_type)) {
        if (function_exists('fsahsa_sync_set_listing_type_by_slug')) {
            $listing_type_set = (bool) fsahsa_sync_set_listing_type_by_slug($post_id, $listing_type);
            $listing_type_post_id = (int) get_post_meta($post_id, '_case27_listing_type_id', true);
            if ($listing_type_set) {
                $meta_written[] = '_case27_listing_type';
                if ($listing_type_post_id > 0) {
                    $meta_written[] = '_case27_listing_type_id';
                }
            }
        } else {
            // Fallback: store what we received so it can be inspected.
            update_post_meta($post_id, 'listing_type', $listing_type);
            $meta_written[] = 'listing_type (fallback meta)';
        }
    }

    // Sync markers (inbound from Salesforce)
    // Use WordPress site timezone for the timestamp so you can control local time
    // via Settings -> General -> Timezone.
    $synced_by = ($source === 'salesforce') ? 'Salesforce' : (string)$source;
    update_post_meta($post_id, '_fsahsa_last_synced_by', $synced_by);
    update_post_meta($post_id, '_fsahsa_last_synced_at', function_exists('current_time') ? current_time('c') : gmdate('c'));
    update_post_meta($post_id, '_fsahsa_last_sync_hash', $incoming_hash);
    update_post_meta($post_id, '_fsahsa_last_sync_event_id', $event_id);
    update_post_meta($post_id, '_fsahsa_last_sync_updated_at', $updated_at);
    update_post_meta($post_id, '_fsahsa_last_sync_source', $source);

    clean_post_cache($post_id);
    wp_cache_delete($post_id, 'post_meta');

    $resp = [
        'ok'=>true,
        'linked'=>true,
        'created'=>$created,
        'post_id'=>$post_id,
        'slug'=>get_post_field('post_name',$post_id),
        'link'=>get_permalink($post_id),
        'plugin_version'=>defined('FSAHSA_SYNC_VERSION') ? FSAHSA_SYNC_VERSION : '',
        'sf_id'=>$sf_id,
        'listing_type'=>$listing_type,
        'listing_type_derived'=>$listing_type_derived,
        'listing_type_set'=>$listing_type_set,
        'listing_type_post_id'=>$listing_type_post_id,
        'record_type_id'=>$record_type_id,
        'location_synced'=>$location_synced,
        'meta_written'=>$meta_written,
        'ref'=>$ref,
    ];

    fsahsa_sync_debug_log('200', $created ? 'Upsert created (v2)' : 'Upsert updated (v2)', [
        'post_id'=>$post_id,
        'created'=>$created,
        'sf_id'=>$sf_id,
        'slug'=>$resp['slug'],
        'meta_written_count'=>count($meta_written),
        'event_id'=>$event_id,
        'source'=>$source,
    ], $ref);

    // Clear inbound context so unrelated saves in the same request (rare) aren't mis-routed.
    unset($GLOBALS['fsahsa_sync_inbound_context']);
    return new WP_REST_Response($resp, 200);
}
