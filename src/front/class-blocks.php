<?php
/**
 * Blocks and shortcodes.
 *
 * @package WishCraft
 */

namespace WishCraft\Front;

use WP_Block;

/**
 * Each block has a shortcode with the same output, for classic themes and page builders:
 * [wishcraft], [wishcraft_button id="" label="yes"] and [wishcraft_count label="no"].
 */
final class Blocks {

	/**
	 * Hooks.
	 */
	public static function register() {
		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_shortcode( 'wishcraft', array( self::class, 'wishlist_shortcode' ) );
		add_shortcode( 'wishcraft_button', array( self::class, 'button_shortcode' ) );
		add_shortcode( 'wishcraft_count', array( self::class, 'counter_shortcode' ) );
	}

	/**
	 * Register the blocks and their editor script.
	 */
	public static function register_blocks() {
		$asset = Assets::asset( 'blocks' );
		wp_register_script( 'wishcraft-blocks', WISHCRAFT_URL . 'build/blocks.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'wishcraft-blocks', 'wp-smart-wishlist' );
		register_block_type( WISHCRAFT_DIR . 'blocks/button', array( 'render_callback' => array( self::class, 'button' ) ) );
		register_block_type( WISHCRAFT_DIR . 'blocks/wishlist', array( 'render_callback' => array( self::class, 'wishlist' ) ) );
		register_block_type( WISHCRAFT_DIR . 'blocks/counter', array( 'render_callback' => array( self::class, 'counter' ) ) );
	}

	/**
	 * Wrapper attributes for a block (plain attributes outside a block render, e.g. WP 6.5 tests).
	 *
	 * @param array $extra Extra attributes.
	 * @return string
	 */
	private static function wrapper( array $extra = array() ) {
		if ( class_exists( 'WP_Block_Supports' ) && ! empty( \WP_Block_Supports::$block_to_render ) ) {
			return get_block_wrapper_attributes( $extra );
		}
		$out = '';
		foreach ( $extra as $name => $value ) {
			$out .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}
		return trim( $out );
	}

	/**
	 * Button block.
	 *
	 * @param array    $attributes Attributes.
	 * @param string   $content    Content.
	 * @param WP_Block $block      Block.
	 * @return string
	 */
	public static function button( $attributes, $content = '', $block = null ) {
		$post_id = $block instanceof WP_Block && ! empty( $block->context['postId'] ) ? (int) $block->context['postId'] : get_the_ID();
		$html    = Button::render(
			$post_id,
			array(
				'show_label' => ! isset( $attributes['showLabel'] ) || $attributes['showLabel'],
				'context'    => 'block',
			)
		);
		return '' === $html ? '' : '<div ' . self::wrapper( array( 'class' => 'wishcraft-button-wrap' ) ) . '>' . $html . '</div>';
	}

	/**
	 * Wishlist block.
	 *
	 * @return string
	 */
	public static function wishlist() {
		return '<div ' . self::wrapper() . '>' . Page::render_current() . '</div>';
	}

	/**
	 * Counter block.
	 *
	 * @param array $attributes Attributes.
	 * @return string
	 */
	public static function counter( $attributes ) {
		return '<div ' . self::wrapper() . '>' . Page::counter( array( 'show_label' => ! empty( $attributes['showLabel'] ) ) ) . '</div>';
	}

	/**
	 * [wishcraft]
	 *
	 * @return string
	 */
	public static function wishlist_shortcode() {
		return Page::render_current();
	}

	/**
	 * [wishcraft_button id="" label="yes"]
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function button_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'label' => 'yes',
			),
			$atts,
			'wishcraft_button'
		);
		return Button::render(
			$atts['id'] ? absint( $atts['id'] ) : get_the_ID(),
			array( 'show_label' => 'no' !== $atts['label'] )
		);
	}

	/**
	 * [wishcraft_count label="no"]
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function counter_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'label' => 'no' ), $atts, 'wishcraft_count' );
		return Page::counter( array( 'show_label' => 'yes' === $atts['label'] ) );
	}
}
