<?php
/**
 * Who may see and change a list.
 *
 * @package WishCraft
 */

namespace WishCraft;

use WishCraft\Data\Members;

/**
 * Owners do everything. Collaborators (active members, when collaboration is on) see the list and
 * change its items. Anyone may see a public list; a list shared by link is only seen through its
 * share token (see Rest\Lists_Controller::shared()).
 */
final class Access {

	/**
	 * Whether the visitor owns the list.
	 *
	 * @param array $wishlist List.
	 * @return bool
	 */
	public static function owns( array $wishlist ) {
		return Visitor::owns( $wishlist );
	}

	/**
	 * Whether the visitor is an active collaborator.
	 *
	 * @param array $wishlist List.
	 * @return bool
	 */
	public static function collaborates( array $wishlist ) {
		$user = Visitor::user_id();
		return $user && Settings::get( 'collaboration' ) && null !== Members::find( $wishlist['id'], $user );
	}

	/**
	 * Whether the visitor may see the list by its ID.
	 *
	 * @param array $wishlist List.
	 * @return bool
	 */
	public static function can_view( array $wishlist ) {
		if ( self::owns( $wishlist ) || self::collaborates( $wishlist ) || current_user_can( 'manage_wishcraft' ) ) {
			return true;
		}
		return 'public' === $wishlist['privacy'] && Settings::get( 'sharing' );
	}

	/**
	 * Whether the visitor may see the list through its share link.
	 *
	 * @param array $wishlist List.
	 * @return bool
	 */
	public static function can_view_shared( array $wishlist ) {
		if ( self::can_view( $wishlist ) ) {
			return true;
		}
		return 'private' !== $wishlist['privacy'] && Settings::get( 'sharing' );
	}

	/**
	 * Whether the visitor may add, change and remove items.
	 *
	 * @param array $wishlist List.
	 * @return bool
	 */
	public static function can_edit( array $wishlist ) {
		return self::owns( $wishlist ) || self::collaborates( $wishlist );
	}

	/**
	 * Whether the visitor may change the list itself, its sharing and its members, or delete it.
	 *
	 * @param array $wishlist List.
	 * @return bool
	 */
	public static function can_manage( array $wishlist ) {
		return self::owns( $wishlist );
	}

	/**
	 * Role of the visitor for a list, for the REST API.
	 *
	 * @param array $wishlist List.
	 * @return string owner, editor or viewer.
	 */
	public static function role( array $wishlist ) {
		if ( self::owns( $wishlist ) ) {
			return 'owner';
		}
		return self::collaborates( $wishlist ) ? 'editor' : 'viewer';
	}
}
