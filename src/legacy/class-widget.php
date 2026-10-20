<?php
/**
 * The 2.0 "Smart Wish List" widget.
 *
 * @package WishCraft\Legacy
 */

namespace WishCraft\Legacy;

use WishCraft\Front\Assets;
use WishCraft\Front\Page;
use WishCraft\Rest\Lists_Controller;
use WishCraft\Data\Items;
use WishCraft\Settings;
use WishCraft\Wishlists;
use WP_Widget;

/**
 * Same widget ID base as 2.0 (`wishlistwidget`), so widgets placed with 2.0 keep working; it now
 * shows the visitor's default list and links to the wishlist page.
 */
final class Widget extends WP_Widget {

	/**
	 * Widget.
	 */
	public function __construct() {
		parent::__construct(
			'wishlistwidget',
			__( 'Wishlist', 'wp-smart-wishlist' ),
			array(
				'classname'             => 'WishlistWidget wishcraft-widget',
				'description'           => __( 'Items in the visitor\'s wishlist.', 'wp-smart-wishlist' ),
				'show_instance_in_rest' => true,
			)
		);
	}

	/**
	 * Register.
	 */
	public static function register() {
		add_action(
			'widgets_init',
			static function () {
				register_widget( self::class );
			}
		);
	}

	/**
	 * Output.
	 *
	 * @param array $args     Display arguments.
	 * @param array $instance Settings.
	 */
	public function widget( $args, $instance ) {
		Assets::enqueue();
		$title    = isset( $instance['title'] ) ? $instance['title'] : '';
		$limit    = isset( $instance['number'] ) ? max( 1, (int) $instance['number'] ) : 5;
		$wishlist = Wishlists::default_list();
		$items    = array();
		if ( $wishlist ) {
			$controller = new Lists_Controller();
			$items      = array_slice( array_values( array_filter( array_map( array( $controller, 'format_item' ), Items::for_list( $wishlist['id'] ) ) ) ), 0, $limit );
		}
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.
		/** This filter is documented in wp-includes/widgets/class-wp-widget-pages.php */
		$title = apply_filters( 'widget_title', $title, $instance, $this->id_base );
		if ( '' !== trim( (string) $title ) ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.
		}
		if ( ! $items ) {
			echo '<p class="wishcraft-widget__empty">' . esc_html__( 'Your wishlist is empty.', 'wp-smart-wishlist' ) . '</p>';
		} else {
			echo '<ul class="wishcraft-widget__items">';
			foreach ( $items as $item ) {
				printf( '<li><a href="%1$s">%2$s</a></li>', esc_url( $item['link'] ), esc_html( $item['title'] ) );
			}
			echo '</ul>';
		}
		$page = (int) Settings::get( 'page' );
		if ( $page ) {
			echo '<p class="wishcraft-widget__more">' . Page::counter( array( 'show_label' => true ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Page::counter().
		}
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.
	}

	/**
	 * Settings form.
	 *
	 * @param array $instance Settings.
	 * @return string
	 */
	public function form( $instance ) {
		$title  = isset( $instance['title'] ) ? $instance['title'] : '';
		$number = isset( $instance['number'] ) ? (int) $instance['number'] : 5;
		printf(
			'<p><label for="%1$s">%2$s</label><input class="widefat" id="%1$s" name="%3$s" type="text" value="%4$s" /></p>',
			esc_attr( $this->get_field_id( 'title' ) ),
			esc_html__( 'Title:', 'wp-smart-wishlist' ),
			esc_attr( $this->get_field_name( 'title' ) ),
			esc_attr( $title )
		);
		printf(
			'<p><label for="%1$s">%2$s</label> <input class="tiny-text" id="%1$s" name="%3$s" type="number" min="1" max="50" value="%4$d" /></p>',
			esc_attr( $this->get_field_id( 'number' ) ),
			esc_html__( 'Number of items to show:', 'wp-smart-wishlist' ),
			esc_attr( $this->get_field_name( 'number' ) ),
			(int) $number
		);
		return 'form';
	}

	/**
	 * Save settings.
	 *
	 * @param array $new_instance New settings.
	 * @param array $old_instance Old settings.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		unset( $old_instance );
		return array(
			'title'  => sanitize_text_field( isset( $new_instance['title'] ) ? $new_instance['title'] : '' ),
			'number' => max( 1, min( 50, isset( $new_instance['number'] ) ? (int) $new_instance['number'] : 5 ) ),
		);
	}
}
