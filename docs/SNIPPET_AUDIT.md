# WPCode snippet audit and migration

This is Phase 3 of the project. Goal: move every active WPCode snippet out of the database and into tracked code. Works against the staging environment first, then live.

## Why this matters

- WPCode snippets are PHP stored in the WordPress database, executed on hooks
- They are invisible to Git, invisible to diffs, and disappear if the plugin is deactivated
- A single plugin conflict, update issue, or accidental deactivation breaks all of them at once
- They cannot be code-reviewed, version-controlled, or deployed atomically
- Migrating them to files puts business logic where it belongs and makes it recoverable

## Process overview

1. Export all snippets from WPCode as a point-in-time record
2. Catalog every snippet into the audit spreadsheet (`docs/SNIPPET_AUDIT.csv`)
3. Assign each snippet a destination (child theme or one of the four plugins)
4. Migrate in small batches (5–10 related snippets per branch)
5. Deactivate migrated snippets in WPCode (don't delete yet)
6. Verify behavior identical in staging
7. Deploy to production, deactivate in live WPCode, verify
8. After 2 weeks stable: delete the deactivated snippets and remove WPCode plugin

## Step 1: Export

On **Kinsta staging** (not production, not local — staging is your source of truth here):

1. WP admin → **Code Snippets (WPCode) → Tools → Export**
2. Check "Include deactivated snippets"? **No** — we only care about what's actually running
3. Export all active snippets. Save the file.
4. Commit the file to this repo at `docs/wpcode-export-YYYY-MM-DD.json` (replace date)
5. This is evidence, not source. Don't re-import it anywhere.

```bash
git checkout -b docs/wpcode-baseline-export
cp ~/Downloads/wpcode-export.json docs/wpcode-export-$(date +%Y-%m-%d).json
git add docs/
git commit -m "Baseline WPCode export for migration"
git push -u origin docs/wpcode-baseline-export
```

Merge to main right away — this is read-only documentation.

## Step 2: Catalog

Open `docs/SNIPPET_AUDIT.csv` in Google Sheets or Excel. One row per snippet. Fill in every column.

Column definitions:

| Column | Description |
|---|---|
| `id` | Internal WPCode snippet ID (visible in the URL when editing) |
| `title` | Snippet title in WPCode |
| `hook` | WordPress hook(s) it uses, e.g. `init`, `woocommerce_before_account_navigation` |
| `priority` | Hook priority if specified, default 10 |
| `description` | One-sentence summary of what it does |
| `scope` | `theme` / `woocommerce` / `mylisting` / `salesforce` / `site-wide` / `admin` |
| `depends_on` | User role, post type, URL path, or other trigger conditions |
| `destination` | Target file — see decision matrix below |
| `notes` | Anything unclear, questionable, or needing review |
| `migrated_local` | ✓ when migrated in DevKinsta |
| `migrated_staging` | ✓ when deployed to Kinsta staging and snippet deactivated there |
| `migrated_prod` | ✓ when deployed to Kinsta live and snippet deactivated there |
| `deleted` | ✓ when snippet permanently deleted from WPCode |

## Step 3: Destination decision matrix

For each snippet, the destination is determined by scope + purpose. Use this table:

| Pattern | Destination |
|---|---|
| Enqueues a stylesheet or script | Child theme `functions.php` |
| Adds/removes body classes, post classes | Child theme `functions.php` |
| Template tag / shortcode for display only | Child theme `functions.php` or new inc file |
| Modifies WC or MyListing nav menus | `fsa-account-menu-manager` |
| Adds/modifies fields on provider listings | `fsa-identity-card` |
| Changes pricing display, subscriber perks, discount logic | `fsa-discount-panel` |
| Salesforce sync, WP All Import hooks, data pipeline | `fsahsa-sync` |
| Login redirects, role creation/modification | New plugin: `fsa-roles-auth` |
| Email templates, notification logic | New plugin: `fsa-notifications` |
| Admin UI customizations (admin menus, columns) | New plugin: `fsa-admin-ux` |
| Security tweaks (disable file editor, hide version) | New plugin: `fsa-security` |
| Fragment of something that doesn't clearly fit | `notes` column: "REVIEW" — ask before assigning |

**Core principle**: presentation → theme. Business logic → plugin. When unsure, plugin.

Don't create a dumping-ground "fsa-misc" plugin. If a snippet doesn't fit an existing plugin, either it belongs in a new focused plugin or it indicates a gap in your architecture worth discussing.

## Step 4: Migrate in batches

Group snippets that share a destination and work on them together. Example batches:

- Batch 1: all nav/menu snippets → `fsa-account-menu-manager` v1.3.0
- Batch 2: all discount/pricing snippets → `fsa-discount-panel` v1.3.0
- Batch 3: all listing display snippets → `fsa-identity-card` v1.3.0
- Batch 4: enqueues and theme tweaks → child theme
- Batch 5: Salesforce/import hooks → `fsahsa-sync` v1.0.0 (or next version)

Per batch:

```bash
git checkout main
git pull
git checkout -b snippets/migrate-batch-1-account-nav
```

For each snippet in the batch:

1. Copy the PHP code into the target file
2. Wrap it with a header comment:

```php
/**
 * [Snippet Title from WPCode]
 *
 * Migrated from WPCode snippet ID #123 on 2026-04-21.
 * Original description: [description from audit row]
 */
add_action( 'hook_name', function() {
    // snippet body
}, $priority );
```

3. Bump the plugin's version in its main file header
4. Test in DevKinsta: **deactivate the corresponding snippet in WPCode locally**, clear cache, verify the behavior still works (because the file-based version is now handling it)
5. If it works, move to the next snippet in the batch
6. If it breaks: reactivate the snippet, figure out what's different, fix the file-based version, try again

Commit frequently — one commit per snippet or per tight group:

```bash
git add wp-content/plugins/fsa-account-menu-manager/
git commit -m "Migrate: hide admin bar for subscribers (WPCode #123)"
```

When the batch is done locally:

```bash
git push -u origin snippets/migrate-batch-1-account-nav
```

## Step 5: Staging verification

On GitHub: open a PR from the batch branch into `staging`. Merge. Actions deploys to Kinsta staging.

On Kinsta staging WP admin:
1. Verify the plugin version bumped
2. Deactivate each migrated snippet in WPCode (don't delete)
3. Clear Kinsta cache
4. Walk through every page type affected by the snippets — does behavior still match?
5. Update `migrated_staging` column to ✓ for each verified snippet

If something breaks: reactivate the snippet in WPCode. The file-based version stays in place but doesn't conflict (WPCode loads first on the hook generally; worst case you get duplicate execution, which is a fix-forward problem). Debug, patch, redeploy.

Let the batch sit on staging for **at least 24 hours** before production. Gives time for edge cases to surface — scheduled tasks, cron jobs, subscription renewals, etc.

## Step 6: Production deployment

PR from `staging` into `main`. This triggers the production workflow with the approval gate. Click approve when ready.

After deployment succeeds:
1. Kinsta live WP admin → deactivate the same snippets in WPCode
2. Clear cache
3. Spot-check the same pages you checked on staging
4. Update `migrated_prod` column to ✓

## Step 7: Cleanup (2 weeks later)

After two weeks with zero regressions:

1. On production WPCode: delete all deactivated migrated snippets
2. Update `deleted` column to ✓
3. Export the remaining snippets again (should be empty or only things not migrated) and commit as `docs/wpcode-final-YYYY-MM-DD.json`
4. Uninstall the WPCode plugin

Don't skip the 2-week buffer. Deletion is irreversible. Deactivation is a 10-second undo.

## Red flags during audit

Stop and ask before migrating if you encounter:

- **Snippets that modify wp-config constants** — these need to move to actual `wp-config.php` on each environment, not into plugin code
- **Snippets with hardcoded URLs, IDs, or paths** — these need refactoring to use WordPress functions (`home_url()`, `get_option()`) before migration
- **Snippets with database write operations inside loops or uncached queries** — these are bug candidates, refactor during migration
- **Snippets that override WooCommerce or MyListing core functions via `remove_action`/`add_action`** — these are fragile; document heavily during migration and consider whether a template override is cleaner
- **Snippets that reference other snippets by ID** — means they're coupled; migrate the whole chain in one batch
- **Snippets with no clear purpose** — may be dead code. Before migration, comment it out on staging for 48h. If nothing breaks, don't migrate it; just delete it.

## Tracking progress

Keep `docs/SNIPPET_AUDIT.csv` updated in the same PRs as your migration commits. The CSV becomes a living audit trail. When the project is done, you'll have a full record of what moved where.
