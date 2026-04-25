# fsaproviders.com

Repository for custom code powering fsaproviders.com — child theme + custom plugins.

## What's tracked here

- `wp-content/themes/mylisting-child/` — child theme for MyListing Pro
- `wp-content/plugins/fsa-account-menu-manager/` — WC/MyListing nav separation
- `wp-content/plugins/fsa-identity-card/` — single-listing shortcode cards
- `wp-content/plugins/fsa-discount-panel/` — role/listing-type gated pricing display
- `wp-content/plugins/fsahsa-sync/` — WordPress ↔ Salesforce sync

## What's NOT tracked

WordPress core, the parent MyListing theme, WooCommerce, third-party plugins, uploads, cache, and `wp-config.php`. See `.gitignore`.

## Environments

- **Local**: DevKinsta
- **Staging**: Kinsta staging environment (deploy via `staging` branch)
- **Production**: Kinsta live environment (deploy via `main` branch)

## Workflow

1. Branch off `main`: `git checkout -b feature/short-description`
2. Edit in DevKinsta, test locally
3. Commit, push to GitHub
4. PR into `staging`, merge → auto-deploys to Kinsta staging
5. Test on staging
6. PR `staging` into `main`, merge → auto-deploys to Kinsta live

Never commit directly to `main` or `staging` after initial setup.

## First-time setup

See `docs/SETUP.md` for step-by-step instructions including:
- DevKinsta install and import
- GitHub repository creation
- GitHub Actions deployment configuration
- Kinsta SSH key setup

## Snippet migration

See `docs/SNIPPET_AUDIT.md` for the process of moving WPCode snippets into this repo.

## Branch naming conventions

- `feature/...` — new functionality
- `refactor/...` — restructuring existing code, no behavior change
- `fix/...` — bug fixes
- `snippets/migrate-...` — WPCode snippet migrations
- `design/...` — CSS / Claude Design work

## Versioning

Each custom plugin has its own version in its main file header. Bump on every change that ships:
- Patch (1.2.2 → 1.2.3): bug fix, no behavior change
- Minor (1.2.2 → 1.3.0): new feature or refactor
- Major (1.2.2 → 2.0.0): breaking change
