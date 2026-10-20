<?php
/**
 * Rendered HTML for the script.
 *
 * @package WishCraft
 */

namespace WishCraft\Rest;

use WishCraft\Front\Assets;
use WishCraft\Front\Page;
use WP_REST_Request;
use WP_REST_Server;

/**
 * GET /view returns the wishlist page markup after a change, so the page has one renderer.
 */
final class View_Controller extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/view',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'page' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'list'  => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'token' => array(
						'type'    => 'string',
						'pattern' => '^([a-f0-9]{32})?$',
						'default' => '',
					),
					'url'   => array(
						'type'    => 'string',
						'format'  => 'uri',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Page markup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function page( WP_REST_Request $request ) {
		$url = wp_validate_redirect( esc_url_raw( (string) $request['url'] ), home_url( '/' ) );
		Assets::use_url( $url );
		$html = Page::render(
			array(
				'list'  => (int) $request['list'],
				'token' => (string) $request['token'],
				'url'   => $url,
			)
		);
		Assets::use_url( '' );
		return rest_ensure_response( array( 'html' => $html ) );
	}
}
