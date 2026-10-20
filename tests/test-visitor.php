<?php
/**
 * Guests, ownership, access and merging at login.
 *
 * @package WishCraft
 */

use WishCraft\Access;
use WishCraft\Data\Items;
use WishCraft\Data\Lists;
use WishCraft\Data\Members;
use WishCraft\Settings;
use WishCraft\Visitor;

/**
 * Visitor and Access.
 */
class Test_Visitor extends WishCraft_TestCase {

	/**
	 * Become a guest with a given token.
	 *
	 * @param string $token Token.
	 * @return string Guest key.
	 */
	private function guest( $token ) {
		wp_set_current_user( 0 );
		Visitor::reset();
		$_COOKIE[ Visitor::COOKIE ] = $token;
		return Visitor::guest_key();
	}

	public function test_guest_key_is_a_hash_of_the_cookie() {
		$token = str_repeat( 'c', 64 );
		$key   = $this->guest( $token );
		$this->assertSame( Visitor::hash( $token ), $key );
		$this->assertNotSame( $token, $key );
		$_COOKIE[ Visitor::COOKIE ] = 'short';
		$this->assertSame( '', Visitor::guest_key() );
	}

	public function test_guest_cookie_is_issued_on_first_save() {
		wp_set_current_user( 0 );
		$this->assertSame( '', Visitor::guest_key() );
		$fields = Visitor::owner_fields();
		$this->assertSame( 0, $fields['owner'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $fields['guest_key'] );
		$this->assertSame( $fields['guest_key'], Visitor::guest_key(), 'Same key for the rest of the request.' );
	}

	public function test_guests_off() {
		Settings::save( array( 'guests' => false ) );
		$this->guest( str_repeat( 'c', 64 ) );
		$this->assertFalse( Visitor::can_save() );
		$this->assertSame( '', Visitor::guest_key( true ) );
	}

	public function test_ownership() {
		$user  = self::factory()->user->create();
		$mine  = Lists::get( Lists::create( array( 'owner' => $user ) ) );
		$key   = $this->guest( str_repeat( 'd', 64 ) );
		$guest = Lists::get( Lists::create( array( 'guest_key' => $key ) ) );
		$this->assertTrue( Visitor::owns( $guest ) );
		$this->assertFalse( Visitor::owns( $mine ) );
		$this->guest( str_repeat( 'e', 64 ) );
		$this->assertFalse( Visitor::owns( $guest ) );
		wp_set_current_user( $user );
		$this->assertTrue( Visitor::owns( $mine ) );
		$this->assertFalse( Visitor::owns( $guest ) );
	}

	public function test_access_by_privacy_and_membership() {
		$owner  = self::factory()->user->create();
		$friend = self::factory()->user->create();
		$other  = self::factory()->user->create();
		$id     = Lists::create( array( 'owner' => $owner ) );
		$invite = Members::invite( $id, 'f@example.com' );
		Members::accept( $invite['key'], $friend );

		wp_set_current_user( $other );
		$list = Lists::get( $id );
		$this->assertFalse( Access::can_view( $list ) );
		$this->assertFalse( Access::can_view_shared( $list ) );
		$list = Lists::update( $id, array( 'privacy' => 'link' ) );
		$this->assertFalse( Access::can_view( $list ), 'Link lists are not listed by ID.' );
		$this->assertTrue( Access::can_view_shared( $list ) );
		$list = Lists::update( $id, array( 'privacy' => 'public' ) );
		$this->assertTrue( Access::can_view( $list ) );
		$this->assertFalse( Access::can_edit( $list ) );
		Settings::save( array( 'sharing' => false ) );
		$this->assertFalse( Access::can_view_shared( $list ) );

		wp_set_current_user( $friend );
		$list = Lists::update( $id, array( 'privacy' => 'private' ) );
		$this->assertTrue( Access::can_view( $list ) );
		$this->assertTrue( Access::can_edit( $list ) );
		$this->assertFalse( Access::can_manage( $list ) );
		$this->assertSame( 'editor', Access::role( $list ) );
		Settings::save( array( 'collaboration' => false ) );
		$this->assertFalse( Access::can_edit( $list ) );

		wp_set_current_user( $owner );
		$this->assertTrue( Access::can_manage( $list ) );
		$this->assertSame( 'owner', Access::role( $list ) );
	}

	public function test_guest_lists_merge_at_login() {
		$posts = self::factory()->post->create_many( 3 );
		$user  = self::factory()->user->create();
		$mine  = Lists::default_for_user( $user, true );
		Items::add( $mine['id'], $posts[0] );

		$key     = $this->guest( str_repeat( 'f', 64 ) );
		$default = Lists::create( array( 'guest_key' => $key, 'is_default' => 1 ) );
		$party   = Lists::create( array( 'guest_key' => $key, 'title' => 'Party' ) );
		Items::add( $default, $posts[0] );
		Items::add( $default, $posts[1] );
		Items::add( $party, $posts[2] );

		do_action( 'wp_login', 'someone', get_userdata( $user ) );

		$this->assertNull( Lists::get( $default ) );
		$this->assertSame( array( $posts[0], $posts[1] ), wp_list_pluck( Items::for_list( $mine['id'] ), 'object_id' ) );
		$party = Lists::get( $party );
		$this->assertSame( $user, $party['owner'] );
		$this->assertSame( '', $party['guest_key'] );
		$this->assertFalse( $party['is_default'] );
		$this->assertArrayNotHasKey( Visitor::COOKIE, $_COOKIE );
		$this->assertSame( array(), Lists::for_guest( $key ) );
	}

	public function test_merge_at_registration_without_existing_list() {
		$post = self::factory()->post->create();
		$key  = $this->guest( str_repeat( '1', 64 ) );
		Items::add( Lists::create( array( 'guest_key' => $key, 'is_default' => 1 ) ), $post );
		$user    = self::factory()->user->create();
		$default = Lists::default_for_user( $user );
		$this->assertNotNull( $default );
		$this->assertSame( array( $post ), wp_list_pluck( Items::for_list( $default['id'] ), 'object_id' ) );
	}
}
