<?php
/**
 * The wishlist (heart) button.
 *
 * @package WishCraft
 */

namespace WishCraft\Front;

use WishCraft\Data\Items;
use WishCraft\Settings;
use WishCraft\Visitor;
use WishCraft\Wishlists;

/**
 * The button is a link to a nonce-checked handler, so it works without JavaScript; the script
 * turns it into a toggle (and a list picker when the visitor has several lists).
 */
final class Button {

	/**
	 * Hooks.
	 */
	public static function register() {
		add_filter( 'the_content', array( self::class, 'content' ), 20 );
	}

	/**
	 * Button labels.
	 *
	 * @return array { add, added }
	 */
	public static function labels() {
		$add   = Settings::get( 'button_text' );
		$added = Settings::get( 'button_added' );
		return array(
			'add'   => '' !== $add ? $add : __( 'Add to wishlist', 'wp-smart-wishlist' ),
			'added' => '' !== $added ? $added : __( 'In your wishlist', 'wp-smart-wishlist' ),
		);
	}

	/**
	 * No-JavaScript toggle URL.
	 *
	 * @param int $object_id    Post ID.
	 * @param int $variation_id Variation ID.
	 * @return string
	 */
	public static function toggle_url( $object_id, $variation_id = 0 ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'       => Handler::TOGGLE,
					'object_id'    => (int) $object_id,
					'variation_id' => (int) $variation_id,
					'redirect_to'  => rawurlencode( Assets::current_url() ),
				),
				admin_url( 'admin-post.php' )
			),
			Handler::TOGGLE
		);
	}

	/**
	 * Render a button.
	 *
	 * @param int   $object_id Post ID.
	 * @param array $args      { show_label: bool, context: single|loop|block, class: string }.
	 * @return string
	 */
	public static function render( $object_id, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'show_label' => true,
				'context'    => 'single',
				'class'      => '',
			)
		);
		$post = get_post( $object_id );
		if ( ! $post || ! Items::allowed( $post->ID ) ) {
			return '';
		}
		Assets::enqueue();
		$labels = self::labels();
		$saved  = Wishlists::has( $post->ID );
		$href   = Visitor::can_save() ? self::toggle_url( $post->ID ) : wp_login_url( get_permalink( $post ) );
		$html   = sprintf(
			'<a href="%1$s" class="%2$s" role="button" aria-pressed="%3$s" data-wishcraft-button data-object-id="%4$d" data-context="%5$s" rel="nofollow">%6$s<span class="wishcraft-button__label%7$s">%8$s</span></a>',
			esc_url( $href ),
			esc_attr( trim( 'wishcraft-button ' . ( $saved ? 'is-saved ' : '' ) . ( $args['show_label'] ? '' : 'wishcraft-button--icon ' ) . $args['class'] ) ),
			$saved ? 'true' : 'false',
			$post->ID,
			esc_attr( $args['context'] ),
			Icons::get( 'heart' ),
			$args['show_label'] ? '' : ' screen-reader-text',
			esc_html( $saved ? $labels['added'] : $labels['add'] )
		);
		/**
		 * Filters the wishlist button.
		 *
		 * @param string $html      HTML.
		 * @param int    $object_id Post ID.
		 * @param array  $args      Arguments.
		 */
		return apply_filters( 'wishcraft_button_html', $html, $post->ID, $args );
	}

	/**
	 * Automatic button on single posts of enabled post types (not WooCommerce products, which
	 * have their own positions).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function content( $content ) {
		$position = Settings::get( 'content_position' );
		if ( 'none' === $position || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || 'product' === $post->post_type || (int) Settings::get( 'page' ) === $post->ID || ! Settings::supports( $post->post_type ) ) {
			return $content;
		}
		$button = '<div class="wishcraft-button-wrap">' . self::render( $post->ID ) . '</div>';
		return 'before' === $position ? $button . $content : $content . $button;
	}
}
