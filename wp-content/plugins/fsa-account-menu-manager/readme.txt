=== FSA Account Menu Manager ===
Contributors: fsaproviders
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Control the WooCommerce My Account menu and keep its endpoints out of the
MyListing user navigation dropdown.

== What it does ==

1. Replaces the WooCommerce My Account menu (shown on /my-account/) with a
   list you control from WooCommerce → Account Menu. You can add:
     - Any registered WooCommerce endpoint (Orders, Subscriptions, Role
       History, Memberships, Wishlist, etc. — auto-discovered from your
       WC install)
     - Any WordPress page
     - Any custom URL (internal or external)
2. Automatically strips WooCommerce endpoints from the MyListing user
   dropdown in the site header. MyListing's own items (Bookmarks,
   Listings, Profile, etc.) are untouched.

== How the separation works ==

WooCommerce's account navigation template fires
`woocommerce_before_account_navigation` before iterating
`wc_get_account_menu_items()`. The renderer flips a flag on that action
and flips it back on `woocommerce_after_account_navigation`. The
`woocommerce_account_menu_items` filter then behaves differently based
on context:

  - Inside the WC nav: returns your configured menu.
  - Everywhere else (MyListing dropdown, any third-party caller):
    returns an empty array, so WC endpoints do not appear.

This means WC and MyListing are now independently controllable without
touching either theme.

== Installation ==

1. Upload the `fsa-account-menu-manager` folder to `/wp-content/plugins/`
   (or install the ZIP via Plugins → Add New → Upload Plugin).
2. Activate through the Plugins menu.
3. Go to WooCommerce → Account Menu to configure.

== Notes ==

- Requires WooCommerce. Tested with WooCommerce 9.x.
- Pairs with MyListing theme; safe to use without MyListing (the
  dropdown-stripping behavior just has nothing to strip).
- Menu data is stored in a single option: `fsa_amm_items`.
- Custom items (pages and URLs) use synthetic keys prefixed with
  `fsa_custom_` internally; `woocommerce_get_endpoint_url` is filtered
  to return the real target URL.

== Changelog ==

= 1.2.1 =
* Register the Delete Account stripper lazily inside `woocommerce_before_account_navigation` so it always runs after any filter another plugin registered at PHP_INT_MAX during plugins_loaded (resolves a priority-tie load-order problem).
* Fuzzy-match Delete Account entries by key ("delete" + "account" substrings) and label ("delete account" substring) instead of hard-coding specific keys, so any plugin's variant is caught regardless of how it names the menu key.

= 1.2.0 =
* Decouple the `/my-account/` WC endpoint navigation from the MyListing `mylisting-user-menu` ("Woocommerce Menu") theme location. MyListing overrides WC's `myaccount/navigation.php` with `templates/dashboard/navigation.php`, which renders the nav menu assigned to `mylisting-user-menu` instead of calling `wc_get_account_menu_items()`. This release filters `theme_mod_nav_menu_locations` only during the WC account navigation render span (bracketed by `woocommerce_before_account_navigation` / `woocommerce_after_account_navigation`), so the template falls through to its `else` branch on `/my-account/` and uses the plugin's configured menu. The header dropdown and MyListing dashboard rendering are untouched because the filter is only live for the duration of the account nav template.

= 1.1.0 =
* Strip WP Frontend Delete Account (`wpf-delete-account`) from the WooCommerce menu items array at PHP_INT_MAX priority.
* Strip `wpf-delete-account` from `woocommerce_get_query_vars` so theme code iterating WC endpoints directly (e.g. MyListing's theme-location fallback) cannot see it.
* Filter `wp_nav_menu_objects` to remove any nav menu item whose URL points at the Delete Account endpoint, or whose title is literally "Delete Account". Children of removed items are also stripped.

= 1.0.0 =
* Initial release.
