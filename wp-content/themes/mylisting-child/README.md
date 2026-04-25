# MyListing Child Theme

Child theme for fsaproviders.com, running on the MyListing Pro parent theme.

## Structure

```
mylisting-child/
├── style.css                    Theme header only (no CSS rules here)
├── functions.php                Enqueues + file manifest (keep minimal)
├── inc/                         PHP includes, auto-loaded by functions.php
│   └── .gitkeep
└── assets/
    └── css/
        ├── tokens.css           Design tokens (CSS variables)
        ├── base.css             Foundational styles
        ├── components/          Component-specific styles
        │   └── .gitkeep
        └── pages/               Page-type-specific styles
            └── .gitkeep
```

## Rules

1. **No CSS in `style.css`** — it's the theme header file only. Put rules in `assets/css/`.
2. **Presentation only** — if you're writing business logic (user roles, WC hooks, Salesforce), it belongs in a plugin.
3. **No parent theme edits** — ever. If you need to override a parent template, copy it into the child theme at the matching path.
4. **Reference tokens, not raw values** — use `var(--fsa-color-primary)` not `#hex`. Makes rebranding trivial.
5. **One file per feature** — don't accumulate everything into one monolith. Components → `components/`, pages → `pages/`, PHP → `inc/`.

## Adding new CSS

1. Create the file under `assets/css/components/` or `assets/css/pages/`
2. Add it to the enqueue list in `functions.php`
3. Add it as a dependency after `fsa-base` so tokens and base are already loaded

## Adding new PHP

Drop it in `inc/`. It will be auto-loaded by `functions.php`. Name files descriptively: `inc/template-tags.php`, `inc/shortcodes.php`, etc.

## Overriding parent templates

Mirror the parent's path. Example: to override the parent's `partials/listing/card.php`, create `mylisting-child/partials/listing/card.php`.

Copy the parent file verbatim first, commit, then make changes in a follow-up commit. That way the diff of your changes is readable.
