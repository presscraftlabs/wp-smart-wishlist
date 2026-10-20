<?php
/**
 * Requests made without JavaScript.
 *
 * @package WishCraft
 */

namespace WishCraft\Front;

use WishCraft\Access;
use WishCraft\Data\Items;
use WishCraft\Wishlists;

/**
 * Handlers for the links in the buttons and on the wishlist page.
 *
 * They run through admin-post.php; each checks a nonce and the same permissions as the REST API,
 * then returns to the page.
 */
final class Handler {

	const TOGGLE = 'wishcraft_toggle';

	const REMOVE = 'wishcraft_remove';

	/**
	 * Hooks.
	 */
	public static function register() {
		foreach ( array( self::TOGGLE, self::REMOVE ) as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, $action ) );
			add_action( 'admin_post_nopriv_' . $action, array( self::class, $action ) );
		}
	}

	/**
	 * Where to go back to.
	 *
	 * @return string
	 */
	private static function back() {
		$to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked by the callers; sanitized by esc_url_raw.
		return wp_validate_redirect( $to, home_url( '/' ) );
	}

	/**
	 * Toggle a post in the default list.
	 */
	public static function wishcraft_toggle() {
		check_admin_referer( self::TOGGLE );
		$object    = isset( $_GET['object_id'] ) ? absint( $_GET['object_id'] ) : 0;
		$variation = isset( $_GET['variation_id'] ) ? absint( $_GET['variation_id'] ) : 0;
		Wishlists::toggle( $object, $variation );
		wp_safe_redirect( self::back() );
		exit;
	}

	/**
	 * Remove an item.
	 */
	public static function wishcraft_remove() {
		check_admin_referer( self::REMOVE );
		$item = Items::get( isset( $_GET['item'] ) ? absint( $_GET['item'] ) : 0 );
		if ( $item ) {
			$wishlist = \WishCraft\Data\Lists::get( $item['list_id'] );
			if ( $wishlist && Access::can_edit( $wishlist ) ) {
				Items::remove( $item['id'] );
			}
		}
		wp_safe_redirect( self::back() );
		exit;
	}
}
