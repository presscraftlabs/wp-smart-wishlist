<?php
/**
 * The current visitor's wishlists.
 *
 * @package WishCraft
 */

namespace WishCraft;

use WishCraft\Data\Items;
use WishCraft\Data\Lists;
use WishCraft\Data\Members;
use WP_Error;

/**
 * Operations on the visitor's own lists, shared by the REST API, the no-JavaScript handler and
 * the renderers.
 */
final class Wishlists {

	/**
	 * IDs of the visitor's lists, for has().
	 *
	 * @var int[]|null
	 */
	private static $list_ids = null;

	/**
	 * Forget cached data (after changes, and between tests).
	 */
	public static function reset() {
		self::$list_ids = null;
	}

	/**
	 * Lists the visitor owns or collaborates on (owned first).
	 *
	 * @return array[]
	 */
	public static function visitor_lists() {
		$user = Visitor::user_id();
		if ( ! $user ) {
			return Lists::for_guest( Visitor::guest_key() );
		}
		$lists = Lists::for_user( $user );
		if ( Settings::get( 'collaboration' ) ) {
			foreach ( Members::list_ids_for_user( $user ) as $id ) {
				$wishlist = Lists::get( $id );
				if ( $wishlist ) {
					$lists[] = $wishlist;
				}
			}
		}
		return $lists;
	}

	/**
	 * Lists the visitor owns.
	 *
	 * @return array[]
	 */
	public static function owned() {
		return array_values( array_filter( self::visitor_lists(), array( Access::class, 'owns' ) ) );
	}

	/**
	 * Lists the visitor can add to.
	 *
	 * @return array[]
	 */
	public static function editable() {
		return array_values( array_filter( self::visitor_lists(), array( Access::class, 'can_edit' ) ) );
	}

	/**
	 * The visitor's default list.
	 *
	 * @param bool $create Create it when missing.
	 * @return array|null
	 */
	public static function default_list( $create = false ) {
		$owned = self::owned();
		foreach ( $owned as $wishlist ) {
			if ( $wishlist['is_default'] ) {
				return $wishlist;
			}
		}
		if ( $owned ) {
			// Lists left without a default (e.g. after an import): the oldest becomes it.
			return Lists::update( $owned[0]['id'], array( 'is_default' => 1 ) );
		}
		if ( ! $create || ! Visitor::can_save() ) {
			return null;
		}
		return Lists::get( Lists::create( Visitor::owner_fields() + array( 'is_default' => 1 ) ) );
	}

	/**
	 * Add a post to a list, or remove it when it is there. Without a list, the default list.
	 *
	 * @param int $object_id    Post ID.
	 * @param int $variation_id Variation ID.
	 * @param int $list_id      List ID (0 for the default list).
	 * @return array|WP_Error { added, list_id, object_id, lists, count }
	 */
	public static function toggle( $object_id, $variation_id = 0, $list_id = 0 ) {
		$object_id    = (int) $object_id;
		$variation_id = (int) $variation_id;
		if ( $list_id ) {
			$wishlist = Lists::get( $list_id );
			if ( ! $wishlist || ! Access::can_view( $wishlist ) ) {
				return new WP_Error( 'wishcraft_not_found', __( 'Wishlist not found.', 'wp-smart-wishlist' ), array( 'status' => 404 ) );
			}
			if ( ! Access::can_edit( $wishlist ) ) {
				return new WP_Error( 'wishcraft_forbidden', __( 'You cannot change this wishlist.', 'wp-smart-wishlist' ), array( 'status' => 403 ) );
			}
		} else {
			$wishlist = self::default_list();
		}
		$existing = $wishlist ? Items::find( $wishlist['id'], $object_id, $variation_id ) : null;
		if ( $existing ) {
			Items::remove( $existing['id'] );
		} else {
			if ( ! Items::allowed( $object_id, $variation_id ) ) {
				return new WP_Error( 'wishcraft_not_allowed', __( 'This item cannot be added to a wishlist.', 'wp-smart-wishlist' ), array( 'status' => 400 ) );
			}
			$wishlist = $wishlist ? $wishlist : self::default_list( true );
			if ( ! Items::add( $wishlist['id'], $object_id, $variation_id, 1, '', Visitor::user_id() ) ) {
				return new WP_Error( 'wishcraft_list_full', __( 'This wishlist is full.', 'wp-smart-wishlist' ), array( 'status' => 403 ) );
			}
		}
		self::reset();
		$status = self::status( array( $object_id ) );
		return array(
			'added'     => ! $existing,
			'list_id'   => $wishlist['id'],
			'object_id' => $object_id,
			'lists'     => isset( $status[ $object_id ] ) ? $status[ $object_id ] : array(),
			'count'     => self::total(),
		);
	}

	/**
	 * Object ID => IDs of the visitor's lists holding it.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array
	 */
	public static function status( array $ids ) {
		return Items::membership( wp_list_pluck( self::visitor_lists(), 'id' ), $ids );
	}

	/**
	 * Whether a post is in one of the visitor's lists (cached for the request).
	 *
	 * @param int $object_id Post ID.
	 * @return bool
	 */
	public static function has( $object_id ) {
		if ( null === self::$list_ids ) {
			self::$list_ids = wp_list_pluck( self::visitor_lists(), 'id' );
		}
		return self::$list_ids && Items::membership( self::$list_ids, array( (int) $object_id ) );
	}

	/**
	 * Number of items in the lists the visitor owns.
	 *
	 * @return int
	 */
	public static function total() {
		$total = 0;
		foreach ( self::owned() as $wishlist ) {
			$total += Items::count( $wishlist['id'] );
		}
		return $total;
	}
}
