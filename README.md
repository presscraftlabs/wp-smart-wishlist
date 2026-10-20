# WishCraft – Multiple & Shareable Wishlists

WordPress.org plugin [`wp-smart-wishlist`](https://wordpress.org/plugins/wp-smart-wishlist/), rebuilt for 3.0: several named wishlists for WooCommerce products and any post type, guest wishlists merged at login, share links, gift lists and collaborators.

3.0 is in development on `release/3.0`; the plan is in [`docs/ROADMAP-3.0.md`](docs/ROADMAP-3.0.md).

## Development

Requires PHP 7.4+ and WordPress 6.5+.

```
composer install
npm ci && npm run build
composer lint                      # PHPCS (WordPress Coding Standards)
bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit
```

`vendor/` and `build/` are not committed. Releases are deployed to WordPress.org by GitHub Actions; see [`RELEASING.md`](RELEASING.md).

## Blocks and shortcodes

| Block | Shortcode | |
|---|---|---|
| Wishlist (`wishcraft/wishlist`) | `[wishcraft]` | The visitor's lists, and lists opened through a share link (`?wishlist=token`) |
| Wishlist Button (`wishcraft/button`) | `[wishcraft_button id="" label="yes"]` | Heart button for the current or given post |
| Wishlist Counter (`wishcraft/counter`) | `[wishcraft_count label="no"]` | Number of saved items, linking to the wishlist page |

WooCommerce products get the button automatically on product pages and product cards (classic templates and WooCommerce blocks); other post types after the content. The 2.0 "Smart Wish List" widget keeps working.

Theme colours can be changed with the `--wishcraft-accent`, `--wishcraft-border`, `--wishcraft-muted`, `--wishcraft-surface` and `--wishcraft-radius` custom properties.

## REST API (`wishcraft/v1`)

| Route | Methods | |
|---|---|---|
| `/lists` | GET, POST | The visitor's lists (owned and shared with them); create a list |
| `/lists/{id}` | GET, PATCH, DELETE | One list with its items; rename, describe, set privacy; delete |
| `/lists/{id}/share` | POST | New share link (the old one stops working) |
| `/lists/{id}/items` | POST | Add a post or product (with `variation_id`, `quantity`, `note`) |
| `/items/{id}` | PATCH, DELETE | Change quantity, note or position; remove |
| `/items/{id}/move` | POST | Move to another list |
| `/toggle` | POST | Add to or remove from a list (the default list without `list_id`) |
| `/status?ids[]=` | GET | Which of the given posts are saved, the item count and the lists |
| `/shared/{token}` | GET | A list seen through its share link |
| `/view` | GET | Wishlist page markup (`list`, `token`, `url`), used by the script after each change |

Logged-in users authenticate with the usual REST nonce. Guests are recognised by an HttpOnly cookie, and their writes must send the `X-WishCraft: 1` header.

## License

GPL-2.0-or-later.
