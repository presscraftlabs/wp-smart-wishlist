<?php
/**
 * Uninstall: remove WishCraft's tables and options. The 2.0 `wishlist` user meta is left alone.
 *
 * @package WishCraft
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

WishCraft\Data\Schema::uninstall();
delete_option( WishCraft\Settings::OPTION );
delete_option( WishCraft\Legacy\Migrator::OPTION );
wp_clear_scheduled_hook( WishCraft\Plugin::CLEANUP_HOOK );
wp_clear_scheduled_hook( WishCraft\Legacy\Migrator::HOOK );

foreach ( wp_roles()->role_objects as $wishcraft_role ) {
	$wishcraft_role->remove_cap( 'manage_wishcraft' );
}
