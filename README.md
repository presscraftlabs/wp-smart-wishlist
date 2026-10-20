# WishCraft – Multiple & Shareable Wishlists

WordPress.org plugin [`wp-smart-wishlist`](https://wordpress.org/plugins/wp-smart-wishlist/), rebuilt for 3.0: several named wishlists for WooCommerce products and any post type, guest wishlists merged at login, share links, gift lists and collaborators.

3.0 is in development on `release/3.0`; the plan is in [`docs/ROADMAP-3.0.md`](docs/ROADMAP-3.0.md).

## Development

Requires PHP 7.4+ and WordPress 6.5+.

```
composer install
composer lint                      # PHPCS (WordPress Coding Standards)
bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit
```

`vendor/` is not committed. Releases are deployed to WordPress.org by GitHub Actions; see [`RELEASING.md`](RELEASING.md).

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

Logged-in users authenticate with the usual REST nonce. Guests are recognised by an HttpOnly cookie, and their writes must send the `X-WishCraft: 1` header.

## License

GPL-2.0-or-later.
