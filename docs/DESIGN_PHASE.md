# Claude Design → CSS modernization (Phase 5)

Last phase. Do not start until Phases 1–4 are complete and stable on production. CSS work on top of unstable PHP code produces cascading bugs you can't diagnose.

## Prerequisites

- Repo is clean: snippets migrated, plugins refactored, all changes in Git
- Staging and production are in sync
- You have access to Claude Design (Pro/Max/Team/Enterprise plan, per Anthropic's docs)
- Design tokens file at `wp-content/themes/mylisting-child/assets/css/tokens.css` is ready to be populated

## Step 1: Set up the design system in Claude Design

Per the Claude Design docs, this is a separate setup article — do it before generating any designs. The guide is at:

https://support.claude.com/en/articles/14604397-set-up-your-design-system-in-claude-design

What goes into the design system:

**Colors** — your brand palette. Primary, secondary, accent, semantic (success/warning/error), neutrals. Pull from your current site by inspecting live elements in browser devtools.

**Typography** — font families actually in use. MyListing likely loads specific Google Fonts; check the parent theme's enqueues or use browser devtools to identify them. Define a type scale (size, weight, line-height per role: display, heading levels, body, caption).

**Spacing scale** — the increments you'll use (the tokens file uses a 4px base: 4, 8, 12, 16, 20, 24, 32, 40, 48, 64).

**Component patterns** — reference components that exist in your site that should be preserved:
- Identity card (profile photo + credentials + tagline layout)
- Benefits card (list-style with icons)
- Discount panel (pricing display, single-item centered variant)
- Listing cards on directory pages
- Search/filter UI on explore pages

Claude Design will inherit these across every project, so the design system is a one-time investment paying out over every subsequent generation.

## Step 2: Link your GitHub repo

In Claude Design → project settings → link codebase → select `fsaproviders` repo on GitHub.

This gives Claude Design context on:
- Your existing CSS class names
- Your child theme's file structure
- Your shortcode output markup (so it generates CSS targeting the actual HTML you produce)
- Naming conventions already in use

## Step 3: Inventory current design

Before generating new designs, capture what exists. One screenshot per unique page/template type:

| Page type | Capture |
|---|---|
| Homepage | Full page (hero, features, CTA) |
| Directory / Explore | Search UI + results + filters |
| Single listing — claimed | All five identity cards + WC integration |
| Single listing — unclaimed | Default state with claim CTA |
| Provider dashboard | Account pages WC + MyListing |
| Add listing form | Multi-step form UI |
| Subscription checkout | WooCommerce checkout |
| My Account — subscriber | WC subscriber dashboard |
| My Account — provider | MyListing provider dashboard |
| Login / register | Both forms |
| Static pages | Homepage, about, pricing, contact |

Upload these to Claude Design as project context — "here's what exists today."

## Step 4: Iterate page-by-page, not site-wide

Do not ask Claude Design to redesign the whole site. One page type per project (or per canvas within a project). Reasons:
- Smaller scope → tighter feedback loops
- Easier to review and approve
- Easier to deploy in isolation
- If one page's redesign doesn't land, the others aren't blocked

Suggested order — lowest risk first:

1. **Static pages** (about, pricing) — no business logic, safe to restyle
2. **Homepage** — high visibility but no transactional risk
3. **Directory / Explore** — core UX, test carefully
4. **Single listing** — affects the five identity cards and discount panel; coordinate with those plugin owners (= you)
5. **Checkout and account pages** — highest risk. Any layout shift can tank conversions.

## Step 5: Prompting patterns that work

Per the Claude Design docs, a good prompt specifies goal + layout + content + audience. Apply that to your site:

**Weak**: "Modernize the single listing page."

**Strong**: "Redesign the single provider listing page for fsaproviders.com. Goal: help consumers decide whether this provider accepts their FSA/HSA and is a good fit. Layout: hero with provider name, credentials, and primary CTA at top; left column with identity card, services, service area; right column with benefits, discount panel, and social proof. Content: all existing shortcode output — [fsa_identity_card], [fsa_benefits_card], [fsa_service_area_card], [fsa_services_card], [fsa_social_card], [fsa_discount_panel]. Audience: consumers searching for FSA/HSA-eligible healthcare providers, typically on mobile first. Use the existing design tokens already in the system."

Always mention that the design must work with your existing markup — Claude Design has the repo linked, so it can see what the shortcodes output. Telling it "use the existing markup" keeps it from generating designs that would require changing the PHP.

## Step 6: Iterate with chat + inline comments

Per Anthropic's docs:
- **Chat** for structural changes: "move the discount panel to the top on mobile"
- **Inline comments** for targeted changes: click the element, "make this padding 16px"

Be specific. "This looks off" doesn't help. "The credentials list is visually heavier than the tagline; swap the weight so the tagline is the primary visual" does.

## Step 7: Handoff to Claude Code

When a design is approved in Claude Design, use **Export → Handoff to Claude Code**. This pipes the design into Claude Code running against your local repo.

Claude Code will propose CSS changes. **Do not auto-accept.** Review the diff:

- Does it write to `assets/css/components/` or `assets/css/pages/`? ✓
- Does it modify `style.css` directly? ✗ — that file is the theme header, redirect it
- Does it use existing design tokens (`var(--fsa-color-primary)`)? ✓
- Does it hardcode colors/sizes? ✗ — ask it to use tokens instead
- Does it override MyListing's own CSS with `!important` everywhere? ✗ — look for cleaner selectors
- Does it touch parent theme files? ✗ — must be child-theme-only

Approve the changes incrementally, commit with descriptive messages:

```bash
git checkout -b design/single-listing-redesign
# Claude Code makes changes
git add wp-content/themes/mylisting-child/assets/css/
git commit -m "Redesign single listing page layout"
```

## Step 8: Enqueue the new files

For each new CSS file under `components/` or `pages/`, add an `wp_enqueue_style` call in `functions.php`. Pattern:

```php
// Inside the wp_enqueue_scripts callback in functions.php

if ( is_singular( 'job_listing' ) ) {  // MyListing's listing post type
    $file = $theme_path . '/assets/css/pages/single-listing.css';
    if ( file_exists( $file ) ) {
        wp_enqueue_style(
            'fsa-page-single-listing',
            $theme_uri . '/assets/css/pages/single-listing.css',
            array( 'fsa-base' ),
            filemtime( $file )
        );
    }
}
```

Conditional loading (only enqueue a page-specific stylesheet on that page) is important — don't load checkout CSS on every page.

## Step 9: Staging verification

Deploy the design branch to Kinsta staging via the usual workflow.

Verification checklist per page redesign:

- [ ] Desktop 1920px — looks correct
- [ ] Laptop 1440px — looks correct
- [ ] Tablet 768px — looks correct
- [ ] Mobile 375px — looks correct
- [ ] No console errors (JS or CSS)
- [ ] Page speed — Lighthouse score not worse than baseline (capture baseline first)
- [ ] All interactive elements still work (buttons, forms, dropdowns)
- [ ] Existing shortcode output is unchanged — only its styling changed
- [ ] Dark mode / light mode if your design uses both
- [ ] Print stylesheet still works if you rely on one

Let each page redesign sit on staging 48 hours minimum before production. CSS bugs often only show up on edge-case content (very long provider names, unusual credential combinations, missing photos, etc.).

## Step 10: Production deploy

Same workflow as other phases — PR `staging` → `main`, approve, deploy.

After deploy:
- Clear Kinsta cache (page cache, object cache, CDN)
- Hard refresh in browser (Cmd/Ctrl+Shift+R)
- Spot-check the redesigned page
- Leave analytics / conversion tracking open — if conversion rate tanks, revert immediately

## Rollback for CSS

CSS regressions are usually safer to hotfix than revert, because reverting pulls in a whole commit. Keep the old CSS file in Git history and, if needed, check out the previous version of just that one file:

```bash
git log wp-content/themes/mylisting-child/assets/css/pages/single-listing.css
git checkout <previous-commit> -- wp-content/themes/mylisting-child/assets/css/pages/single-listing.css
git commit -m "Hotfix: revert single listing CSS to previous version"
```

Push, deploy. Faster than full revert of a large design commit.

## What Claude Design is and isn't good at

**Good at**:
- Generating first-pass layouts from a clear prompt
- Applying a design system consistently
- Responsive breakpoints and spacing rhythm
- Accessibility checks on contrast and hierarchy

**Not good at**:
- Understanding complex WordPress template hierarchy
- Knowing which MyListing classes are overridable vs. untouchable
- Matching pixel-perfect existing brand assets without strong source material
- Performance implications of its own output (it may generate CSS that triggers layout thrash)

Your job in the loop is to bring those blind spots. You know your stack; Claude Design brings design velocity.
