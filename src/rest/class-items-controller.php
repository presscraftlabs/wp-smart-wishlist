<?php
/**
 * Items endpoints.
 *
 * @package WishCraft
 */

namespace WishCraft\Rest;

use WishCraft\Access;
use WishCraft\Data\Items;
use WishCraft\Visitor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * POST /lists/{id}/items, PATCH/DELETE /items/{id}, POST /items/{id}/move, POST /toggle and
 * GET /status (which of the given posts are in the visitor's lists, for the buttons).
 */
final class Items_Controller extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$object = array(
			'object_id'    => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
			'variation_id' => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
		);
		$fields = array(
			'quantity' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 9999,
			),
			'note'     => array(
				'type'      => 'string',
				'maxLength' => 500,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/lists/(?P<id>\d+)/items',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'can_write' ),
				'args'                => $object + $fields,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/items/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_write' ),
					'args'                => $fields + array(
						'position' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
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
			'/items/(?P<id>\d+)/move',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'move' ),
				'permission_callback' => array( $this, 'can_write' ),
				'args'                => array(
					'list_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/toggle',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle' ),
				'permission_callback' => array( $this, 'can_write' ),
				'args'                => $object + array(
					'list_id' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'ids' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'maxItems' => 200,
						'default'  => array(),
					),
				),
			)
		);
	}

	/**
	 * Error for a post that cannot be saved.
	 *
	 * @return WP_Error
	 */
	private function not_allowed() {
		return new WP_Error( 'wishcraft_not_allowed', __( 'This item cannot be added to a wishlist.', 'wp-smart-wishlist' ), array( 'status' => 400 ) );
	}

	/**
	 * Error for a full list.
	 *
	 * @return WP_Error
	 */
	private function full() {
		return new WP_Error( 'wishcraft_list_full', __( 'This wishlist is full.', 'wp-smart-wishlist' ), array( 'status' => 403 ) );
	}

	/**
	 * An item and its list, checked for editing.
	 *
	 * @param int $id Item ID.
	 * @return array|WP_Error [ item, list ]
	 */
	private function item_or_error( $id ) {
		$item = Items::get( (int) $id );
		if ( ! $item ) {
			return new WP_Error( 'wishcraft_not_found', __( 'Item not found.', 'wp-smart-wishlist' ), array( 'status' => 404 ) );
		}
		$wishlist = $this->list_or_error( $item['list_id'], 'can_edit' );
		if ( is_wp_error( $wishlist ) ) {
			return 404 === $wishlist->get_error_data()['status'] ? new WP_Error( 'wishcraft_not_found', __( 'Item not found.', 'wp-smart-wishlist' ), array( 'status' => 404 ) ) : $wishlist;
		}
		return array( $item, $wishlist );
	}

	/**
	 * Add an item to a list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$wishlist = $this->list_or_error( $request['id'], 'can_edit' );
		if ( is_wp_error( $wishlist ) ) {
			return $wishlist;
		}
		if ( ! Items::allowed( $request['object_id'], $request['variation_id'] ) ) {
			return $this->not_allowed();
		}
		$id = Items::add( $wishlist['id'], $request['object_id'], $request['variation_id'], (int) $request->get_param( 'quantity' ), (string) $request->get_param( 'note' ), Visitor::user_id() );
		if ( ! $id ) {
			return $this->full();
		}
		$response = rest_ensure_response( $this->format_item( Items::get( $id ) ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Change quantity, note or position.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$found = $this->item_or_error( $request['id'] );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$fields = array_intersect_key( $request->get_params(), array_flip( array( 'quantity', 'note', 'position' ) ) );
		return rest_ensure_response( $this->format_item( Items::update( $found[0]['id'], $fields ) ) );
	}

	/**
	 * Remove an item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$found = $this->item_or_error( $request['id'] );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		Items::remove( $found[0]['id'] );
		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $found[0]['id'],
			)
		);
	}

	/**
	 * Move an item to another list the visitor can edit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function move( WP_REST_Request $request ) {
		$found = $this->item_or_error( $request['id'] );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$target = $this->list_or_error( $request['list_id'], 'can_edit' );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$item = Items::move( $found[0]['id'], $target['id'] );
		return $item ? rest_ensure_response( $this->format_item( $item ) ) : $this->full();
	}

	/**
	 * Add a post to a list, or remove it when it is already there. Without list_id the visitor's
	 * default list is used (and created).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function toggle( WP_REST_Request $request ) {
		$object    = (int) $request['object_id'];
		$variation = (int) $request['variation_id'];
		if ( $request['list_id'] ) {
			$wishlist = $this->list_or_error( $request['list_id'], 'can_edit' );
			if ( is_wp_error( $wishlist ) ) {
				return $wishlist;
			}
		} else {
			$wishlist = $this->default_list( false );
		}
		$existing = $wishlist ? Items::find( $wishlist['id'], $object, $variation ) : null;
		if ( $existing ) {
			Items::remove( $existing['id'] );
		} else {
			if ( ! Items::allowed( $object, $variation ) ) {
				return $this->not_allowed();
			}
			$wishlist = $wishlist ? $wishlist : $this->default_list( true );
			if ( ! Items::add( $wishlist['id'], $object, $variation, 1, '', Visitor::user_id() ) ) {
				return $this->full();
			}
		}
		$lists = $this->status_for( array( $object ) );
		return rest_ensure_response(
			array(
				'added'     => ! $existing,
				'list_id'   => $wishlist['id'],
				'object_id' => $object,
				'lists'     => isset( $lists[ $object ] ) ? $lists[ $object ] : array(),
				'count'     => $this->total(),
			)
		);
	}

	/**
	 * Which of the given posts are in the visitor's lists.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function status( WP_REST_Request $request ) {
		$ids = array_map( 'intval', (array) $request['ids'] );
		return rest_ensure_response(
			array(
				'items' => (object) $this->status_for( $ids ),
				'count' => $this->total(),
				'lists' => array_map(
					static function ( $wishlist ) {
						return array(
							'id'         => $wishlist['id'],
							'title'      => $wishlist['title'],
							'is_default' => $wishlist['is_default'],
						);
					},
					array_values( array_filter( $this->visitor_lists(), array( Access::class, 'can_edit' ) ) )
				),
			)
		);
	}

	/**
	 * Object ID => list IDs for the visitor's lists.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array
	 */
	private function status_for( array $ids ) {
		return Items::membership( wp_list_pluck( $this->visitor_lists(), 'id' ), $ids );
	}

	/**
	 * Number of items in the lists the visitor owns.
	 *
	 * @return int
	 */
	private function total() {
		$total = 0;
		foreach ( $this->visitor_lists() as $wishlist ) {
			if ( Access::owns( $wishlist ) ) {
				$total += Items::count( $wishlist['id'] );
			}
		}
		return $total;
	}
}
