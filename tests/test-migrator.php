<?php
/**
 * Upgrade from 2.0.
 *
 * @package WishCraft
 */

use WishCraft\Data\Items;
use WishCraft\Data\Lists;
use WishCraft\Legacy\Migrator;

/**
 * Migrator.
 */
class Test_Migrator extends WishCraft_TestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Migrator::OPTION );
	}

	/**
	 * Store a 2.0 wishlist the way 2.0 did: a serialized string, serialized again by WordPress.
	 *
	 * @param int   $user User.
	 * @param array $ids  Post IDs as strings.
	 */
	private function seed( $user, array $ids ) {
		update_user_meta( $user, 'wishlist', serialize( $ids ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	public function test_decode() {
		$this->assertSame( array( 4, 9 ), Migrator::decode( serialize( serialize( array( '4', '9', '4', 'x', 0 ) ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$this->assertSame( array( 5 ), Migrator::decode( serialize( array( 5 ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$this->assertSame( array(), Migrator::decode( 'garbage' ) );
		$this->assertSame( array(), Migrator::decode( serialize( serialize( new ArrayObject( array( 1 ) ) ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	public function test_migrates_in_batches_and_keeps_old_data() {
		$posts = self::factory()->post->create_many( 3 );
		$users = self::factory()->user->create_many( 3 );
		$this->seed( $users[0], array( (string) $posts[2], (string) $posts[0] ) );
		$this->seed( $users[1], array( (string) $posts[1], '999999' ) );
		$this->seed( $users[2], array() );

		Migrator::maybe_start();
		$this->assertSame( 'pending', Migrator::state()['status'] );

		$state = Migrator::run( 2 );
		$this->assertSame( 'pending', $state['status'] );
		$state = Migrator::run( 2 );
		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( 2, $state['users'] );
		$this->assertSame( 3, $state['items'] );
		$this->assertSame( 1, $state['skipped'] );

		$list = Lists::default_for_user( $users[0] );
		$this->assertSame( 'My wishlist', $list['title'] );
		$this->assertSame( array( $posts[2], $posts[0] ), wp_list_pluck( Items::for_list( $list['id'] ), 'object_id' ) );
		$this->assertSame( array( $posts[1] ), wp_list_pluck( Items::for_list( Lists::default_for_user( $users[1] )['id'] ), 'object_id' ) );
		$this->assertNotEmpty( get_user_meta( $users[0], 'wishlist', true ), '2.0 data is kept.' );

		// Running again changes nothing.
		Migrator::run();
		$this->assertSame( 2, Items::count( $list['id'] ) );
	}

	public function test_nothing_to_migrate() {
		Migrator::maybe_start();
		$this->assertSame( 'done', Migrator::state()['status'] );
		$this->assertFalse( wp_next_scheduled( Migrator::HOOK ) );
	}
}
