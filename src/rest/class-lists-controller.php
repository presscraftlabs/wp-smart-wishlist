<?php
/**
 * Lists endpoints.
 *
 * @package WishCraft
 */

namespace WishCraft\Rest;

use WishCraft\Access;
use WishCraft\Data\Lists;
use WishCraft\Settings;
use WishCraft\Visitor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * GET/POST /lists, GET/PATCH/DELETE /lists/{id}, POST /lists/{id}/share, GET /shared/{token}.
 */
final class Lists_Controller extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$fields = array(
			'title'       => array(
				'type'      => 'string',
				'maxLength' => 190,
			),
			'description' => array( 'type' => 'string' ),
			'privacy'     => array(
				'type' => 'string',
				'enum' => Lists::PRIVACY,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/lists',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_write' ),
					'args'                => $fields,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/lists/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_write' ),
					'args'                => $fields,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'can_write' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/lists/(?P<id>\d+)/share',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rotate' ),
				'permission_callback' => array( $this, 'can_write' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/shared/(?P<token>[a-f0-9]{32})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'shared' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The visitor's lists.
	 *
	 * @return array
	 */
	public function index() {
		$lists = array_map( array( $this, 'format_list' ), $this->visitor_lists() );
		return rest_ensure_response( $lists );
	}

	/**
	 * Create a list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		if ( ! Settings::get( 'multiple' ) ) {
			return new WP_Error( 'wishcraft_single_list', __( 'This site has one wishlist per visitor.', 'wp-smart-wishlist' ), array( 'status' => 403 ) );
		}
		$owned = array_filter( $this->visitor_lists(), array( Access::class, 'owns' ) );
		if ( count( $owned ) >= Settings::get( 'max_lists' ) ) {
			return new WP_Error( 'wishcraft_too_many_lists', __( 'You have reached the most wishlists you can keep.', 'wp-smart-wishlist' ), array( 'status' => 403 ) );
		}
		$fields = array_intersect_key( $request->get_params(), array_flip( array( 'title', 'description', 'privacy' ) ) );
		if ( ! Settings::get( 'sharing' ) ) {
			unset( $fields['privacy'] );
		}
		// The first list is the default one.
		$fields['is_default'] = $owned ? 0 : 1;
		$wishlist             = Lists::get( Lists::create( Visitor::owner_fields() + $fields ) );
		$response             = rest_ensure_response( $this->format_list( $wishlist, true ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * One list with its items.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ) {
		$wishlist = $this->list_or_error( $request['id'], 'can_view' );
		if ( is_wp_error( $wishlist ) ) {
			return $wishlist;
		}
		return rest_ensure_response( $this->format_list( $wishlist, true ) );
	}

	/**
	 * Change title, description or privacy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$wishlist = $this->list_or_error( $request['id'], 'can_manage' );
		if ( is_wp_error( $wishlist ) ) {
			return $wishlist;
		}
		$fields = array_intersect_key( $request->get_params(), array_flip( array( 'title', 'description', 'privacy' ) ) );
		if ( ! Settings::get( 'sharing' ) ) {
			unset( $fields['privacy'] );
		}
		return rest_ensure_response( $this->format_list( Lists::update( $wishlist['id'], $fields ), true ) );
	}

	/**
	 * Delete a list. Deleting the default list makes the oldest remaining list the default.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$wishlist = $this->list_or_error( $request['id'], 'can_manage' );
		if ( is_wp_error( $wishlist ) ) {
			return $wishlist;
		}
		Lists::delete( $wishlist['id'] );
		if ( $wishlist['is_default'] ) {
			foreach ( $this->visitor_lists() as $other ) {
				if ( Access::owns( $other ) ) {
					Lists::update( $other['id'], array( 'is_default' => 1 ) );
					break;
				}
			}
		}
		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $wishlist['id'],
			)
		);
	}

	/**
	 * New share link (the old one stops working).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function rotate( WP_REST_Request $request ) {
		$wishlist = $this->list_or_error( $request['id'], 'can_manage' );
		if ( is_wp_error( $wishlist ) ) {
			return $wishlist;
		}
		if ( ! Settings::get( 'sharing' ) ) {
			return new WP_Error( 'wishcraft_sharing_off', __( 'Sharing is turned off on this site.', 'wp-smart-wishlist' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( $this->format_list( Lists::update( $wishlist['id'], array( 'share_token' => true ) ) ) );
	}

	/**
	 * A list seen through its share link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function shared( WP_REST_Request $request ) {
		$wishlist = Lists::by_token( $request['token'] );
		if ( ! $wishlist || ! Access::can_view_shared( $wishlist ) ) {
			return new WP_Error( 'wishcraft_not_found', __( 'Wishlist not found.', 'wp-smart-wishlist' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->format_list( $wishlist, true, ! Access::can_edit( $wishlist ) ) );
	}
}
