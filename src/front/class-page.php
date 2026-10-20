<?php
/**
 * The wishlist page and the shared view.
 *
 * @package WishCraft
 */

namespace WishCraft\Front;

use WishCraft\Access;
use WishCraft\Data\Items;
use WishCraft\Data\Lists;
use WishCraft\Rest\Controller;
use WishCraft\Rest\Lists_Controller;
use WishCraft\Settings;
use WishCraft\Visitor;
use WishCraft\Wishlists;

/**
 * Rendered on the server only: the script runs actions through the REST API and then asks for
 * the new HTML (GET /view), so the page looks the same with and without JavaScript.
 */
final class Page {

	/**
	 * Render the page for the current request (?list=ID, or ?wishlist=token for a shared list).
	 *
	 * @return string
	 */
	public static function render_current() {
		// Read-only query arguments of a public page.
		$token = isset( $_GET['wishlist'] ) ? sanitize_key( wp_unslash( $_GET['wishlist'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list  = isset( $_GET['list'] ) ? absint( $_GET['list'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return self::render(
			array(
				'list'  => $list,
				'token' => $token,
				'url'   => Assets::current_url(),
			)
		);
	}

	/**
	 * Render.
	 *
	 * @param array $args { list: int, token: string, url: page URL for links }.
	 * @return string
	 */
	public static function render( array $args ) {
		Assets::enqueue();
		$args = wp_parse_args(
			$args,
			array(
				'list'  => 0,
				'token' => '',
				'url'   => home_url( '/' ),
			)
		);
		$base = remove_query_arg( array( 'list', 'wishlist' ), $args['url'] );
		if ( '' !== $args['token'] ) {
			$wishlist = Lists::by_token( $args['token'] );
			if ( ! $wishlist || ! Access::can_view_shared( $wishlist ) ) {
				return self::wrap( '<p class="wishcraft-notice">' . esc_html__( 'This wishlist is not available. It may be private, or its link may have changed.', 'wp-smart-wishlist' ) . '</p>', $args );
			}
			if ( ! Access::can_edit( $wishlist ) ) {
				return self::wrap( self::shared( $wishlist, $base ), $args );
			}
			$args['list'] = $wishlist['id'];
		}
		if ( ! Visitor::can_save() ) {
			return self::wrap(
				'<p class="wishcraft-notice">' . sprintf(
					/* translators: %s: login URL. */
					wp_kses_post( __( '<a href="%s">Log in</a> to keep a wishlist.', 'wp-smart-wishlist' ) ),
					esc_url( wp_login_url( $args['url'] ) )
				) . '</p>',
				$args
			);
		}
		$lists   = Wishlists::visitor_lists();
		$current = null;
		foreach ( $lists as $candidate ) {
			if ( $candidate['id'] === (int) $args['list'] || ( ! $args['list'] && ! $current && Access::owns( $candidate ) && $candidate['is_default'] ) ) {
				$current = $candidate;
			}
		}
		if ( ! $current && $lists ) {
			$current = $lists[0];
		}
		$html         = self::tabs( $lists, $current, $base );
		$html        .= $current ? self::list_view( $current, $lists, $base ) : self::empty_state( __( 'Your wishlist is empty.', 'wp-smart-wishlist' ) );
		$html        .= self::list_dialog();
		$args['list'] = $current ? $current['id'] : 0;
		return self::wrap( $html, $args );
	}

	/**
	 * Outer element, read by the script.
	 *
	 * @param string $html Inner HTML.
	 * @param array  $args Arguments.
	 * @return string
	 */
	private static function wrap( $html, array $args ) {
		return sprintf(
			'<div class="wishcraft-page" data-wishcraft-page data-list="%1$d" data-token="%2$s" aria-live="polite">%3$s</div>',
			(int) $args['list'],
			esc_attr( $args['token'] ),
			$html
		);
	}

	/**
	 * URL of a list on the page.
	 *
	 * @param string $base     Page URL.
	 * @param array  $wishlist List.
	 * @return string
	 */
	private static function list_url( $base, array $wishlist ) {
		return add_query_arg( 'list', $wishlist['id'], $base );
	}

	/**
	 * Tabs for the visitor's lists, and "New list".
	 *
	 * @param array[]    $lists   Lists.
	 * @param array|null $current Current list.
	 * @param string     $base    Page URL.
	 * @return string
	 */
	private static function tabs( array $lists, $current, $base ) {
		$multiple = Settings::get( 'multiple' );
		if ( count( $lists ) < 2 && ! $multiple ) {
			return '';
		}
		$html = '<nav class="wishcraft-tabs" aria-label="' . esc_attr__( 'Your wishlists', 'wp-smart-wishlist' ) . '"><ul>';
		foreach ( $lists as $wishlist ) {
			$is    = $current && $current['id'] === $wishlist['id'];
			$html .= sprintf(
				'<li><a href="%1$s" class="wishcraft-tab%2$s"%3$s>%4$s <span class="wishcraft-tab__count">%5$d</span>%6$s</a></li>',
				esc_url( self::list_url( $base, $wishlist ) ),
				$is ? ' is-current' : '',
				$is ? ' aria-current="page"' : '',
				esc_html( $wishlist['title'] ),
				Items::count( $wishlist['id'] ),
				Access::owns( $wishlist ) ? '' : ' <span class="wishcraft-tab__shared">' . esc_html__( '(shared with you)', 'wp-smart-wishlist' ) . '</span>'
			);
		}
		$html .= '</ul>';
		if ( $multiple && count( Wishlists::owned() ) < Settings::get( 'max_lists' ) ) {
			$html .= '<button type="button" class="wishcraft-link wishcraft-new-list" data-wishcraft-action="new-list" hidden>' . Icons::get( 'plus' ) . esc_html__( 'New list', 'wp-smart-wishlist' ) . '</button>';
		}
		return $html . '</nav>';
	}

	/**
	 * One list with its items, for people who can change it.
	 *
	 * @param array   $wishlist List.
	 * @param array[] $lists    All the visitor's lists.
	 * @param string  $base     Page URL.
	 * @return string
	 */
	private static function list_view( array $wishlist, array $lists, $base ) {
		$controller = new Lists_Controller();
		$items      = array_values( array_filter( array_map( array( $controller, 'format_item' ), Items::for_list( $wishlist['id'] ) ) ) );
		$manage     = Access::can_manage( $wishlist );
		$targets    = array_values(
			array_filter(
				$lists,
				static function ( $other ) use ( $wishlist ) {
					return $other['id'] !== $wishlist['id'] && Access::can_edit( $other );
				}
			)
		);
		$url        = self::list_url( $base, $wishlist );

		$html  = '<section class="wishcraft-list" data-list-id="' . (int) $wishlist['id'] . '">';
		$html .= '<header class="wishcraft-list__header"><div>';
		$html .= '<h2 class="wishcraft-list__title">' . esc_html( $wishlist['title'] ) . '</h2>';
		if ( '' !== $wishlist['description'] ) {
			$html .= '<p class="wishcraft-list__description">' . esc_html( $wishlist['description'] ) . '</p>';
		}
		if ( ! Access::owns( $wishlist ) && $wishlist['owner'] ) {
			/* translators: %s: name of the list owner. */
			$html .= '<p class="wishcraft-list__owner">' . esc_html( sprintf( __( 'Shared with you by %s', 'wp-smart-wishlist' ), get_the_author_meta( 'display_name', $wishlist['owner'] ) ) ) . '</p>';
		}
		$html .= '</div>';
		if ( $manage ) {
			$html .= sprintf(
				'<div class="wishcraft-list__actions" hidden><button type="button" class="wishcraft-link" data-wishcraft-action="edit-list" data-title="%1$s" data-description="%2$s" data-privacy="%3$s">%4$s</button><button type="button" class="wishcraft-link wishcraft-link--danger" data-wishcraft-action="delete-list" data-confirm="%5$s">%6$s</button></div>',
				esc_attr( $wishlist['title'] ),
				esc_attr( $wishlist['description'] ),
				esc_attr( $wishlist['privacy'] ),
				esc_html__( 'Edit list', 'wp-smart-wishlist' ),
				/* translators: %s: list title. */
				esc_attr( sprintf( __( 'Delete "%s" and everything in it?', 'wp-smart-wishlist' ), $wishlist['title'] ) ),
				esc_html__( 'Delete list', 'wp-smart-wishlist' )
			);
		}
		$html .= '</header>';
		/**
		 * Filters HTML shown under the list header (sharing, members).
		 *
		 * @param string $html     HTML.
		 * @param array  $wishlist List.
		 */
		$html .= apply_filters( 'wishcraft_list_panel', self::share_panel( $wishlist ), $wishlist );

		if ( ! $items ) {
			$html .= self::empty_state( __( 'Nothing saved in this list yet.', 'wp-smart-wishlist' ) );
		} else {
			$html .= '<ul class="wishcraft-items">';
			foreach ( $items as $item ) {
				$html .= self::item( $item, $wishlist, $targets, $url, true );
			}
			$html .= '</ul>';
			/**
			 * Filters HTML under the items (WooCommerce adds "Add all to cart").
			 *
			 * @param string  $html     HTML.
			 * @param array   $wishlist List.
			 * @param array[] $items    Items (REST format).
			 * @param string  $token    Share token when seen through the share link.
			 */
			$html .= apply_filters( 'wishcraft_list_footer', '', $wishlist, $items, '' );
		}
		return $html . '</section>';
	}

	/**
	 * A list seen through its share link.
	 *
	 * @param array  $wishlist List.
	 * @param string $base     Page URL.
	 * @return string
	 */
	private static function shared( array $wishlist, $base ) {
		$controller = new Lists_Controller();
		$items      = array_values( array_filter( array_map( array( $controller, 'format_item' ), Items::for_list( $wishlist['id'] ) ) ) );
		$url        = add_query_arg( 'wishlist', $wishlist['share_token'], $base );
		$html       = '<section class="wishcraft-list wishcraft-list--shared">';
		$html      .= '<header class="wishcraft-list__header"><div><h2 class="wishcraft-list__title">' . esc_html( $wishlist['title'] ) . '</h2>';
		if ( $wishlist['owner'] ) {
			/* translators: %s: name of the list owner. */
			$html .= '<p class="wishcraft-list__owner">' . esc_html( sprintf( __( 'A wishlist by %s', 'wp-smart-wishlist' ), get_the_author_meta( 'display_name', $wishlist['owner'] ) ) ) . '</p>';
		}
		if ( '' !== $wishlist['description'] ) {
			$html .= '<p class="wishcraft-list__description">' . esc_html( $wishlist['description'] ) . '</p>';
		}
		$html .= '</div></header>';
		if ( ! $items ) {
			return $html . self::empty_state( __( 'This wishlist is empty.', 'wp-smart-wishlist' ) ) . '</section>';
		}
		$html .= '<ul class="wishcraft-items">';
		foreach ( $items as $item ) {
			$html .= self::item( $item, $wishlist, array(), $url, false );
		}
		$html .= '</ul>';
		/** This filter is documented in src/front/class-page.php */
		$html .= apply_filters( 'wishcraft_list_footer', '', $wishlist, $items, $wishlist['share_token'] );
		return $html . '</section>';
	}

	/**
	 * One item.
	 *
	 * @param array   $item     Item (REST format).
	 * @param array   $wishlist List.
	 * @param array[] $targets  Lists it can move to.
	 * @param string  $url      This view's URL.
	 * @param bool    $editable Whether the visitor can change it.
	 * @return string
	 */
	private static function item( array $item, array $wishlist, array $targets, $url, $editable ) {
		$html  = '<li class="wishcraft-item" data-item-id="' . (int) $item['id'] . '">';
		$html .= '<a class="wishcraft-item__image" href="' . esc_url( $item['link'] ) . '" tabindex="-1" aria-hidden="true">';
		$html .= $item['image'] ? '<img src="' . esc_url( $item['image'] ) . '" alt="" loading="lazy" />' : '<span class="wishcraft-item__placeholder"></span>';
		$html .= '</a><div class="wishcraft-item__body">';
		$html .= '<a class="wishcraft-item__title" href="' . esc_url( $item['link'] ) . '">' . esc_html( $item['title'] ) . '</a>';
		if ( ! empty( $item['variation'] ) ) {
			$html .= '<span class="wishcraft-item__variation">' . esc_html( $item['variation'] ) . '</span>';
		}
		if ( ! empty( $item['price_html'] ) ) {
			$html .= '<span class="wishcraft-item__price">' . wp_kses_post( $item['price_html'] ) . '</span>';
		}
		if ( ! empty( $item['stock'] ) ) {
			$html .= '<span class="wishcraft-item__stock wishcraft-item__stock--' . esc_attr( $item['stock_status'] ) . '">' . esc_html( $item['stock'] ) . '</span>';
		}
		if ( $editable ) {
			$html .= sprintf(
				'<label class="wishcraft-item__note"><span class="screen-reader-text">%1$s</span><input type="text" maxlength="500" value="%2$s" placeholder="%3$s" data-wishcraft-field="note" disabled /></label>',
				esc_html__( 'Note', 'wp-smart-wishlist' ),
				esc_attr( $item['note'] ),
				esc_attr__( 'Add a note', 'wp-smart-wishlist' )
			);
		} elseif ( '' !== $item['note'] ) {
			$html .= '<p class="wishcraft-item__note-text">' . esc_html( $item['note'] ) . '</p>';
		}
		$html .= '</div><div class="wishcraft-item__actions">';
		if ( $editable ) {
			$html .= sprintf(
				'<label class="wishcraft-item__quantity"><span>%1$s</span><input type="number" min="1" max="9999" value="%2$d" data-wishcraft-field="quantity" disabled /></label>',
				esc_html__( 'Qty', 'wp-smart-wishlist' ),
				(int) $item['quantity']
			);
		} elseif ( $item['quantity'] > 1 ) {
			/* translators: %d: quantity. */
			$html .= '<span class="wishcraft-item__quantity">' . esc_html( sprintf( __( 'Qty %d', 'wp-smart-wishlist' ), $item['quantity'] ) ) . '</span>';
		}
		/**
		 * Filters an item's actions (WooCommerce adds "Add to cart").
		 *
		 * @param string $html     HTML.
		 * @param array  $item     Item (REST format).
		 * @param array  $wishlist List.
		 */
		$html .= apply_filters( 'wishcraft_item_actions', '', $item, $wishlist );
		if ( ! $editable ) {
			$html .= Button::render( $item['object_id'], array( 'context' => 'shared' ) );
		}
		if ( $editable && $targets ) {
			$html .= '<label class="wishcraft-item__move" hidden><span class="screen-reader-text">' . esc_html__( 'Move to', 'wp-smart-wishlist' ) . '</span><select data-wishcraft-field="move"><option value="">' . esc_html__( 'Move to…', 'wp-smart-wishlist' ) . '</option>';
			foreach ( $targets as $target ) {
				$html .= '<option value="' . (int) $target['id'] . '">' . esc_html( $target['title'] ) . '</option>';
			}
			$html .= '</select></label>';
		}
		if ( $editable ) {
			$remove = wp_nonce_url(
				add_query_arg(
					array(
						'action'      => Handler::REMOVE,
						'item'        => (int) $item['id'],
						'redirect_to' => rawurlencode( $url ),
					),
					admin_url( 'admin-post.php' )
				),
				Handler::REMOVE
			);
			$html  .= sprintf(
				'<a class="wishcraft-item__remove" href="%1$s" data-wishcraft-action="remove" rel="nofollow">%2$s<span class="screen-reader-text">%3$s</span></a>',
				esc_url( $remove ),
				Icons::get( 'close' ),
				/* translators: %s: item title. */
				esc_html( sprintf( __( 'Remove %s', 'wp-smart-wishlist' ), $item['title'] ) )
			);
		}
		return $html . '</div></li>';
	}

	/**
	 * Empty list message.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function empty_state( $message ) {
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		return '<div class="wishcraft-empty">' . Icons::get( 'heart' ) . '<p>' . esc_html( $message ) . '</p><p><a href="' . esc_url( $shop ) . '">' . esc_html__( 'Find something you love', 'wp-smart-wishlist' ) . '</a></p></div>';
	}

	/**
	 * Dialog for creating and editing lists.
	 *
	 * @return string
	 */
	private static function list_dialog() {
		$privacy = '';
		if ( Settings::get( 'sharing' ) ) {
			$options = array(
				'private' => __( 'Private – only you and people you invite', 'wp-smart-wishlist' ),
				'link'    => __( 'Shared – anyone with the link', 'wp-smart-wishlist' ),
				'public'  => __( 'Public', 'wp-smart-wishlist' ),
			);
			$privacy = '<label>' . esc_html__( 'Who can see it', 'wp-smart-wishlist' ) . '<select name="privacy">';
			foreach ( $options as $value => $label ) {
				$privacy .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
			}
			$privacy .= '</select></label>';
		}
		return '<dialog class="wishcraft-dialog" data-wishcraft-dialog="list"><form method="dialog" data-wishcraft-form="list">'
			. '<h2 data-title-new="' . esc_attr__( 'New list', 'wp-smart-wishlist' ) . '" data-title-edit="' . esc_attr__( 'Edit list', 'wp-smart-wishlist' ) . '">' . esc_html__( 'New list', 'wp-smart-wishlist' ) . '</h2>'
			. '<label>' . esc_html__( 'Name', 'wp-smart-wishlist' ) . '<input type="text" name="title" maxlength="190" required /></label>'
			. '<label>' . esc_html__( 'Description', 'wp-smart-wishlist' ) . '<textarea name="description" rows="2"></textarea></label>'
			. $privacy
			. '<p class="wishcraft-dialog__error" role="alert" hidden></p>'
			. '<div class="wishcraft-dialog__buttons"><button type="button" class="wishcraft-link" value="cancel" data-wishcraft-action="close">' . esc_html__( 'Cancel', 'wp-smart-wishlist' ) . '</button><button type="submit" class="wishcraft-primary">' . esc_html__( 'Save', 'wp-smart-wishlist' ) . '</button></div>'
			. '</form></dialog>';
	}

	/**
	 * The count of saved items (header counter).
	 *
	 * @param array $args { show_label: bool }.
	 * @return string
	 */
	public static function counter( array $args = array() ) {
		Assets::enqueue();
		$page  = (int) Settings::get( 'page' );
		$count = Wishlists::total();
		return sprintf(
			'<a class="wishcraft-counter" href="%1$s" data-wishcraft-counter>%2$s%3$s<span class="wishcraft-counter__count" data-count="%4$d">%4$d</span><span class="screen-reader-text">%5$s</span></a>',
			esc_url( $page ? get_permalink( $page ) : home_url( '/' ) ),
			Icons::get( 'heart' ),
			empty( $args['show_label'] ) ? '' : '<span class="wishcraft-counter__label">' . esc_html__( 'Wishlist', 'wp-smart-wishlist' ) . '</span>',
			$count,
			esc_html__( 'saved items', 'wp-smart-wishlist' )
		);
	}

	/**
	 * The share link of a shared or public list, for its owner.
	 *
	 * @param array $wishlist List.
	 * @return string
	 */
	private static function share_panel( array $wishlist ) {
		if ( ! Settings::get( 'sharing' ) || 'private' === $wishlist['privacy'] || ! Access::can_manage( $wishlist ) ) {
			return '';
		}
		$url = Controller::share_url( $wishlist );
		return sprintf(
			'<div class="wishcraft-share"><label for="wishcraft-share-%1$d">%2$s</label><div class="wishcraft-share__row"><input id="wishcraft-share-%1$d" type="text" readonly value="%3$s" data-wishcraft-share-url /><button type="button" class="wishcraft-secondary" data-wishcraft-action="copy-link" data-copied="%4$s" hidden>%5$s</button></div><p class="wishcraft-share__help">%6$s</p></div>',
			(int) $wishlist['id'],
			esc_html__( 'Share link', 'wp-smart-wishlist' ),
			esc_url( $url ),
			esc_attr__( 'Link copied.', 'wp-smart-wishlist' ),
			esc_html__( 'Copy link', 'wp-smart-wishlist' ),
			'public' === $wishlist['privacy'] ? esc_html__( 'This list is public: anyone with the link can see it.', 'wp-smart-wishlist' ) : esc_html__( 'Anyone with this link can see the list and buy from it. Make the list private to turn the link off.', 'wp-smart-wishlist' )
		);
	}

	/**
	 * Share URL helper for other renderers.
	 *
	 * @param array $wishlist List.
	 * @return string
	 */
	public static function share_url( array $wishlist ) {
		return Controller::share_url( $wishlist );
	}
}
