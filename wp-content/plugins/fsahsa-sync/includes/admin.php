<?php
if (!defined('ABSPATH')) { exit; }

add_action('admin_menu', function() {
    add_options_page(
        'FSAHSA Sync',
        'FSAHSA Sync',
        'manage_options',
        'fsahsa-sync',
        'fsahsa_sync_render_settings_page'
    );
});

add_action('admin_init', function() {
    register_setting('fsahsa_sync_group', FSAHSA_SYNC_OPTION, [
        'type' => 'array',
        'sanitize_callback' => function($value) {
            // We'll sanitize/merge ourselves to avoid fatals.
            return fsahsa_sync_update_settings(is_array($value) ? $value : []);
        },
        'default' => fsahsa_sync_default_settings(),
    ]);
});

function fsahsa_sync_render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $s = fsahsa_sync_get_settings();
    ?>
    <div class="wrap">
      <h1>FSAHSA Sync</h1>
      <p>Configure inbound authentication, outbound Zapier webhook, and field mappings between WordPress job listings and Salesforce Provider_Profile__c.</p>

      <form method="post" action="options.php">
        <?php settings_fields('fsahsa_sync_group'); ?>

        <h2>Security</h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="fsahsa_secret">Shared Secret</label></th>
            <td>
              <input id="fsahsa_secret" type="text" class="regular-text"
                     name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[secret]"
                     value="<?php echo esc_attr($s['secret']); ?>" />
              <p class="description">Send this in Zapier as header <code>X-FSAHSA-SYNC-SECRET</code>.</p>
            </td>
          </tr>

          <tr>
            <th scope="row">Inbound Endpoints</th>
            <td>
              <p class="description">Use these URLs in Zapier Webhooks (POST). Both require the <code>X-FSAHSA-SYNC-SECRET</code> header.</p>
              <p><strong>v2 (recommended, simple payload):</strong> <code><?php echo esc_html(rest_url('fsahsa-sync/v2/upsert-profile')); ?></code></p>
              <p><strong>v1 (legacy, mapping-driven):</strong> <code><?php echo esc_html(rest_url('fsahsa-sync/v1/provider-profile')); ?></code></p>
            </td>
          </tr>
          <tr>
            <th scope="row">Debug logging</th>
            <td>
              <label>
                <input type="checkbox"
                  name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[debug_logging]"
                  value="1" <?php checked(!empty($s['debug_logging'])); ?> />
                Enable request logging (stores last ~<?php echo intval($s['debug_log_limit'] ?? 50); ?> requests)
              </label>
              <p class="description">When enabled, the plugin stores a small ring buffer of recent inbound requests so we can see exactly what arrived (without guessing what Zapier did).</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="fsahsa_sf_meta">Salesforce Id Meta Key</label></th>
            <td>
              <input id="fsahsa_sf_meta" type="text" class="regular-text"
                     name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[sf_id_meta_key]"
                     value="<?php echo esc_attr($s['sf_id_meta_key']); ?>" />
              <p class="description">WP post meta key where the Salesforce record id is stored. Default: <code>sf_id</code>. Keep this consistent with your Zapier mapping.</p>
            </td>
          </tr>
        </table>

        <h2>Outbound (WP → Zapier)</h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="fsahsa_webhook">Zapier Webhook URL</label></th>
            <td>
              <input id="fsahsa_webhook" type="url" class="regular-text"
                     name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[zapier_webhook_url]"
                     value="<?php echo esc_attr($s['zapier_webhook_url']); ?>" />
              <p class="description">Default webhook used when a job listing is published/updated (based on mapping directions).</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="fsahsa_geocoding_key">Server Geocoding API Key</label></th>
            <td>
              <input id="fsahsa_geocoding_key" type="text" class="regular-text"
                     name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[geocoding_api_key]"
                     value="<?php echo esc_attr($s['geocoding_api_key'] ?? ''); ?>" />
              <p class="description">
                A <strong>separate</strong> Google API key restricted to your server IP (<code>147.224.165.202</code>)
                with the Geocoding API enabled. This is used for server-side geocoding during Zapier/REST imports
                and must be different from your frontend map key. Leave blank to fall back to MyListing's key.
              </p>
            </td>
          </tr>

          <tr>
            <th scope="row">Outbound routing</th>
            <td>
              <select name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[zapier_webhook_route_mode]">
                <?php foreach ([
                  'default' => 'Use default webhook only',
                  'listing_type_slug' => 'Route by MyListing Listing Type (slug)',
                  'post_type' => 'Route by WP post type',
                  'sync_action' => 'Route by sync action (create/update, SF→WP vs WP→SF)',
                  'sync_action_plus' => 'Route by sync action + listing type/status (WP decides)',
                ] as $k => $lbl): ?>
                  <option value="<?php echo esc_attr($k); ?>" <?php selected($s['zapier_webhook_route_mode'] ?? 'default', $k); ?>><?php echo esc_html($lbl); ?></option>
                <?php endforeach; ?>
              </select>
              <p class="description">If routing is enabled, the plugin will look up a matching webhook URL from the table below. If no match is found, it falls back to the default webhook.</p>
            </td>
          </tr>
        </table>

        <h3>Outbound webhook routes (optional)</h3>
        <p class="description" style="max-width: 980px;">Add one or more <strong>Route Keys</strong> and their Zapier webhooks. Route keys are compared as slugs (lowercase, alphanumeric + underscores). Examples:<br/>
        <strong>Listing Type routing:</strong> <code>chiropractor</code>, <code>massage</code>...<br/>
        <strong>Sync action routing:</strong> <code>sf_to_wp_create</code>, <code>sf_to_wp_update</code>, <code>wp_to_sf_create</code>, <code>wp_to_sf_update</code>.<br/>
        <strong>Sync action + context routing:</strong> <code>wp_to_sf_update__provider</code>, <code>wp_to_sf_update__publish</code>, <code>sf_to_wp_create__unclaimed</code> (format: <code>{sync_action}__{listing_type_slug|post_status}</code>).</p>

        <?php $whm = $s['zapier_webhook_map'] ?? []; if (!is_array($whm)) $whm = []; ?>
        <table class="widefat striped" id="fsahsa_whm_table" style="max-width: 980px;">
          <thead>
            <tr>
              <th style="width:35%;">Route Key</th>
              <th>Zapier Webhook URL</th>
              <th style="width:1%;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($whm as $key => $url): ?>
              <tr>
                <td><input type="text" class="regular-text" name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[zapier_webhook_map_key][]" value="<?php echo esc_attr($key); ?>" /></td>
                <td><input type="url" class="large-text" name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[zapier_webhook_map_url][]" value="<?php echo esc_attr($url); ?>" /></td>
                <td><button type="button" class="button fsahsa_whm_del">Remove</button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p><button type="button" class="button" id="fsahsa_add_whm">Add Webhook Route</button></p>

        <h2>Inbound (SF → WP)</h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Create listing if missing</th>
            <td>
              <label>
                <input type="checkbox"
                  name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[create_if_missing]"
                  value="1" <?php checked(!empty($s['create_if_missing'])); ?> />
                Enable creating a new <code><?php echo esc_html($s['post_type']); ?></code> post when Salesforce sends an update for a record that doesn't match any existing listing.
              </label>
              <p class="description">If enabled, the plugin will attempt to match by: SF Id meta → <code>Post_ID__c</code> → <code>Slug__c</code>, then create.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">New listing status</th>
            <td>
              <select name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[create_post_status]">
                <?php foreach (['draft'=>'Draft','pending'=>'Pending Review','publish'=>'Publish','private'=>'Private'] as $k=>$lbl): ?>
                  <option value="<?php echo esc_attr($k); ?>" <?php selected($s['create_post_status'] ?? 'draft', $k); ?>><?php echo esc_html($lbl); ?></option>
                <?php endforeach; ?>
              </select>
              <p class="description">Recommended: <strong>Draft</strong> until you trust your mapping.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Allow direct meta writes</th>
            <td>
              <label>
                <input type="checkbox"
                  name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[allow_direct_meta_keys]"
                  value="1" <?php checked(!empty($s['allow_direct_meta_keys'])); ?> />
                Allow inbound requests to write exact WP meta keys via a <code>meta</code> object (recommended for Zapier simplicity).
              </label>
              <p class="description">Example payload: <code>{ "sf_id": "...", "wp": {"post_id": 123}, "meta": {"_job_phone":"...", "_job_website":"..."} }</code></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="fsahsa_extra_meta">Extra meta keys</label></th>
            <td>
              <textarea id="fsahsa_extra_meta" class="large-text code" rows="4"
                name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[extra_meta_keys]"><?php echo esc_textarea($s['extra_meta_keys'] ?? ''); ?></textarea>
              <p class="description">Meta keys to always show in the WP Field picker, even before they exist on any post. Comma or newline separated. Example: <code>address_mapping</code></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="fsahsa_extra_payload_meta">Extra outbound payload meta</label></th>
            <td>
              <textarea id="fsahsa_extra_payload_meta" class="large-text code" rows="4"
                name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[extra_payload_meta_keys]"><?php echo esc_textarea($s['extra_payload_meta_keys'] ?? ''); ?></textarea>
              <p class="description">One meta key per line. These keys will be automatically included in outbound webhooks under <code>wp.meta.&lt;key&gt;</code> (and also mirrored to <code>wp.&lt;key&gt;</code> for convenience). This prevents plugin updates whenever you add new WP fields (e.g. Telehealth, Location).</p>
            </td>
          </tr>
        </table>

        <h2>Field Mapping</h2>
        <p>Each row maps a WordPress field to a Salesforce API field. Use <strong>Direction</strong> to control one-way or bidirectional syncing.</p>

        <div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #2271b1;padding:12px;margin:12px 0;max-width:980px;">
          <strong>Transforms cheatsheet</strong>
          <ul style="margin:8px 0 0 18px;">
            <li><code>trim</code> remove leading/trailing spaces</li>
            <li><code>lower</code> lowercase (emails)</li>
            <li><code>upper</code> uppercase</li>
            <li><code>join_comma</code> join array values with commas</li>
            <li><code>join_semicolon</code> join array values with semicolons (Salesforce Multi-Select Picklists)</li>
            <li><code>join(;</code><code>)</code> custom join delimiter (examples: <code>join(;)</code>, <code>join(|)</code>, <code>join(\n)</code>)</li>
            <li><code>record_type_id_from_listing_type</code> map listing type slug → RecordTypeId using <code>record_type_map</code> in settings.php</li>
          </ul>
          <p style="margin:8px 0 0;" class="description">Transforms are applied left-to-right, comma-separated. Example: <code>trim,lower</code></p>
        </div>

        <?php fsahsa_sync_render_mapping_table($s); ?>

        <p>
          <button type="button" class="button" id="fsahsa_add_row">Add Mapping Row</button>
        </p>

        
        <hr style="margin:24px 0;" />
        <h2>Record Type Mapper</h2>
        <p>Map MyListing <strong>Listing Type slug</strong> to a Salesforce <strong>RecordTypeId</strong>.
        You can use the transform <code>record_type_id_from_listing_type</code> in your mapping rows to populate a RecordTypeId field in Salesforce.
        For SF → WP, the v2 endpoint can also reverse-map a provided RecordTypeId back into a Listing Type slug.</p>

        <div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #46b450;padding:12px;margin:12px 0;max-width:980px;">
          <strong>Troubleshooting checklist</strong>
          <ol style="margin:8px 0 0 18px;">
            <li>Make sure you're using the <strong>Listing Type slug</strong> (not the display name). In WordPress, that's the Listing Type post <code>post_name</code>.</li>
            <li>Make sure the RecordTypeId you paste matches the exact Salesforce record type on your object (15 or 18 chars).</li>
            <li>If you're doing SF → WP using the <strong>v2 endpoint</strong>, send either <code>listing_type</code> <em>or</em> <code>RecordTypeId</code>/<code>record_type_id</code> in the payload. If both are missing, the listing type can't be set.</li>
          </ol>
        </div>

        <?php
          // Helpful reference: list MyListing Listing Types found in this WP install.
          $listing_types = get_posts([
            'post_type' => 'case27_listing_type',
            'post_status' => 'publish',
            'numberposts' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
          ]);
        ?>
        <details style="max-width:980px; margin: 0 0 14px 0;">
          <summary><strong>Show detected MyListing Listing Types (slugs)</strong></summary>
          <div style="background:#fff;border:1px solid #ddd;padding:10px;margin-top:8px;">
            <?php if (empty($listing_types)): ?>
              <p><em>No <code>case27_listing_type</code> posts found. If MyListing is installed, double-check the post type name, or create at least one Listing Type.</em></p>
            <?php else: ?>
              <table class="widefat striped" style="max-width: 900px;">
                <thead><tr><th>Title</th><th>Slug</th><th>ID</th></tr></thead>
                <tbody>
                  <?php foreach ($listing_types as $lt): ?>
                    <tr>
                      <td><?php echo esc_html($lt->post_title); ?></td>
                      <td><code><?php echo esc_html($lt->post_name); ?></code></td>
                      <td><?php echo intval($lt->ID); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </details>

        <?php $rtm = $s['record_type_map'] ?? []; if (!is_array($rtm)) $rtm = []; ?>

        <table class="widefat striped" id="fsahsa_rtm_table" style="max-width: 900px;">
          <thead>
            <tr>
              <th style="width:40%;">Listing Type Slug</th>
              <th>RecordTypeId</th>
              <th style="width:1%;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rtm as $slug => $rtid): ?>
              <tr>
                <td><input type="text" class="regular-text" name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[record_type_map_slug][]" value="<?php echo esc_attr($slug); ?>" /></td>
                <td><input type="text" class="regular-text" name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[record_type_map_id][]" value="<?php echo esc_attr($rtid); ?>" /></td>
                <td><button type="button" class="button fsahsa_rtm_del">Remove</button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p><button type="button" class="button" id="fsahsa_add_rtm">Add Record Type Mapping</button></p>
<hr/>
        <h2>Recent Requests (Inbound + Outbound)</h2>
        <p class="description" style="max-width: 980px;">Only shown when Debug logging is enabled. This shows inbound (SF→WP) and outbound (WP→Zapier) payloads to diagnose missing fields and payload shape issues.</p>
        <?php
          if (!empty($s['debug_logging'])) {
            $logs = get_option(FSAHSA_SYNC_LOG_OPTION, []);
            if (!is_array($logs)) $logs = [];
            if (empty($logs)) {
              echo '<p><em>No requests logged yet.</em></p>';
            } else {
              echo '<div style="max-width: 980px;">';
              foreach ($logs as $entry) {
                $ts = esc_html($entry['ts'] ?? '');
                $status = esc_html($entry['status'] ?? '');
                $msg = esc_html($entry['msg'] ?? '');
                $ref = esc_html($entry['ref'] ?? '');
                echo '<details style="margin: 0 0 10px 0; padding: 10px; border: 1px solid #ddd; background: #fff;">';
                echo '<summary><strong>'.$ts.'</strong> — '.$status.' — '.$msg.' <code style="opacity:.7">'.$ref.'</code></summary>';
                echo '<pre style="white-space:pre-wrap; word-break:break-word;">'.esc_html(json_encode($entry['data'] ?? new stdClass(), JSON_PRETTY_PRINT)).'</pre>';
                echo '</details>';
              }
              echo '</div>';
            }
          } else {
            echo '<p><em>Enable Debug logging above to record requests here.</em></p>';
          }
        ?>

<?php submit_button(); ?>
      </form>
    </div>

    <script>
    (function(){
      const addBtn = document.getElementById('fsahsa_add_row');
      if(!addBtn) return;
      addBtn.addEventListener('click', function(){
        const tbody = document.querySelector('#fsahsa_map_table tbody');
        const idx = tbody.querySelectorAll('tr').length;
        const tpl = document.getElementById('fsahsa_row_template').innerHTML.replaceAll('__INDEX__', idx);
        const tr = document.createElement('tr');
        tr.innerHTML = tpl;
        tbody.appendChild(tr);
      });
      document.addEventListener('click', function(e){
        if(e.target && e.target.classList.contains('fsahsa_remove_row')){
          e.preventDefault();
          const tr = e.target.closest('tr');
          if(tr) tr.remove();
        }
      });
    })();

      // Record Type Mapper table
      const addRtm = document.getElementById('fsahsa_add_rtm');
      if(addRtm){
        addRtm.addEventListener('click', function(){
          const tbody = document.querySelector('#fsahsa_rtm_table tbody');
          const tr = document.createElement('tr');
          tr.innerHTML = `<td><input type="text" class="regular-text" name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[record_type_map_slug][]" value="" /></td>
                          <td><input type="text" class="regular-text" name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[record_type_map_id][]" value="" /></td>
                          <td><button type="button" class="button fsahsa_rtm_del">Remove</button></td>`;
          tbody.appendChild(tr);
        });
      }
      document.addEventListener('click', function(e){
        if(e.target && e.target.classList.contains('fsahsa_rtm_del')){
          e.preventDefault();
          const tr = e.target.closest('tr');
          if(tr) tr.remove();
        }
      });

      // Outbound Webhook Routes table
      const addWhm = document.getElementById('fsahsa_add_whm');
      if(addWhm){
        addWhm.addEventListener('click', function(){
          const tbody = document.querySelector('#fsahsa_whm_table tbody');
          const tr = document.createElement('tr');
          tr.innerHTML = `<td><input type="text" class="regular-text" name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[zapier_webhook_map_key][]" value="" /></td>
                          <td><input type="url" class="large-text" name="<?php echo esc_attr(FSAHSA_SYNC_OPTION); ?>[zapier_webhook_map_url][]" value="" /></td>
                          <td><button type="button" class="button fsahsa_whm_del">Remove</button></td>`;
          tbody.appendChild(tr);
        });
      }
      document.addEventListener('click', function(e){
        if(e.target && e.target.classList.contains('fsahsa_whm_del')){
          e.preventDefault();
          const tr = e.target.closest('tr');
          if(tr) tr.remove();
        }
      });

    </script>
    <?php
}

function fsahsa_sync_get_wp_field_options() {
    global $wpdb;
    $s = fsahsa_sync_get_settings();
    $post_type = $s['post_type'] ?? 'job_listing';
    $opts = [
      'post_id' => 'Post ID',
      'post_title' => 'Post Title',
      'post_status' => 'Post Status',
      'permalink' => 'Permalink',
      'slug' => 'Slug',
      'modified_gmt' => 'Modified GMT',
      'listing_type_slug' => 'MyListing Listing Type (slug)',
      'listing_type_slugs' => 'Listing Type (slugs)',
    ];

    // Friendly names for common job listing meta keys (best-effort).
    $friendly_meta = [
        '_job_phone' => 'Phone',
        '_job_website' => 'Website',
        '_job_email' => 'Email',
        '_job_location' => 'Location',
        '_job_address' => 'Address',
        '_job_company_name' => 'Company Name',
        '_job_tagline' => 'Tagline',
        '_job_cover' => 'Cover Image',
        'address_mapping' => 'Address Mapping (custom)',
    ];

    // Discover meta keys from existing listings (includes underscore keys used by MyListing).
    try {
        $sql = $wpdb->prepare(
            "SELECT DISTINCT pm.meta_key
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = %s
             LIMIT 400",
            $post_type
        );
        $keys = $wpdb->get_col($sql);
        if (is_array($keys)) {
            foreach ($keys as $k) {
                // Hide some noisy internal keys.
                if (!$k) continue;
                if (in_array($k, ['_edit_lock','_edit_last','_thumbnail_id','_wp_old_slug'], true)) continue;
                if (str_starts_with($k, '_wp_')) continue;
                if (str_starts_with($k, '_oembed')) continue;
                $label = 'Meta: ' . $k;
                if (isset($friendly_meta[$k])) {
                    $label .= ' | ' . $friendly_meta[$k];
                }
                $opts['meta:' . $k] = $label;
            }
        }
    } catch (Exception $e) { /* ignore */ }

    // Always include any user-specified extra meta keys (even if they don't exist yet on any post).
    $extra = $s['extra_meta_keys'] ?? '';
    if (is_string($extra) && trim($extra) !== '') {
        $lines = preg_split('/[\r\n,]+/', $extra);
        foreach ($lines as $line) {
            $k = trim($line);
            if ($k === '') continue;
            if (!preg_match('/^[A-Za-z0-9_:-]+$/', $k)) continue;
            if (!isset($opts['meta:' . $k])) {
                $label = 'Meta: ' . $k;
                if (isset($friendly_meta[$k])) { $label .= ' | ' . $friendly_meta[$k]; }
                $opts['meta:' . $k] = $label;
            }
        }
    }

    // Common taxonomies (best-effort)
    $taxes = get_object_taxonomies($post_type, 'objects');
    if (is_array($taxes)) {
        foreach ($taxes as $tax) {
            $opts['taxonomy:' . $tax->name] = 'Taxonomy: ' . $tax->label . ' (' . $tax->name . ')';
        }
    }

    // Explicit meta keys for custom UI fields (e.g., MyListing/ACF) that won't show up until populated.
    $opts['meta:salesforce_id'] = 'Meta: salesforce_id | Salesforce ID (UI Field)';
    $opts['meta:sfdc_provider_profile_id'] = 'Meta: sfdc_provider_profile_id | Salesforce Provider Profile Id (UI Field)';
    // Special: map SF id storage (show both the label and the actual meta key)
    $sf_meta = $s['sf_id_meta_key'] ?? 'sf_id';
    $opts['meta:' . $sf_meta] = 'Salesforce Record Id (meta: ' . $sf_meta . ')';
    // NOTE: legacy meta key is intentionally not exposed in the UI to prevent mapping mistakes.
    return $opts;
}

function fsahsa_sync_render_mapping_table($s) {
    $rows = $s['field_map'] ?? [];
    if (!is_array($rows)) $rows = [];
    $wp_opts = fsahsa_sync_get_wp_field_options();
    ?>

    <datalist id="fsahsa_wp_field_opts">
      <?php foreach ($wp_opts as $val => $label): ?>
        <option value="<?php echo esc_attr($val); ?>"></option>
      <?php endforeach; ?>
    </datalist>

    <table class="widefat striped" id="fsahsa_map_table">
      <thead>
        <tr>
          <th>WP Field</th>
          <th>Salesforce Field API Name</th>
          <th>Direction</th>
          <th>Transforms</th>
          <th>Conflict</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $row): ?>
        <tr>
          <?php fsahsa_sync_render_row_inputs($i, $row, $wp_opts); ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <p class="description" style="margin-top:10px;">
      Tip: For WordPress meta, you can type exact meta keys directly (e.g. <code>_job_phone</code>)
      or use the <code>meta:_job_phone</code> form.
    </p>

    <script type="text/template" id="fsahsa_row_template">
      <?php
        // Render empty row with placeholder index.
        ob_start();
        fsahsa_sync_render_row_inputs('__INDEX__', ['wp_field'=>'','sf_field'=>'','direction'=>'off','transform'=>'','conflict'=>'newer_wins'], $wp_opts);
        echo str_replace("
","", ob_get_clean());
      ?>
    </script>
    <?php
}

function fsahsa_sync_render_row_inputs($i, $row, $wp_opts) {
    $base = esc_attr(FSAHSA_SYNC_OPTION) . "[field_map][$i]";
    $wp_field = $row['wp_field'] ?? '';
    $sf_field = $row['sf_field'] ?? '';
    $direction = $row['direction'] ?? 'off';
    $transform = $row['transform'] ?? '';
    $conflict = $row['conflict'] ?? 'newer_wins';
    ?>
    <td>
      <input
        type="text"
        list="fsahsa_wp_field_opts"
        name="<?php echo $base; ?>[wp_field]"
        value="<?php echo esc_attr($wp_field); ?>"
        placeholder="e.g. post_title or meta:_job_phone or _job_phone"
      />
    </td>
    <td>
      <input type="text" class="regular-text" name="<?php echo $base; ?>[sf_field]" value="<?php echo esc_attr($sf_field); ?>" placeholder="e.g. Provider_Name__c" />
      <p class="description" style="margin:4px 0 0;">Use <code>sf_id</code> to map the Salesforce record id (recommended). Legacy accepted: <code>Id</code>.</p>
    </td>
    <td>
      <select name="<?php echo $base; ?>[direction]">
        <?php foreach (['off'=>'Off','wp_to_sf'=>'WP → SF','sf_to_wp'=>'SF → WP','bidir'=>'Bidirectional'] as $k=>$lbl): ?>
          <option value="<?php echo esc_attr($k); ?>" <?php selected($direction, $k); ?>><?php echo esc_html($lbl); ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td>
      <input type="text" class="regular-text" name="<?php echo $base; ?>[transform]" value="<?php echo esc_attr($transform); ?>" placeholder="trim,lower,join_comma" />
    </td>
    <td>
      <select name="<?php echo $base; ?>[conflict]">
        <?php foreach (['newer_wins'=>'Newer timestamp wins','sf_wins'=>'Salesforce wins','wp_wins'=>'WordPress wins'] as $k=>$lbl): ?>
          <option value="<?php echo esc_attr($k); ?>" <?php selected($conflict, $k); ?>><?php echo esc_html($lbl); ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><a href="#" class="button-link fsahsa_remove_row">Remove</a></td>
    <?php
}