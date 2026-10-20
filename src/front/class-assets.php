<?php
/**
 * Front-end script and styles.
 *
 * @package WishCraft
 */

namespace WishCraft\Front;

use WishCraft\Rest\Controller;
use WishCraft\Settings;
use WishCraft\Visitor;

/**
 * One small script (no jQuery) enhances the server-rendered buttons, counters and wishlist pages.
 * It is enqueued by whatever renders one of them, so pages without wishlists load nothing.
 */
final class Assets {

	/**
	 * URL of the page being rendered, when it is not the request URL (REST renders).
	 *
	 * @var string
	 */
	private static $url = '';

	/**
	 * Hooks.
	 */
	public static function register() {
		add_action( 'init', array( self::class, 'register_assets' ) );
	}

	/**
	 * Data written by wp-scripts next to each build.
	 *
	 * @param string $name Entry.
	 * @return array { dependencies, version }
	 */
	public static function asset( $name ) {
		$file = WISHCRAFT_DIR . 'build/' . $name . '.asset.php';
		$data = file_exists( $file ) ? require $file : array();
		return array(
			'dependencies' => isset( $data['dependencies'] ) ? $data['dependencies'] : array(),
			'version'      => isset( $data['version'] ) ? $data['version'] : WISHCRAFT_VERSION,
		);
	}

	/**
	 * Register the front-end script and style.
	 */
	public static function register_assets() {
		$asset = self::asset( 'frontend' );
		wp_register_script( 'wishcraft', WISHCRAFT_URL . 'build/frontend.js', $asset['dependencies'], $asset['version'], array( 'strategy' => 'defer' ) );
		wp_set_script_translations( 'wishcraft', 'wp-smart-wishlist' );
		wp_register_style( 'wishcraft', WISHCRAFT_URL . 'build/frontend.css', array(), $asset['version'] );
	}

	/**
	 * Enqueue them, with the visitor's configuration.
	 */
	public static function enqueue() {
		wp_enqueue_style( 'wishcraft' );
		if ( wp_script_is( 'wishcraft', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_script( 'wishcraft' );
		$page   = (int) Settings::get( 'page' );
		$config = array(
			'root'     => esc_url_raw( rest_url( Controller::NAMESPACE . '/' ) ),
			// Logged-in users need the REST nonce; guests send the X-WishCraft header instead.
			'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'loggedIn' => is_user_logged_in(),
			'canSave'  => Visitor::can_save(),
			'multiple' => (bool) Settings::get( 'multiple' ),
			'page'     => $page ? get_permalink( $page ) : '',
			'login'    => wp_login_url( self::current_url() ),
			'labels'   => Button::labels(),
		);
		wp_add_inline_script( 'wishcraft', 'window.wishcraftConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Render links for another page URL ('' to go back to the request URL).
	 *
	 * @param string $url URL.
	 */
	public static function use_url( $url ) {
		self::$url = (string) $url;
	}

	/**
	 * URL of the current page.
	 *
	 * @return string
	 */
	public static function current_url() {
		if ( '' !== self::$url ) {
			return self::$url;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return home_url( $uri );
	}
}
