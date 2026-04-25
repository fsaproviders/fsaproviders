# FSA Identity Card

Renders a Psychology Today-style identity card on MyListing single listing pages.

## Install

1. Zip the `fsa-identity-card` folder.
2. WP Admin → Plugins → Add New → Upload Plugin → activate.
3. Settings → FSA Identity Card → confirm field mappings.

## Default field mapping

| Card element | Source | Default |
|---|---|---|
| First Name | post meta | `provider-first-name` |
| Last Name | post meta | `provider-last-name` |
| Credentials | post meta | `credentials` |
| Pronouns | taxonomy | `pronouns` |
| Tagline | post meta | `identity-card-tagline` |
| Photo | post meta | `identity-card-profile-photo` |
| Category | taxonomy | `provider-category` |
| Verified badge | MyListing native verification | — |

The plugin auto-prefixes meta keys with `_` when reading (MyListing convention) and falls back to the unprefixed key.

## Placement

- **Auto-inject (default ON):** card renders at the top of the listing content on every single listing page, all listing types.
- **Manual:** disable auto-inject and place `[fsa_identity_card]` anywhere — including a Static Code block in the Single Listing Page Builder.

## Removing the Provider Category card

Per your decision: edit the Single Listing Page layout in MyListing's Page Builder and remove the Provider Category block. The new identity card displays the category as a pill.

## Per-listing opt-out

```php
add_filter( 'fsa_ic_disable_auto_inject', function( $disabled, $listing_id ) {
    if ( $listing_id === 123 ) return true;
    return $disabled;
}, 10, 2 );
```

## Notes

- File Upload field stores attachment ID(s); renderer handles ID, URL, JSON, serialized, and array formats.
- If both first/last name are empty, falls back to the listing post title so the card never renders nameless.
- Empty optional fields (credentials, pronouns, tagline, photo) hide gracefully.
