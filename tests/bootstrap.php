<?php
/**
 * PHPUnit bootstrap. Set WP_TESTS_DIR to the wordpress-develop tests/phpunit folder
 * (bin/install-wp-tests.sh does this).
 *
 * @package WishCraft
 */

$wishcraft_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wishcraft_tests_dir ) {
	$wishcraft_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}
if ( ! file_exists( $wishcraft_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $wishcraft_tests_dir/includes/functions.php\n"; // phpcs:ignore
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $wishcraft_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/install.php';
	}
);
// Create the tables once, before tests start their transactions.
tests_add_filter(
	'init',
	static function () {
		WishCraft\Data\Schema::install();
	},
	30
);

require $wishcraft_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-testcase.php';
