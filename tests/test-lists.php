<?php
/**
 * Lists, items and members.
 *
 * @package WishCraft
 */

use WishCraft\Data\Items;
use WishCraft\Data\Lists;
use WishCraft\Data\Members;
use WishCraft\Plugin;
use WishCraft\Settings;

/**
 * Data layer rules.
 */
class Test_Lists extends WishCraft_TestCase {

	public function test_create_defaults_and_token() {
		$user = self::factory()->user->create();
		$list = Lists::get( Lists::create( array( 'owner' => $user, 'title' => '  ' ) ) );
		$this->assertSame( 'My wishlist', $list['title'] );
		$this->assertSame( 'private', $list['privacy'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $list['share_token'] );
		$this->assertSame( $list['id'], Lists::by_token( $list['share_token'] )['id'] );
		$this->assertNull( Lists::by_token( 'nope' ) );
	}

	public function test_invalid_privacy_becomes_private_and_token_rotates() {
		$id   = Lists::create( array( 'privacy' => 'everyone' ) );
		$list = Lists::get( $id );
		$this->assertSame( 'private', $list['privacy'] );
		$new = Lists::update( $id, array( 'share_token' => true, 'privacy' => 'link' ) );
		$this->assertNotSame( $list['share_token'], $new['share_token'] );
		$this->assertSame( 'link', $new['privacy'] );
		$this->assertNull( Lists::by_token( $list['share_token'] ) );
	}

	public function test_items_are_unique_and_limited() {
		Settings::save( array( 'post_types' => array( 'post' ), 'max_items' => 10 ) );
		$list  = Lists::create( array() );
		$posts = self::factory()->post->create_many( 11 );
		$first = Items::add( $list, $posts[0] );
		$this->assertSame( $first, Items::add( $list, $posts[0] ) );
		foreach ( array_slice( $posts, 1, 9 ) as $post ) {
			$this->assertGreaterThan( 0, Items::add( $list, $post ) );
		}
		$this->assertSame( 0, Items::add( $list, $posts[10] ) );
		$this->assertSame( 10, Items::count( $list ) );
		$this->assertSame( range( 1, 10 ), wp_list_pluck( Items::for_list( $list ), 'position' ) );
	}

	public function test_allowed_checks_type_status_and_variation() {
		Settings::save( array( 'post_types' => array( 'post' ) ) );
		$post  = self::factory()->post->create();
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$page  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertTrue( Items::allowed( $post ) );
		$this->assertFalse( Items::allowed( $draft ) );
		$this->assertFalse( Items::allowed( $page ) );
		$this->assertFalse( Items::allowed( $post, $page ) );
		$this->assertFalse( Items::allowed( 999999 ) );
	}

	public function test_note_and_quantity_are_cleaned() {
		$list = Lists::create( array() );
		$post = self::factory()->post->create();
		$id   = Items::add( $list, $post, 0, 50000, '<b>Size M</b>' );
		$item = Items::get( $id );
		$this->assertSame( 9999, $item['quantity'] );
		$this->assertSame( 'Size M', $item['note'] );
		$item = Items::update( $id, array( 'quantity' => 0, 'note' => str_repeat( 'a', 600 ) ) );
		$this->assertSame( 1, $item['quantity'] );
		$this->assertSame( 500, strlen( $item['note'] ) );
	}

	public function test_move_merges_with_existing_item() {
		$post = self::factory()->post->create();
		$a    = Lists::create( array() );
		$b    = Lists::create( array() );
		$item = Items::add( $a, $post );
		$keep = Items::add( $b, $post );
		$moved = Items::move( $item, $b );
		$this->assertSame( $keep, $moved['id'] );
		$this->assertSame( 0, Items::count( $a ) );
		$this->assertSame( 1, Items::count( $b ) );
	}

	public function test_membership_and_popular() {
		$posts = self::factory()->post->create_many( 2 );
		$a     = Lists::create( array() );
		$b     = Lists::create( array() );
		Items::add( $a, $posts[0] );
		Items::add( $b, $posts[0] );
		Items::add( $b, $posts[1] );
		$this->assertSame( array( $posts[0] => array( $a, $b ), $posts[1] => array( $b ) ), Items::membership( array( $a, $b ), $posts ) );
		$this->assertSame( array( 'object_id' => $posts[0], 'lists' => 2 ), Items::popular( 1 )[0] );
	}

	public function test_delete_removes_items_and_members() {
		$list = Lists::create( array() );
		Items::add( $list, self::factory()->post->create() );
		Members::invite( $list, 'friend@example.com' );
		Lists::delete( $list );
		$this->assertNull( Lists::get( $list ) );
		$this->assertSame( 0, Items::count( $list ) );
		$this->assertSame( array(), Members::for_list( $list ) );
	}

	public function test_invitation_is_accepted_once_by_a_user() {
		$owner  = self::factory()->user->create();
		$friend = self::factory()->user->create();
		$list   = Lists::create( array( 'owner' => $owner ) );
		$invite = Members::invite( $list, 'friend@example.com' );
		$this->assertNull( Members::invite( $list, 'not an email' ) );
		$this->assertNull( Members::accept( $invite['key'], $owner ), 'The owner cannot join their own list.' );
		$this->assertNull( Members::accept( str_repeat( 'a', 64 ), $friend ) );
		$member = Members::accept( $invite['key'], $friend );
		$this->assertSame( 'active', $member['status'] );
		$this->assertSame( $friend, $member['user_id'] );
		$this->assertNull( Members::accept( $invite['key'], $friend ), 'Keys work once.' );
		$this->assertSame( array( $list ), Members::list_ids_for_user( $friend ) );
	}

	public function test_cleanup_deletes_only_stale_guest_lists() {
		global $wpdb;
		$user  = self::factory()->user->create();
		$stale = Lists::create( array( 'guest_key' => str_repeat( 'a', 64 ) ) );
		$fresh = Lists::create( array( 'guest_key' => str_repeat( 'b', 64 ) ) );
		$owned = Lists::create( array( 'owner' => $user ) );
		$old   = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
		$table = WishCraft\Data\Schema::table( 'lists' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET updated = %s WHERE id IN (%d, %d)", $old, $stale, $owned ) ); // phpcs:ignore
		$this->assertSame( 1, Plugin::cleanup() );
		$this->assertNull( Lists::get( $stale ) );
		$this->assertNotNull( Lists::get( $fresh ) );
		$this->assertNotNull( Lists::get( $owned ) );
	}

	public function test_deleted_user_lists_are_removed_or_reassigned() {
		$a    = self::factory()->user->create();
		$b    = self::factory()->user->create();
		$gone = Lists::create( array( 'owner' => $a ) );
		$kept = Lists::create( array( 'owner' => $b, 'is_default' => 1 ) );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $a );
		$this->assertNull( Lists::get( $gone ) );
		$c = self::factory()->user->create();
		wp_delete_user( $b, $c );
		$list = Lists::get( $kept );
		$this->assertSame( $c, $list['owner'] );
		$this->assertFalse( $list['is_default'] );
	}
}
