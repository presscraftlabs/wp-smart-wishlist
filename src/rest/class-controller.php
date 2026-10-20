<?php
/**
 * Shared REST helpers.
 *
 * @package WishCraft
 */

namespace WishCraft\Rest;

use WishCraft\Access;
use WishCraft\Data\Items;
use WishCraft\Data\Lists;
use WishCraft\Data\Members;
use WishCraft\Settings;
use WishCraft\Visitor;
use WP_Error;
use WP_REST_Request;

/**
 * Base for the controllers of the wishcraft/v1 namespace.
 *
 * Logged-in users are protected from cross-site requests by the REST nonce that WordPress checks
 * for cookie authentication. Guests have no nonce worth checking (it is the same for every guest
 * and cached pages serve stale ones), so their writes must carry the X-WishCraft header: browsers
 * only send a custom header cross-site after a CORS preflight, which WordPress does not allow for
 * this header, and the guest cookie itself is SameSite=Lax.
 */
abstract class Controller {

	const NAMESPACE = 'wishcraft/v1';

	const HEADER = 'X-WishCraft';

	/**
	 * Register routes.
	 */
	abstract public function register_routes();

	/**
	 * Permission for writes by the current visitor.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_write( WP_REST_Request $request ) {
		if ( ! Visitor::can_save() ) {
			return new WP_Error( 'wishcraft_login_required', __( 'Log in to save a wishlist.', 'wp-smart-wishlist' ), array( 'status' => 401 ) );
		}
		if ( ! Visitor::user_id() && '1' !== (string) $request->get_header( self::HEADER ) ) {
			return new WP_Error( 'wishcraft_missing_header', __( 'This request needs the X-WishCraft header.', 'wp-smart-wishlist' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permission for reading the visitor's own data (anyone; guests without a cookie see nothing).
	 *
	 * @return true
	 */
	public function can_read() {
		return true;
	}

	/**
	 * A list by the request's id, checked with an Access method.
	 *
	 * @param int    $id    List ID.
	 * @param string $check can_view, can_edit or can_manage.
	 * @return array|WP_Error
	 */
	protected function list_or_error( $id, $check ) {
		$wishlist = Lists::get( (int) $id );
		if ( ! $wishlist || ! Access::can_view( $wishlist ) ) {
			return new WP_Error( 'wishcraft_not_found', __( 'Wishlist not found.', 'wp-smart-wishlist' ), array( 'status' => 404 ) );
		}
		if ( ! call_user_func( array( Access::class, $check ), $wishlist ) ) {
			return new WP_Error( 'wishcraft_forbidden', __( 'You cannot change this wishlist.', 'wp-smart-wishlist' ), array( 'status' => 403 ) );
		}
		return $wishlist;
	}

	/**
	 * Lists the visitor owns or collaborates on.
	 *
	 * @return array[]
	 */
	protected function visitor_lists() {
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
	 * The visitor's default list, created when asked.
	 *
	 * @param bool $create Create it.
	 * @return array|null
	 */
	protected function default_list( $create ) {
		foreach ( $this->visitor_lists() as $wishlist ) {
			if ( $wishlist['is_default'] && Access::owns( $wishlist ) ) {
				return $wishlist;
			}
		}
		if ( ! $create ) {
			return null;
		}
		return Lists::get( Lists::create( Visitor::owner_fields() + array( 'is_default' => 1 ) ) );
	}

	/**
	 * Share URL of a list.
	 *
	 * @param array $wishlist List.
	 * @return string
	 */
	public static function share_url( array $wishlist ) {
		$page = (int) Settings::get( 'page' );
		$base = $page ? get_permalink( $page ) : home_url( '/' );
		return add_query_arg( 'wishlist', $wishlist['share_token'], $base ? $base : home_url( '/' ) );
	}

	/**
	 * A list for the API.
	 *
	 * @param array $wishlist       List.
	 * @param bool  $with_items Include items.
	 * @param bool  $shared     Seen through the share link (no private details).
	 * @return array
	 */
	protected function format_list( array $wishlist, $with_items = false, $shared = false ) {
		$manage = ! $shared && Access::can_manage( $wishlist );
		$out    = array(
			'id'          => $wishlist['id'],
			'title'       => $wishlist['title'],
			'description' => $wishlist['description'],
			'privacy'     => $wishlist['privacy'],
			'is_default'  => $wishlist['is_default'],
			'role'        => $shared ? 'viewer' : Access::role( $wishlist ),
			'count'       => Items::count( $wishlist['id'] ),
			'updated'     => mysql_to_rfc3339( $wishlist['updated'] ),
			'owner_name'  => $wishlist['owner'] ? get_the_author_meta( 'display_name', $wishlist['owner'] ) : '',
		);
		if ( $manage && Settings::get( 'sharing' ) && 'private' !== $wishlist['privacy'] ) {
			$out['share_url'] = self::share_url( $wishlist );
		}
		if ( $with_items ) {
			$out['items'] = array_values( array_filter( array_map( array( $this, 'format_item' ), Items::for_list( $wishlist['id'] ) ) ) );
		}
		/**
		 * Filters a wishlist in REST responses.
		 *
		 * @param array $out    Response data.
		 * @param array $wishlist   List.
		 * @param bool  $shared Seen through the share link.
		 */
		return apply_filters( 'wishcraft_rest_list', $out, $wishlist, $shared );
	}

	/**
	 * An item for the API (null when its post is gone or hidden).
	 *
	 * @param array $item Item.
	 * @return array|null
	 */
	public function format_item( array $item ) {
		$post = get_post( $item['object_id'] );
		if ( ! $post || ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) ) {
			return null;
		}
		$image = get_the_post_thumbnail_url( $post, 'woocommerce_thumbnail' );
		$out   = array(
			'id'           => $item['id'],
			'list_id'      => $item['list_id'],
			'object_id'    => $item['object_id'],
			'variation_id' => $item['variation_id'],
			'quantity'     => $item['quantity'],
			'note'         => $item['note'],
			'added'        => mysql_to_rfc3339( $item['added'] ),
			'title'        => html_entity_decode( get_the_title( $post ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
			'link'         => get_permalink( $post ),
			'image'        => $image ? $image : '',
			'type'         => $post->post_type,
		);
		/**
		 * Filters a wishlist item in REST responses (WooCommerce adds price and stock).
		 *
		 * @param array    $out  Response data.
		 * @param array    $item Item.
		 * @param \WP_Post $post Post.
		 */
		return apply_filters( 'wishcraft_rest_item', $out, $item, $post );
	}
}
