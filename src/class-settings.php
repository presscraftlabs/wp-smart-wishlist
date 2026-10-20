<?php
/**
 * Site settings, stored in one option.
 *
 * @package WishCraft
 */

namespace WishCraft;

/**
 * Settings are read through get() (defaults merged in) and written through save(), which sanitizes
 * every value against the defaults' types.
 */
final class Settings {

	const OPTION = 'wishcraft_settings';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Post types with a wishlist button. WooCommerce products by default when the shop is
			// active; 2.0 sites used WP eCommerce products.
			'post_types'       => self::default_post_types(),
			'guests'           => true,
			'guest_days'       => 30,
			'multiple'         => true,
			'max_lists'        => 20,
			'max_items'        => 500,
			'sharing'          => true,
			'networks'         => array( 'copy', 'email', 'whatsapp', 'x', 'facebook', 'pinterest' ),
			'collaboration'    => true,
			'page'             => 0,
			'button_text'      => '',
			'button_added'     => '',
			'product_position' => 'after_cart',
			'loop_position'    => 'after_cart',
			'remove_on_cart'   => false,
		);
	}

	/**
	 * Post types enabled by default.
	 *
	 * @return string[]
	 */
	private static function default_post_types() {
		$types = array();
		if ( post_type_exists( 'product' ) || class_exists( 'WooCommerce' ) ) {
			$types[] = 'product';
		}
		if ( post_type_exists( 'wpsc-product' ) ) {
			$types[] = 'wpsc-product';
		}
		return $types ? $types : array( 'post' );
	}

	/**
	 * All settings, or one.
	 *
	 * @param string $key Key (optional).
	 * @return mixed
	 */
	public static function get( $key = '' ) {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $stored ) ? $stored : array() );
		}
		if ( '' === $key ) {
			return self::$cache;
		}
		return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
	}

	/**
	 * Save settings.
	 *
	 * @param array $settings Any subset.
	 * @return array Saved settings.
	 */
	public static function save( array $settings ) {
		$clean = self::sanitize( array_merge( self::get(), $settings ) );
		update_option( self::OPTION, $clean );
		self::$cache = null;
		return self::get();
	}

	/**
	 * Forget the cached copy.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanitize against the defaults.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function sanitize( array $settings ) {
		$out = array();
		foreach ( self::defaults() as $key => $default ) {
			$value = array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
			switch ( $key ) {
				case 'post_types':
					$value = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $value ) ) ) );
					break;
				case 'networks':
					$value = array_values( array_intersect( array_map( 'strval', (array) $value ), array( 'copy', 'email', 'whatsapp', 'x', 'facebook', 'pinterest' ) ) );
					break;
				case 'product_position':
				case 'loop_position':
					$value = in_array( $value, array( 'after_cart', 'before_cart', 'after_summary', 'none' ), true ) ? $value : $default;
					break;
				case 'guest_days':
					$value = max( 1, min( 365, (int) $value ) );
					break;
				case 'max_lists':
					$value = max( 1, min( 100, (int) $value ) );
					break;
				case 'max_items':
					$value = max( 10, min( 2000, (int) $value ) );
					break;
				case 'page':
					$value = absint( $value );
					break;
				case 'button_text':
				case 'button_added':
					$value = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
					break;
				default:
					$value = (bool) rest_sanitize_boolean( $value );
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Whether a post type has wishlists.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function supports( $post_type ) {
		/**
		 * Filters whether a post type can be added to wishlists.
		 *
		 * @param bool   $supported Supported.
		 * @param string $post_type Post type.
		 */
		return (bool) apply_filters( 'wishcraft_supports_post_type', in_array( $post_type, self::get( 'post_types' ), true ), $post_type );
	}
}
