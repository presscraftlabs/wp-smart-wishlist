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
		Front\Assets::register();
		Front\Blocks::register();
		Front\Button::register();
		Front\Handler::register();
		Woo\WooCommerce::register();
		Legacy\Widget::register();

		add_action( 'init', array( Schema::class, 'maybe_install' ), 5 );
		add_action( 'admin_init', array( Migrator::class, 'maybe_start' ) );
		add_action( 'rest_api_init', array( self::class, 'rest' ) );
		add_action( self::CLEANUP_HOOK, array( self::class, 'cleanup' ) );
		add_action( 'deleted_user', array( self::class, 'delete_user' ), 10, 2 );
		add_action( 'wishcraft_item_added', array( Wishlists::class, 'reset' ), 10, 0 );
		add_action( 'wishcraft_item_removed', array( Wishlists::class, 'reset' ), 10, 0 );
		add_filter( 'display_post_states', array( self::class, 'post_state' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'privacy_policy' ) );
	}

	/**
	 * REST routes.
	 */
	public static function rest() {
		( new Rest\Lists_Controller() )->register_routes();
		( new Rest\Items_Controller() )->register_routes();
		( new Rest\View_Controller() )->register_routes();
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
		self::create_page();
	}

	/**
	 * Create the wishlist page once, unless one is set and still exists.
	 *
	 * @return int Page ID.
	 */
	public static function create_page() {
		$page = (int) Settings::get( 'page' );
		if ( $page && 'page' === get_post_type( $page ) && 'trash' !== get_post_status( $page ) ) {
			return $page;
		}
		$page = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Wishlist', 'wp-smart-wishlist' ),
				'post_name'    => 'wishlist',
				'post_content' => '<!-- wp:wishcraft/wishlist /-->',
			)
		);
		if ( $page && ! is_wp_error( $page ) ) {
			Settings::save( array( 'page' => $page ) );
			return (int) $page;
		}
		return 0;
	}

	/**
	 * Suggested text for the site's privacy policy.
	 */
	public static function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . __( 'When you save items to a wishlist, we store the list, its items, quantities and notes. If you are logged in, the list belongs to your account.', 'wp-smart-wishlist' ) . '</p>'
			. '<p>' . sprintf(
				/* translators: %s: number of days. */
				__( 'If you are not logged in, we set a cookie named wishcraft_guest with a random code so we can show you your wishlist again. It expires after %s days and is not used for tracking. Your guest wishlist moves into your account when you log in or register, and unused guest wishlists are deleted after the same number of days.', 'wp-smart-wishlist' ),
				(int) Settings::get( 'guest_days' )
			) . '</p>'
			. '<p>' . __( 'If you share a list by link or make it public, anyone with the link can see its title, description, items, quantities, notes and your display name.', 'wp-smart-wishlist' ) . '</p>';
		wp_add_privacy_policy_content( 'WishCraft', wp_kses_post( wpautop( $text, false ) ) );
	}

	/**
	 * "Wishlist page" label in the pages list.
	 *
	 * @param string[] $states States.
	 * @param \WP_Post $post   Post.
	 * @return string[]
	 */
	public static function post_state( $states, $post ) {
		if ( (int) Settings::get( 'page' ) === $post->ID ) {
			$states['wishcraft'] = __( 'Wishlist page', 'wp-smart-wishlist' );
		}
		return $states;
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
