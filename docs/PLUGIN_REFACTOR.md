# Plugin refactor guide (Phase 4)

Do not start this phase until the snippet audit (Phase 3) is complete. Refactoring a plugin while parallel logic still lives in WPCode snippets is how you create hard-to-debug ghost behavior.

## Order

Refactor easiest → riskiest:

1. **fsa-discount-panel** (smallest, least-coupled) — warm up on this one
2. **fsa-identity-card** (shortcode-heavy, low-risk)
3. **fsa-account-menu-manager** (touches WC nav, moderate risk)
4. **fsahsa-sync** (Salesforce pipeline, highest blast radius — last)

Each plugin gets its own branch, PR, staging verification, and production deploy. Do not batch them.

## Per-plugin process

### 1. Create the branch

```bash
git checkout main && git pull
git checkout -b refactor/fsa-discount-panel
```

### 2. Drive Claude Code through the audit

In your local DevKinsta repo, open Claude Code. Feed it the plugin directory and ask for an audit covering specifically:

**Correctness**
- Dead code paths, unreachable conditionals
- Duplicated logic (especially if snippets were just migrated in — you may have double-hooked the same thing)
- Incorrect hook priorities causing filter ordering bugs
- Missing `ABSPATH` guards at the top of files
- Hardcoded URLs, IDs, or paths that should use WP functions

**Security**
- Unescaped output (`echo` without `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`)
- Missing nonce verification on form handlers and AJAX endpoints
- Missing capability checks before destructive actions
- SQL without `$wpdb->prepare()`
- Direct `$_GET` / `$_POST` / `$_REQUEST` access without sanitization

**Performance**
- `WP_Query` or `get_posts` inside loops
- Unbounded queries (`posts_per_page => -1`)
- `get_post_meta` calls without caching in loops
- Expensive work on `init` or `admin_init` that should be on a later hook
- Autoloaded options that shouldn't be

**PHP 7.4 compatibility** (relevant for fsahsa-sync especially)
- No arrow functions short-form issues
- No typed class constants
- Null coalescing assignment (`??=`) — PHP 7.4 supports this, fine to use
- `match` expressions — **not** available, use `switch`
- Constructor property promotion — **not** available, use explicit assignment

**Structure**
- Single-file plugins that should be split
- Missing `uninstall.php` for cleanup
- Plugin header metadata accuracy
- Appropriate use of classes vs. procedural code

### 3. Refactor in small commits

Don't pile all audit findings into one giant commit. Pattern:

```bash
git commit -m "Add ABSPATH guard to all plugin files"
git commit -m "Escape shortcode output in [fsa_identity_card]"
git commit -m "Cache expensive listing type lookup with wp_cache_get"
git commit -m "Extract role detection into reusable function"
```

Each commit should:
- Be independently revertible
- Pass PHP lint (the CI will catch this for you)
- Not break the site if deployed alone

### 4. Bump version

In the plugin's main PHP file header, bump the `Version:` line:

- Bug fix only → patch: `1.2.2` → `1.2.3`
- Refactor with no behavior change → minor: `1.2.2` → `1.3.0`
- Breaking change (removed shortcode, changed DB schema) → major: `1.2.2` → `2.0.0`

Do this in the final commit of the branch, right before merging.

### 5. Test locally

Before pushing:
- Clear all caches (OPcache, object cache, page cache)
- Smoke-test every user-facing feature the plugin affects
- Check browser console for JS errors on pages the plugin renders
- Check PHP error log for warnings/notices

### 6. Deploy to staging

```bash
git push -u origin refactor/fsa-discount-panel
```

Open PR → `staging`. Merge. Actions deploys. On Kinsta staging:

- Clear cache
- Run the same smoke test you ran locally
- Let it sit 24h before production (edge cases surface overnight — renewal cron, etc.)

### 7. Deploy to production

PR `staging` → `main`. Approval gate fires; approve when ready. Actions deploys.

Post-deploy:
- Clear Kinsta cache
- Spot-check 2–3 key pages
- Monitor error logs for 30 minutes

### 8. Tag the release

After production deploy is verified:

```bash
git checkout main && git pull
git tag -a fsa-discount-panel-v1.3.0 -m "Refactor: audit fixes, cache improvements"
git push --tags
```

Tags give you a one-command rollback path: `git revert` or hard-reset to the previous tag.

## Rollback procedure

If a production deploy breaks something:

1. **Fast path** (SSH into Kinsta production): rename the plugin folder to disable it, e.g. `mv fsa-discount-panel fsa-discount-panel.broken`. Plugin deactivates automatically because WordPress can't find its header. Site resumes working without the plugin's features.
2. **Proper path**: revert the merge commit on `main`, push — Actions redeploys the previous version.

Don't troubleshoot in production. Roll back first, debug on staging.

## Plugin-specific notes

### fsa-discount-panel
- Read two separate repeater meta fields: `_individual-subscriber-products-pricing` and `_corporate-products-pricing`
- Centers single-item display via `fdp-items--single` class — preserve this
- Role + listing type gating is the core logic — test both paths

### fsa-identity-card
- Five shortcodes: `[fsa_identity_card]`, `[fsa_benefits_card]`, `[fsa_service_area_card]`, `[fsa_services_card]`, `[fsa_social_card]`
- Most fields are taxonomy-based; credentials, tagline, profile photo, and insurance disclaimer are post meta
- Insurance disclaimer MUST NOT display until attorney-reviewed content replaces placeholder — add a kill switch if not already present

### fsa-account-menu-manager
- Role detection is pattern-based (any slug containing "provider") — keep this pattern, don't hardcode role lists
- Depends on MyListing's `mylisting-user-menu` theme location for menu rendering
- Delete Account item surfaces in main account menu — don't lose this

### fsahsa-sync
- PHP 7.4 compatible — keep it that way
- Writes `_work_hours` as a PHP associative array (not JSON or stringified array) — the MyListing widget requires this format
- Uses `Work_Hours_JSON__c` as Salesforce staging field; don't shortcut around it
- Recursive trigger prevention must be at the trigger level, not inside called class — `@future` methods reset static flags
- Anything that affects Salesforce writes: test on Kinsta staging with a Salesforce sandbox, never production-to-production for test data
