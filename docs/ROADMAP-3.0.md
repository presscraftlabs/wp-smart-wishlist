# WishCraft 3.0: multiple and shareable wishlists

The plugin keeps the WordPress.org slug `wp-smart-wishlist` and its main file `install.php`. It is released as **WishCraft – Multiple & Shareable Wishlists**. Everything is free.

## Where we start

### Live release 2.0 on WordPress.org
- Last updated January 2018, "tested up to" 4.1, about 10 active installs. `readme.txt` says `Stable tag: 4.1`, which does not exist, so the site is served from `trunk`.
- One file, `install.php` (218 lines), for **WP eCommerce** only: an "Add to Wishlist" button on the `wpsc_product_form_fields_end` hook, a "Smart Wish List" widget (`WishlistWidget`) that loads the list over admin-ajax, and three AJAX actions (`add_wish_list`, `remove_wish_list`, `load_wish_list`).
- **Data:** one user meta value per user, `wishlist`, holding a *serialized string* of post IDs (serialized twice, since WordPress serializes meta again).
- **Problems:**
  - **Fatal error on PHP 8:** the widget is registered with `create_function()`, removed in PHP 8.0, and uses the PHP 4 widget constructor.
  - No nonces and no input validation on the AJAX actions; `unserialize()` on stored data.
  - Logged-in users only; output is not escaped; CSS is printed with a `<link>` in `wp_head`.
  - WP eCommerce is effectively abandoned, so the button no longer appears on most shops.

## Goals
- **Shoppers:** heart any product or post, keep **several named lists** ("Birthday", "Kitchen"), move items between them, add notes and quantities, and buy from them.
- **Sharing:** every list is **private, shared by link or public**; share buttons and email; anyone with the link can **add the items to their own cart** (gift lists); owners can **invite collaborators** who add and remove items.
- **Guests:** wishlists without an account, merged into the account at login or registration.
- **Shops:** first-class **WooCommerce** support (simple and variable products, price and stock, add to cart from the list), and **any public post type** (posts, EstateCraft properties, courses...) without a shop.
- **Themes:** blocks for block themes, shortcodes and a widget for classic themes; neutral styles.
- **Upgrade safety:** 2.0 wishlists and widgets keep working after the update.
- **Engineering:** WordPress coding standards, PHP 7.4 to 8.4, WordPress 6.5+, accessible, Plugin Check clean, the same release tooling as BookPress, SlideCraft and EstateCraft.

## Architecture
- **PHP:** namespace `WishCraft`, Composer classmap autoload, bootstrap stays `install.php`; text domain `wp-smart-wishlist`; prefixes `wishcraft_` / `WISHCRAFT_`.
- **JavaScript:** `@wordpress/scripts`; entries for the front end (button, list picker, wishlist page, shared view), the block editor and the settings app. Plain JavaScript on the front end (no jQuery), progressive enhancement: the button is a real form/link that works without JavaScript.
- **Tables** (`dbDelta`, schema version option):
  - `{prefix}wishcraft_lists`: id, owner (user ID, 0 for guests), guest key (hash of the guest cookie), title, description, privacy (`private`, `link`, `public`), share token, is default, created, updated.
  - `{prefix}wishcraft_items`: id, list id, object id (post or product), variation id, quantity, note, added by, added at, position; unique per list, object and variation.
  - `{prefix}wishcraft_members`: list id, user id, invited email, invite token (hashed), role (`editor`), status, invited at.
- **Guests:** an HttpOnly, SameSite=Lax cookie with a random token; only its hash is stored. Merged into the user's default list at login and registration; guest lists expire after a set number of days (cron).
- **REST API `wishcraft/v1`:** lists (list, create, update, delete), items (add, update, remove, move, toggle), sharing (rotate or revoke the link), members (invite, accept, remove), shared view by token, add list to cart. Every write checks ownership or membership; guests are identified by their cookie and their writes must send an `X-WishCraft` header (a custom header cannot be sent cross-site without a CORS preflight; REST nonces are the same for every guest and go stale in cached pages).
- **Background work:** guest list clean-up, invitation expiry.

## Features
- **Wishlist button:** heart icon with optional text; on WooCommerce single product pages and product cards (classic templates and WooCommerce blocks), on any enabled post type through a block, a shortcode and an automatic position. With several lists, a small picker chooses the list or creates a new one.
- **Wishlist page** (block and `[wishcraft]` shortcode, page created on activation): the visitor's lists, items with image, price, stock, quantity and note, remove and move, add one or all to cart, list settings.
- **Counter:** header block and shortcode with the number of saved items.
- **Sharing:** privacy per list; share link with an unguessable token that can be rotated or revoked; copy link, native share sheet, email, WhatsApp, X, Facebook, Pinterest; read-only shared view with "add to my cart" and "save to my wishlist".
- **Collaborative lists:** invite by email; invitees accept with a link (and log in or register); editors add, remove and reorder items; the owner manages members.
- **Privacy:** personal data export and erase for lists, items and memberships.
- **Admin:** settings app (post types, button positions and labels, guests, number of lists, sharing networks, collaboration, wishlist page), "most wishlisted" overview, and the 2.0 migration report.

## Upgrade from 2.0
Runs automatically on update, in batches, and never deletes the 2.0 data:
- Each user's `wishlist` meta becomes their default list "My wishlist" (items keep their order).
- The "Smart Wish List" widget keeps its widget ID and shows the new list.
- WP eCommerce products (`wpsc-product`) stay supported as a post type.

## Milestones
| # | Scope |
|---|---|
| M1 | Foundations: bootstrap, tables, lists and items model, guests and merge at login, REST API with permissions, 2.0 migration, tests, release tooling |
| M2 | Front end: button and list picker, wishlist page, counter, WooCommerce integration (classic and block templates, variations, add to cart), blocks and shortcodes, legacy widget |
| M3 | Sharing and collaboration: privacy, links, share buttons, shared view, add to cart from a shared list, invitations and members, emails |
| M4 | Admin settings app, most wishlisted overview, privacy tools, logo, banner, screenshots, `readme.txt` and `README.md`, Plugin Check, release 3.0.0 |

**Release process, as for the other plugins:** work on `release/3.0`, one PR to `master` with CI green; the owner pushes the `3.0.0` tag, the deploy finishes, then merge.

## Verification
- **PHPUnit:** list and item rules, permissions (owners, collaborators, guests, shared links), guest merge, migration from seeded 2.0 data, REST routes, add to cart, privacy export and erase.
- **Browser:** WooCommerce shop on a block theme and a classic theme; guest to account merge; share link in a private window; collaborator invite; phone width; no JavaScript.
- **Release checks:** Plugin Check clean, shipped-PHP lint on 7.4 to 8.4, PHPUnit on WordPress 6.5 and latest.
