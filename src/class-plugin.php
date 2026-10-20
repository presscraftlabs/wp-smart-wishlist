<?php
/**
 * Plugin bootstrap.
 *
 * @package WishCraft
 */

namespace WishCraft;

use WishCraft\Data\Lists;
use WishCraft\Data\Members;
use WishCraft\Data\Schema;
use WishCraft\Legacy\Migrator;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	const CLEANUP_HOOK = 'wishcraft_cleanup';

	/**
	 * Whether boot() ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register hooks.
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Visitor::register();
		Migrator::register();

		add_action( 'init', array( Schema::class, 'maybe_install' ), 5 );
		add_action( 'admin_init', array( Migrator::class, 'maybe_start' ) );
		add_action( 'rest_api_init', array( self::class, 'rest' ) );
		add_action( self::CLEANUP_HOOK, array( self::class, 'cleanup' ) );
		add_action( 'deleted_user', array( self::class, 'delete_user' ), 10, 2 );
	}

	/**
	 * REST routes.
	 */
	public static function rest() {
		( new Rest\Lists_Controller() )->register_routes();
		( new Rest\Items_Controller() )->register_routes();
	}

	/**
	 * Activation: tables, capability, cron, migration from 2.0.
	 */
	public static function activate() {
		Schema::install();
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'manage_wishcraft' );
		}
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
		Migrator::maybe_start();
	}

	/**
	 * Deactivation: stop background work.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		wp_clear_scheduled_hook( Migrator::HOOK );
	}

	/**
	 * Delete guest lists nobody has touched for the configured number of days.
	 *
	 * @return int Lists deleted.
	 */
	public static function cleanup() {
		$ids = Lists::stale_guest_lists( Settings::get( 'guest_days' ) );
		foreach ( $ids as $id ) {
			Lists::delete( $id );
		}
		return count( $ids );
	}

	/**
	 * A deleted user's lists go with them, or to the user their content is given to.
	 *
	 * @param int      $user_id  User ID.
	 * @param int|null $reassign User receiving the content.
	 */
	public static function delete_user( $user_id, $reassign = null ) {
		foreach ( Lists::for_user( $user_id ) as $wishlist ) {
			if ( $reassign ) {
				Lists::update(
					$wishlist['id'],
					array(
						'owner'      => (int) $reassign,
						'is_default' => 0,
					)
				);
			} else {
				Lists::delete( $wishlist['id'] );
			}
		}
		Members::remove_user( $user_id );
	}
}
