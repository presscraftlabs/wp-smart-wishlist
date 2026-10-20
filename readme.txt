=== WishCraft – Multiple & Shareable Wishlists ===

Contributors: rajanit2000
Tags: wishlist, woocommerce wishlist, gift list, favorites, save for later
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Wishlists for WooCommerce and any post type: several named lists, guest wishlists, share links and gift lists. Formerly WP Smart Wishlist. Free.

== Description ==

WishCraft adds a heart button to your products and posts. Visitors save what they love into one or more named wishlists ("Birthday", "Kitchen", "Gift ideas"), come back to them later, share them by link and buy from them.

WishCraft is the new name and a complete rewrite of **WP Smart Wishlist**. The plugin slug is still `wp-smart-wishlist`, and wishlists saved with version 2.0 move over automatically. Everything is free.

= Wishlists =

* **Several lists per visitor**, each with a name and description. Create, rename and delete lists from the wishlist page or straight from the heart button.
* **Quantities and notes** on each item ("size M", "the blue one"), and **move** items between lists.
* **Guest wishlists:** visitors can save items without an account. When they log in or register, their guest lists move into their account.
* **Works on any post type:** WooCommerce products, posts, pages from other plugins (properties, courses, recipes...).

= Sharing and gift lists =

* Every list is **private**, **shared by link** or **public**.
* A **share link** that is hard to guess, with a copy button. Making the list private turns the link off.
* People who open a shared list see the items with prices and can **add them to their own cart** (one by one or all at once), or save them to their own wishlist.

= WooCommerce =

* The heart button on **product pages** and **product cards**, in block themes (WooCommerce blocks) and classic themes.
* **Variable products:** the chosen variation is saved, and goes to the cart with its attributes.
* Price, stock and an **Add to cart** button for each item, and **Add all to cart** for a whole list.
* Compatible with WooCommerce order storage (HPOS) and the cart and checkout blocks.

= For any theme =

* **Blocks:** Wishlist, Wishlist Button and Wishlist Counter (a heart with the number of saved items, for headers).
* **Shortcodes:** `[wishcraft]`, `[wishcraft_button id="123"]` and `[wishcraft_count]`.
* A **Wishlist page** is created when you activate the plugin.
* Fast and light: one small script without jQuery, loaded only on pages that show a wishlist.
* **Works without JavaScript** too: the button and the remove links are real links.
* Accessible buttons and dialogs, keyboard friendly, and responsive down to phone screens.
* Neutral styles that follow your theme. Change the accent colour with the `--wishcraft-accent` CSS custom property.

= Privacy =

WishCraft does not use any external services. Guests are recognised by a cookie (`wishcraft_guest`) that holds a random code; only a coded form of it is stored on your site. Unused guest wishlists are deleted after 30 days. WishCraft adds suggested text to your privacy policy page (Settings → Privacy).

= Coming next =

* Share buttons (email, WhatsApp, X, Facebook, Pinterest) and **collaborative lists** that friends and family can edit.
* A settings screen (button text and positions, post types, guests, number of lists) and a "most wishlisted" report.

== Installation ==

1. Install WishCraft from **Plugins → Add New** and activate it.
2. A **Wishlist** page is created for you. Add it to your menu, and add the **Wishlist Counter** block to your header if you like.
3. With WooCommerce, the heart button appears on product pages and product cards. Without WooCommerce, it appears after the content of your posts.

== Frequently Asked Questions ==

= I used WP Smart Wishlist 2.0. What happens to my data? =

After the update, each user's 2.0 wishlist becomes their list "My wishlist", with the same items in the same order. This runs in the background in small batches. The 2.0 data is kept, and the "Smart Wish List" widget keeps working and shows the new lists.

= WP Smart Wishlist 2.0 worked with WP eCommerce. Does WishCraft? =

Yes: WP eCommerce products (`wpsc-product`) are supported as a post type, and WooCommerce products are supported fully.

= Can visitors use wishlists without an account? =

Yes. Their lists are kept for 30 days in their browser and move into their account when they log in or register. Developers can turn guest wishlists off with the `wishcraft_settings` option (`guests` set to false).

= How do I put the button somewhere else? =

Use the **Wishlist Button** block in your product or post template, or the `[wishcraft_button]` shortcode. Inside a loop it uses the current post; elsewhere, pass the post or product ID: `[wishcraft_button id="123"]`. Add `label="no"` for a heart without text.

= Which post types get the button? =

WooCommerce products when WooCommerce is active, otherwise posts. Developers can enable other post types with the `wishcraft_supports_post_type` filter.

= How does sharing work? =

On the wishlist page, choose **Edit list** and set "Who can see it" to **Shared** (anyone with the link) or **Public**. The share link appears above the list, with a copy button. Set the list back to **Private** to turn the link off.

= Can I change how the plugin looks? =

WishCraft uses neutral styles that inherit your theme's fonts and colours. Override the `--wishcraft-accent`, `--wishcraft-border`, `--wishcraft-muted`, `--wishcraft-surface` and `--wishcraft-radius` CSS custom properties, or style the `.wishcraft-` classes.

= Does it work with page caching? =

Yes. Buttons and counters load the visitor's state after the page loads, so cached pages show each visitor their own wishlist.

= Is there a REST API? =

Yes, under `wishcraft/v1`: lists, items, toggle, status and shared lists. See the project on GitHub for the routes.

== Screenshots ==

1. The heart button on a WooCommerce product page.
2. Choosing a list, or creating a new one, from the heart button.
3. The wishlist page with lists, quantities, notes and add to cart.
4. A wishlist opened through its share link, ready to buy from.
5. Hearts on product cards in the shop.

== Changelog ==

= 3.0.0 =
* New: renamed to WishCraft and rewritten from scratch.
* New: several named wishlists per visitor, with descriptions, quantities and notes; move items between lists.
* New: guest wishlists, moved into the account at login or registration.
* New: private, shared-by-link and public lists, with a share link and a copy button.
* New: shared lists show prices and stock, with add to cart for each item or for the whole list.
* New: WooCommerce support for product pages and product cards in block and classic themes, including variable products.
* New: Wishlist, Wishlist Button and Wishlist Counter blocks, and the `[wishcraft]`, `[wishcraft_button]` and `[wishcraft_count]` shortcodes.
* New: works with any post type, and without JavaScript.
* New: REST API under `wishcraft/v1`.
* New: suggested privacy policy text.
* Improvement: 2.0 wishlists move over automatically and the 2.0 widget keeps working.
* Fix: version 2.0 stopped the site with a fatal error on PHP 8.
* Security: every change is checked against the list owner, with nonces and escaped output.
* Requires WordPress 6.5 and PHP 7.4 or later.

= 2.0 =
* WP eCommerce wishlist with a widget.

== Upgrade Notice ==

= 3.0.0 =
WP Smart Wishlist is now WishCraft: several shareable wishlists, guest wishlists and full WooCommerce support. Version 2.0 does not run on PHP 8. Your saved wishlists move over automatically. Requires WordPress 6.5 and PHP 7.4.
