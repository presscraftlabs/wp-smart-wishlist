<?php
/**
 * Inline icons.
 *
 * @package WishCraft
 */

namespace WishCraft\Front;

/**
 * SVG icons, decorative (aria-hidden) and coloured with currentColor.
 */
final class Icons {

	/**
	 * Paths.
	 *
	 * @var string[]
	 */
	const PATHS = array(
		'heart' => 'M12 20.6l-1.3-1.2C5.9 15.1 3 12.4 3 8.9 3 6.1 5.2 4 7.9 4c1.6 0 3.1.7 4.1 1.9C13 4.7 14.5 4 16.1 4 18.8 4 21 6.1 21 8.9c0 3.5-2.9 6.2-7.7 10.5L12 20.6z',
		'share' => 'M18 16.1c-.8 0-1.4.3-2 .8l-7.1-4.2c.1-.2.1-.5.1-.7s0-.5-.1-.7l7-4.1c.5.5 1.2.8 2.1.8 1.7 0 3-1.3 3-3s-1.3-3-3-3-3 1.3-3 3c0 .2 0 .5.1.7l-7 4.1C7.5 9.3 6.8 9 6 9c-1.7 0-3 1.3-3 3s1.3 3 3 3c.8 0 1.5-.3 2-.8l7.1 4.2c-.1.2-.1.4-.1.6 0 1.6 1.3 2.9 2.9 2.9s2.9-1.3 2.9-2.9-1.2-2.9-2.8-2.9z',
		'close' => 'M19 6.4L17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z',
		'plus'  => 'M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z',
	);

	/**
	 * An icon.
	 *
	 * @param string $name  Name.
	 * @param string $extra_class Extra class.
	 * @return string
	 */
	public static function get( $name, $extra_class = '' ) {
		if ( ! isset( self::PATHS[ $name ] ) ) {
			return '';
		}
		return sprintf(
			'<svg class="wishcraft-icon wishcraft-icon--%1$s %2$s" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="%3$s"/></svg>',
			esc_attr( $name ),
			esc_attr( $extra_class ),
			esc_attr( self::PATHS[ $name ] )
		);
	}
}
