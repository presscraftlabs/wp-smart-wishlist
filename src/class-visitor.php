<?php
/**
 * Who is using the wishlist: a logged-in user or a guest.
 *
 * @package WishCraft
 */

namespace WishCraft;

use WishCraft\Data\Items;
use WishCraft\Data\Lists;

/**
 * Guests are recognised by a random token in an HttpOnly, SameSite=Lax cookie; only a keyed hash
 * of it is stored, so the database cannot be used to take over a guest's lists. The cookie is set
 * the first time a guest saves something, and their lists move into their account when they log
 * in or register.
 */
final class Visitor {

	const COOKIE = 'wishcraft_guest';

	/**
	 * Token issued in this request (cookie not yet visible in $_COOKIE).
	 *
	 * @var string
	 */
	private static $issued = '';

	/**
	 * Hooks.
	 */
	public static function register() {
		add_action( 'wp_login', array( self::class, 'on_login' ), 10, 2 );
		add_action( 'user_register', array( self::class, 'on_register' ) );
	}

	/**
	 * Forget the token issued in this request (tests).
	 */
	public static function reset() {
		self::$issued = '';
	}

	/**
	 * Logged-in user ID, or 0.
	 *
	 * @return int
	 */
	public static function user_id() {
		return (int) get_current_user_id();
	}

	/**
	 * The guest token from the cookie, if it is well formed.
	 *
	 * @return string
	 */
	private static function token() {
		if ( '' !== self::$issued ) {
			return self::$issued;
		}
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return preg_match( '/^[a-f0-9]{64}$/', $token ) ? $token : '';
	}

	/**
	 * Stored form of a guest token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function hash( $token ) {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Guest key of this visitor ('' for logged-in users and guests without a cookie).
	 *
	 * @param bool $create Issue a cookie when there is none.
	 * @return string
	 */
	public static function guest_key( $create = false ) {
		if ( self::user_id() || ! Settings::get( 'guests' ) ) {
			return '';
		}
		$token = self::token();
		if ( '' === $token && $create ) {
			$token        = bin2hex( random_bytes( 32 ) );
			self::$issued = $token;
			self::set_cookie( $token, time() + DAY_IN_SECONDS * Settings::get( 'guest_days' ) );
		}
		return '' === $token ? '' : self::hash( $token );
	}

	/**
	 * Send the guest cookie.
	 *
	 * @param string $token   Token ('' to delete).
	 * @param int    $expires Expiry time.
	 */
	private static function set_cookie( $token, $expires ) {
		if ( '' === $token ) {
			unset( $_COOKIE[ self::COOKIE ] );
		} else {
			$_COOKIE[ self::COOKIE ] = $token;
		}
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$token,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				// Not sent with cross-site requests, so other sites cannot change a guest's lists.
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Whether this visitor can keep a wishlist at all.
	 *
	 * @return bool
	 */
	public static function can_save() {
		return self::user_id() > 0 || Settings::get( 'guests' );
	}

	/**
	 * Owner fields for a new list.
	 *
	 * @return array { owner, guest_key }
	 */
	public static function owner_fields() {
		$user = self::user_id();
		return array(
			'owner'     => $user,
			'guest_key' => $user ? '' : self::guest_key( true ),
		);
	}

	/**
	 * Whether this visitor owns a list.
	 *
	 * @param array $wishlist List.
	 * @return bool
	 */
	public static function owns( array $wishlist ) {
		$user = self::user_id();
		if ( $user ) {
			return (int) $wishlist['owner'] === $user;
		}
		$key = self::guest_key();
		return '' !== $key && 0 === (int) $wishlist['owner'] && hash_equals( $wishlist['guest_key'], $key );
	}

	/**
	 * Move a guest's lists into the account that just logged in.
	 *
	 * @param string   $login User login.
	 * @param \WP_User $user  User.
	 */
	public static function on_login( $login, $user ) {
		self::merge_into( (int) $user->ID );
		unset( $login );
	}

	/**
	 * Same at registration (the new user may not be logged in yet).
	 *
	 * @param int $user_id User ID.
	 */
	public static function on_register( $user_id ) {
		self::merge_into( (int) $user_id );
	}

	/**
	 * Merge the guest's lists into a user's: the guest's default list into the user's default list,
	 * other lists become the user's own.
	 *
	 * @param int $user_id User ID.
	 * @return int Lists merged.
	 */
	public static function merge_into( $user_id ) {
		$token = self::token();
		if ( '' === $token || ! $user_id ) {
			return 0;
		}
		$lists = Lists::for_guest( self::hash( $token ) );
		foreach ( $lists as $wishlist ) {
			if ( $wishlist['is_default'] ) {
				$target = Lists::default_for_user( $user_id, true );
				foreach ( Items::for_list( $wishlist['id'] ) as $item ) {
					Items::add( $target['id'], $item['object_id'], $item['variation_id'], $item['quantity'], $item['note'], $user_id );
				}
				Lists::delete( $wishlist['id'] );
			} else {
				Lists::update(
					$wishlist['id'],
					array(
						'owner'     => $user_id,
						'guest_key' => '',
					)
				);
			}
		}
		self::$issued = '';
		self::set_cookie( '', time() - YEAR_IN_SECONDS );
		return count( $lists );
	}
}
