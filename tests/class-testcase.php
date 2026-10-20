<?php
/**
 * Base test case.
 *
 * @package WishCraft
 */

use WishCraft\Settings;
use WishCraft\Visitor;

/**
 * Starts every test as a guest without a wishlist cookie and with default settings.
 */
abstract class WishCraft_TestCase extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::flush();
		unset( $_COOKIE[ Visitor::COOKIE ] );
		Visitor::reset();
		WishCraft\Wishlists::reset();
	}

	public function tear_down() {
		unset( $_COOKIE[ Visitor::COOKIE ] );
		Visitor::reset();
		Settings::flush();
		parent::tear_down();
	}
}
