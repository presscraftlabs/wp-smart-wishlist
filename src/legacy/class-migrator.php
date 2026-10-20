<?php
/**
 * Upgrade from WP Smart Wishlist 2.0.
 *
 * @package WishCraft\Legacy
 */

namespace WishCraft\Legacy;

use WishCraft\Data\Items;
use WishCraft\Data\Lists;

/**
 * 2.0 kept each user's wishlist in the `wishlist` user meta as a serialized array of post IDs,
 * serialized a second time by WordPress. Each one becomes the user's default list, in batches run
 * by cron. The old meta is kept, so going back to 2.0 still works.
 */
final class Migrator {

	const OPTION = 'wishcraft_migration';

	const HOOK = 'wishcraft_migrate';

	const META = 'wishlist';

	const BATCH = 100;

	/**
	 * Hooks.
	 */
	public static function register() {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Migration state.
	 *
	 * @return array { status: none|pending|done, last_user, users, items, skipped }
	 */
	public static function state() {
		$state = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'status'    => 'none',
				'last_user' => 0,
				'users'     => 0,
				'items'     => 0,
				'skipped'   => 0,
			)
		);
	}

	/**
	 * Save the state.
	 *
	 * @param array $state State.
	 */
	private static function save( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Start the migration once, when there is 2.0 data.
	 */
	public static function maybe_start() {
		$state = self::state();
		if ( 'none' !== $state['status'] ) {
			if ( 'pending' === $state['status'] && ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_single_event( time() + 10, self::HOOK );
			}
			return;
		}
		global $wpdb;
		$found           = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 1", self::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$state['status'] = $found ? 'pending' : 'done';
		self::save( $state );
		if ( $found ) {
			wp_schedule_single_event( time() + 10, self::HOOK );
		}
	}

	/**
	 * Post IDs from a stored 2.0 value. Objects are never created.
	 *
	 * @param string $raw Raw meta_value.
	 * @return int[]
	 */
	public static function decode( $raw ) {
		$value = $raw;
		foreach ( array( 1, 2 ) as $layer ) {
			if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
				break;
			}
			unset( $layer );
			$value = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- objects disabled; malformed values are skipped.
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ids = array();
		foreach ( $value as $id ) {
			if ( is_scalar( $id ) && (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Migrate one batch, and schedule the next.
	 *
	 * @param int $batch Users per batch.
	 * @return array State.
	 */
	public static function run( $batch = self::BATCH ) {
		global $wpdb;
		$state = self::state();
		if ( 'done' === $state['status'] ) {
			return $state;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id > %d ORDER BY user_id ASC LIMIT %d", self::META, $state['last_user'], (int) $batch ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery.slow_query_meta_key,WordPress.DB.SlowDBQuery.slow_query_meta_value
		foreach ( $rows as $row ) {
			$user_id            = (int) $row->user_id;
			$state['last_user'] = max( $state['last_user'], $user_id );
			$ids                = array_filter( self::decode( $row->meta_value ), 'get_post' );
			if ( ! $ids || ! get_userdata( $user_id ) ) {
				++$state['skipped'];
				continue;
			}
			$wishlist = Lists::default_for_user( $user_id, true );
			foreach ( $ids as $id ) {
				if ( Items::add( $wishlist['id'], $id, 0, 1, '', $user_id ) ) {
					++$state['items'];
				}
			}
			++$state['users'];
		}
		if ( count( $rows ) < $batch ) {
			$state['status'] = 'done';
		} else {
			$state['status'] = 'pending';
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
		self::save( $state );
		return $state;
	}
}
