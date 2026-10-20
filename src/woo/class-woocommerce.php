<?php
/**
 * WooCommerce integration.
 *
 * @package WishCraft
 */

namespace WishCraft\Woo;

use WishCraft\Access;
use WishCraft\Data\Items;
use WishCraft\Data\Lists;
use WishCraft\Front\Button;
use WishCraft\Settings;
use WishCraft\Wishlists;

/**
 * Buttons on product pages and product cards (classic templates and WooCommerce blocks), price
 * and stock in wishlists, and adding wishlist items to the cart.
 */
final class WooCommerce {

	const CART = 'wishcraft-cart';

	/**
	 * Products that already have a button, per context.
	 *
	 * @var array
	 */
	private static $rendered = array();

	/**
	 * Single product cart blocks being rendered (their classic hooks run inside them).
	 *
	 * @var int
	 */
	private static $in_block = 0;

	const SINGLE_BLOCKS = array( 'woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options' );

	/**
	 * Hooks (WooCommerce loads after this plugin).
	 */
	public static function register() {
		add_action( 'before_woocommerce_init', array( self::class, 'compatibility' ) );
		add_action( 'plugins_loaded', array( self::class, 'hooks' ) );
	}

	/**
	 * Declare support for order storage tables and the cart and checkout blocks.
	 */
	public static function compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WISHCRAFT_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WISHCRAFT_FILE, true );
		}
	}

	/**
	 * WooCommerce hooks, when the shop is active.
	 */
	public static function hooks() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_action( 'wp', array( self::class, 'positions' ) );
		add_filter( 'pre_render_block', array( self::class, 'entering_block' ), 10, 2 );
		foreach ( self::SINGLE_BLOCKS as $name ) {
			add_filter( 'render_block_' . $name, array( self::class, 'single_block' ), 10, 2 );
		}
		add_filter( 'render_block_woocommerce/product-button', array( self::class, 'loop_block' ), 10, 3 );
		add_filter( 'wishcraft_rest_item', array( self::class, 'item' ), 10, 3 );
		add_filter( 'wishcraft_item_actions', array( self::class, 'item_actions' ), 10, 2 );
		add_filter( 'wishcraft_list_footer', array( self::class, 'list_footer' ), 10, 4 );
		add_action( 'wp_loaded', array( self::class, 'add_list_to_cart' ), 25 );
		add_action( 'woocommerce_add_to_cart', array( self::class, 'added_to_cart' ), 10, 6 );
	}

	/**
	 * Classic template positions from the settings.
	 */
	public static function positions() {
		$single = Settings::get( 'product_position' );
		if ( 'after_cart' === $single ) {
			add_action( 'woocommerce_after_add_to_cart_form', array( self::class, 'single' ) );
		} elseif ( 'before_cart' === $single ) {
			add_action( 'woocommerce_before_add_to_cart_form', array( self::class, 'single' ) );
		}
		if ( 'after_summary' === $single ) {
			add_action( 'woocommerce_single_product_summary', array( self::class, 'single' ), 35 );
		} elseif ( 'none' !== $single && ! wp_is_block_theme() ) {
			// Classic templates skip the cart form hooks for some products (external, out of
			// stock). Block themes fire these hooks around blocks, so the cart block places it.
			add_action( 'woocommerce_single_product_summary', array( self::class, 'single' ), 31 );
		}
		$loop = Settings::get( 'loop_position' );
		$hook = array(
			'after_cart'    => array( 'woocommerce_after_shop_loop_item', 15 ),
			'before_cart'   => array( 'woocommerce_after_shop_loop_item', 9 ),
			'after_summary' => array( 'woocommerce_after_shop_loop_item_title', 15 ),
		);
		if ( isset( $hook[ $loop ] ) ) {
			add_action( $hook[ $loop ][0], array( self::class, 'loop' ), $hook[ $loop ][1] );
		}
	}

	/**
	 * Button once per product and context.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $context    single or loop.
	 * @param string $align      Text alignment of the block it follows.
	 * @return string
	 */
	private static function button( $product_id, $context, $align = '' ) {
		if ( ! $product_id || isset( self::$rendered[ $context ][ $product_id ] ) ) {
			return '';
		}
		self::$rendered[ $context ][ $product_id ] = true;
		$html                                      = Button::render(
			$product_id,
			array(
				'context'    => $context,
				'show_label' => 'single' === $context,
			)
		);
		$class                                     = 'wishcraft-button-wrap wishcraft-button-wrap--' . $context . ( $align ? ' has-text-align-' . $align : '' );
		return '' === $html ? '' : '<div class="' . esc_attr( $class ) . '">' . $html . '</div>';
	}

	/**
	 * Forget rendered buttons (tests).
	 */
	public static function reset() {
		self::$rendered = array();
	}

	/**
	 * Note when a single product cart block starts rendering.
	 *
	 * @param string|null $pre   Short-circuit output.
	 * @param array       $block Block.
	 * @return string|null
	 */
	public static function entering_block( $pre, $block ) {
		if ( null === $pre && in_array( $block['blockName'], self::SINGLE_BLOCKS, true ) ) {
			++self::$in_block;
		}
		return $pre;
	}

	/**
	 * Single product page (classic hooks). Inside cart blocks, single_block() places the button.
	 */
	public static function single() {
		if ( self::$in_block > 0 ) {
			return;
		}
		echo self::button( get_the_ID(), 'single' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Button::render().
	}

	/**
	 * Product cards (classic hooks).
	 */
	public static function loop() {
		echo self::button( get_the_ID(), 'loop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Button::render().
	}

	/**
	 * Single product block templates.
	 *
	 * @param string $html  Block HTML.
	 * @param array  $block Block.
	 * @return string
	 */
	public static function single_block( $html, $block ) {
		self::$in_block = max( 0, self::$in_block - 1 );
		$position       = Settings::get( 'product_position' );
		if ( 'none' === $position || 'after_summary' === $position ) {
			return $html;
		}
		$button = self::button( get_the_ID(), 'single' );
		unset( $block );
		return 'before_cart' === $position ? $button . $html : $html . $button;
	}

	/**
	 * Product card blocks (Product Collection, All Products).
	 *
	 * @param string    $html     Block HTML.
	 * @param array     $block    Block.
	 * @param \WP_Block $instance Block instance.
	 * @return string
	 */
	public static function loop_block( $html, $block, $instance = null ) {
		$position = Settings::get( 'loop_position' );
		if ( 'none' === $position ) {
			return $html;
		}
		$id     = $instance instanceof \WP_Block && ! empty( $instance->context['postId'] ) ? (int) $instance->context['postId'] : get_the_ID();
		$align  = isset( $block['attrs']['textAlign'] ) ? sanitize_html_class( $block['attrs']['textAlign'] ) : '';
		$button = self::button( $id, 'loop', $align );
		return 'before_cart' === $position ? $button . $html : $html . $button;
	}

	/**
	 * Price, stock and cart data for an item.
	 *
	 * @param array    $out  REST data.
	 * @param array    $item Item.
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public static function item( $out, $item, $post ) {
		if ( 'product' !== $post->post_type ) {
			return $out;
		}
		$product = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $post->ID );
		if ( ! $product ) {
			return $out;
		}
		$out['price_html']   = $product->get_price_html();
		$out['stock_status'] = $product->get_stock_status();
		$out['stock']        = $product->is_in_stock() ? '' : __( 'Out of stock', 'wp-smart-wishlist' );
		$out['purchasable']  = $product->is_purchasable() && $product->is_in_stock() && ! $product->is_type( 'variable' ) && ! $product->is_type( 'grouped' );
		$out['cart_url']     = $out['purchasable'] && ! $product->is_type( 'external' ) ? self::cart_url( $product, $item['quantity'] ) : '';
		$out['product_url']  = $product->is_type( 'external' ) ? $product->get_product_url() : '';
		if ( $product->is_type( 'variation' ) ) {
			$out['variation'] = wc_get_formatted_variation( $product, true, false, false );
			$image            = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' );
			$out['image']     = $image ? $image : $out['image'];
			$out['link']      = $product->get_permalink();
		}
		return $out;
	}

	/**
	 * WooCommerce's own add-to-cart link for one product.
	 *
	 * @param \WC_Product $product  Product or variation.
	 * @param int         $quantity Quantity.
	 * @return string
	 */
	private static function cart_url( $product, $quantity ) {
		$args = array(
			'add-to-cart' => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
			'quantity'    => max( 1, (int) $quantity ),
		);
		if ( $product->is_type( 'variation' ) ) {
			$args['variation_id'] = $product->get_id();
			foreach ( $product->get_variation_attributes() as $name => $value ) {
				$args[ $name ] = $value;
			}
		}
		return add_query_arg( array_map( 'rawurlencode', $args ), wc_get_cart_url() );
	}

	/**
	 * "Add to cart" on an item.
	 *
	 * @param string $html HTML.
	 * @param array  $item Item (REST format).
	 * @return string
	 */
	public static function item_actions( $html, $item ) {
		if ( 'product' !== $item['type'] ) {
			return $html;
		}
		if ( ! empty( $item['cart_url'] ) ) {
			return $html . '<a class="wishcraft-primary wishcraft-item__cart" href="' . esc_url( $item['cart_url'] ) . '" rel="nofollow">' . esc_html__( 'Add to cart', 'wp-smart-wishlist' ) . '</a>';
		}
		if ( ! empty( $item['product_url'] ) ) {
			return $html . '<a class="wishcraft-primary wishcraft-item__cart" href="' . esc_url( $item['product_url'] ) . '" rel="nofollow noopener">' . esc_html__( 'Buy product', 'wp-smart-wishlist' ) . '</a>';
		}
		if ( 'instock' === ( isset( $item['stock_status'] ) ? $item['stock_status'] : '' ) ) {
			return $html . '<a class="wishcraft-secondary wishcraft-item__cart" href="' . esc_url( $item['link'] ) . '">' . esc_html__( 'Choose options', 'wp-smart-wishlist' ) . '</a>';
		}
		return $html;
	}

	/**
	 * "Add all to cart" under a list.
	 *
	 * @param string  $html     HTML.
	 * @param array   $wishlist List.
	 * @param array[] $items    Items.
	 * @param string  $token    Share token (shared view).
	 * @return string
	 */
	public static function list_footer( $html, $wishlist, $items, $token ) {
		$buyable = array_filter( wp_list_pluck( $items, 'cart_url' ) );
		if ( count( $buyable ) < 2 ) {
			return $html;
		}
		$url = wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						self::CART => $wishlist['id'],
						'token'    => $token,
					)
				),
				wc_get_cart_url()
			),
			self::CART
		);
		return $html . '<div class="wishcraft-list__footer"><a class="wishcraft-primary" href="' . esc_url( $url ) . '" rel="nofollow">' . esc_html__( 'Add all to cart', 'wp-smart-wishlist' ) . '</a></div>';
	}

	/**
	 * Handle "Add all to cart": every purchasable item, with its quantity.
	 */
	public static function add_list_to_cart() {
		if ( ! isset( $_GET[ self::CART ] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		check_admin_referer( self::CART );
		$wishlist = Lists::get( absint( $_GET[ self::CART ] ) );
		$token    = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : '';
		$allowed  = $wishlist && ( Access::can_view( $wishlist ) || ( '' !== $token && hash_equals( $wishlist['share_token'], $token ) && Access::can_view_shared( $wishlist ) ) );
		if ( ! $allowed ) {
			wc_add_notice( __( 'Wishlist not found.', 'wp-smart-wishlist' ), 'error' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
		$added = self::add_items( Items::for_list( $wishlist['id'] ), Access::owns( $wishlist ) );
		if ( $added ) {
			/* translators: %d: number of products. */
			wc_add_notice( sprintf( _n( '%d product from the wishlist was added to your cart.', '%d products from the wishlist were added to your cart.', $added, 'wp-smart-wishlist' ), $added ) );
		}
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Add items to the cart.
	 *
	 * @param array[] $items Items.
	 * @param bool    $own   Items of the visitor's own list (removed when the setting says so).
	 * @return int Products added.
	 */
	public static function add_items( array $items, $own ) {
		$added            = 0;
		self::$adding_own = $own;
		foreach ( $items as $item ) {
			$product = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['object_id'] );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() || $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
				continue;
			}
			$attributes = $product->is_type( 'variation' ) ? $product->get_variation_attributes() : array();
			$parent     = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			if ( WC()->cart->add_to_cart( $parent, $item['quantity'], $item['variation_id'], $attributes ) ) {
				++$added;
			}
		}
		self::$adding_own = true;
		return $added;
	}

	/**
	 * Whether products being added come from the visitor's own list.
	 *
	 * @var bool
	 */
	private static $adding_own = true;

	/**
	 * Remove a product from the visitor's own lists once it is in the cart, when enabled.
	 *
	 * @param string $key          Cart item key.
	 * @param int    $product_id   Product ID.
	 * @param int    $quantity     Quantity.
	 * @param int    $variation_id Variation ID.
	 */
	public static function added_to_cart( $key, $product_id, $quantity = 1, $variation_id = 0 ) {
		if ( ! Settings::get( 'remove_on_cart' ) || ! self::$adding_own ) {
			return;
		}
		foreach ( Wishlists::owned() as $wishlist ) {
			foreach ( array( (int) $variation_id, 0 ) as $variation ) {
				$item = Items::find( $wishlist['id'], (int) $product_id, $variation );
				if ( $item ) {
					Items::remove( $item['id'] );
				}
			}
		}
		unset( $key, $quantity );
	}
}
