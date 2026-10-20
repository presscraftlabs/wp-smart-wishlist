<?php
/**
 * Plugin Name:       WishCraft – Multiple & Shareable Wishlists
 * Plugin URI:        https://wordpress.org/plugins/wp-smart-wishlist/
 * Description:       Wishlists for WooCommerce and any post type: several named lists, guest wishlists, share links, gift lists and collaborators.
 * Version:           3.0.0-dev
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Rajan V
 * Author URI:        https://profiles.wordpress.org/rajanit2000/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-smart-wishlist
 *
 * @package WishCraft
 */

defined( 'ABSPATH' ) || exit;

define( 'WISHCRAFT_VERSION', '3.0.0-dev' );
define( 'WISHCRAFT_FILE', __FILE__ );
define( 'WISHCRAFT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WISHCRAFT_URL', plugin_dir_url( __FILE__ ) );

if ( ! file_exists( WISHCRAFT_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WishCraft is missing its vendor folder. Run composer install, or install the plugin from WordPress.org.', 'wp-smart-wishlist' ) . '</p></div>';
		}
	);
	return;
}

require WISHCRAFT_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( 'WishCraft\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WishCraft\Plugin', 'deactivate' ) );

WishCraft\Plugin::boot();
