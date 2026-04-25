# Contributing

Conventions for working in this repo. Applies to solo work too — future-you will appreciate past-you following these.

## Branches

### Naming

- `feature/short-description` — new functionality
- `refactor/short-description` — restructuring existing code, no behavior change
- `fix/short-description` — bug fix
- `snippets/migrate-batch-N-topic` — WPCode snippet migrations during Phase 3
- `design/short-description` — CSS/design work during Phase 5
- `docs/short-description` — documentation only

Use kebab-case after the prefix. No spaces, no underscores.

### Protected branches

Configure on GitHub (Settings → Branches → Branch protection rules):

- `main`: require PR, require PR approval (you approving your own is fine for a solo repo), require status checks (PHP lint), no direct pushes
- `staging`: require PR, require status checks (PHP lint), no direct pushes

Without these rules, a mistaken `git push` to `main` will auto-deploy to production.

## Commits

### Format

Single-line summary in imperative mood, 72 chars or less. Optional body after a blank line for context.

Good:
```
Escape shortcode output in fsa_identity_card
```

```
Cache provider lookup by ZIP to avoid N+1 on directory page

The previous implementation queried wp_postmeta once per listing
during the directory loop. With ~2000 listings that's ~2000 queries
per page load. wp_cache_set/get reduces this to 1.
```

Bad:
```
fixes
updated some stuff
wip
```

### Granularity

One logical change per commit. If your commit message needs the word "and," split it.

### Never commit

- `wp-config.php` or anything with credentials
- Files inside `wp-content/uploads/`
- Database dumps
- Any file the `.gitignore` covers (if Git is showing these, fix the `.gitignore`, don't force-add)

## Pull requests

### Title

Same format as commit summary. Describes what the PR does, not what it fixes.

### Description

Include:

- **What** this PR changes
- **Why** it's needed
- **How** to test (specific pages to click, specific actions to try)
- **Risks** — anything that might break, any rollback considerations
- **Environment notes** — if it requires a manual step on staging/production (cache clear, plugin activation, DB migration), call it out

### PR size

Aim for < 400 lines changed. Large PRs are hard to review and risky to deploy. If a refactor is inherently large, split it into sequential PRs that each leave the site functional.

### Merge strategy

- **Feature/fix branches → `staging`**: Squash merge. Keeps staging history clean.
- **`staging` → `main`**: Merge commit (not squash). Preserves the individual commits so production history mirrors staging history.

## Versioning

Plugins and the child theme each have a `Version:` field in their main file header. Bump on every change that ships:

- Patch (1.2.2 → 1.2.3) — bug fix, no behavior change
- Minor (1.2.2 → 1.3.0) — new feature or refactor
- Major (1.2.2 → 2.0.0) — breaking change

Bump in the final commit of a branch, right before merging. Tag after production deploy:

```bash
git tag -a fsa-discount-panel-v1.3.0 -m "Refactor: audit fixes"
git push --tags
```

## Deploy discipline

- Never deploy to production on a Friday afternoon
- Never deploy to production when you can't be available for 30 minutes after
- Always let staging bake — 24h minimum for refactors, 48h for design changes
- Clear Kinsta cache after every deploy
- Check error logs 10 minutes after production deploy
- If something breaks: roll back first, debug second

## Rollback cheat sheet

Fast rollback via SSH (plugin is breaking the site):

```bash
ssh user@host -p port
cd /www/sitename/public/wp-content/plugins
mv fsa-discount-panel fsa-discount-panel.broken
```

Plugin deactivates automatically (WordPress can't find its header), site recovers. Debug the branch, fix, redeploy.

Proper rollback via Git (preferred when site is functional but wrong):

```bash
git checkout main
git revert <bad-commit-sha>
git push
```

Actions redeploys the reverted state. Approval gate still applies.

## Code style

### PHP

- Follow [WordPress coding standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Tabs for indentation (WordPress convention)
- `ABSPATH` guard at the top of every PHP file that could be loaded directly
- Escape all output: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`
- Sanitize all input: `sanitize_text_field`, `absint`, `sanitize_email`, etc.
- Prefix all global functions and classes with `fsa_` or `FSA_`
- Prefix all hook names with `fsa/` (e.g. `fsa/discount_panel/before_render`)
- PHP 7.4 compatible (no `match`, no constructor promotion, no typed constants)

### CSS

- Only in `wp-content/themes/mylisting-child/assets/css/`, never in `style.css`
- Use CSS variables from `tokens.css`, never hardcode values
- No `!important` unless overriding parent theme and no cleaner path exists
- Component-level CSS goes in `components/`, page-level in `pages/`
- Filenames match what they style: `single-listing.css`, `identity-card.css`

### JS

- Vanilla JS preferred; jQuery only if interfacing with something that requires it
- Enqueue properly — never inline in templates
- Localize any text strings with `wp_localize_script`

## When to ask before doing

Some changes touch shared foundations and are worth a second pair of eyes (or a pause-and-think) before shipping:

- Database schema changes (plugin activation hooks that add tables/columns)
- User role modifications
- Anything touching the Salesforce sync transaction flow
- Disabling or removing existing hooks other plugins may depend on
- Changes to routing, rewrite rules, or permalink structure
- Any change that can't be reverted by a simple code rollback
