<?php
/**
 * REST API.
 *
 * @package WishCraft
 */

use WishCraft\Data\Items;
use WishCraft\Data\Lists;
use WishCraft\Data\Members;
use WishCraft\Settings;
use WishCraft\Visitor;

/**
 * wishcraft/v1 routes.
 */
class Test_Rest extends WishCraft_TestCase {

	/**
	 * Posts that can be saved.
	 *
	 * @var int[]
	 */
	private $posts;

	public function set_up() {
		parent::set_up();
		Settings::save( array( 'post_types' => array( 'post' ) ) );
		$this->posts = self::factory()->post->create_many( 3 );
		do_action( 'rest_api_init' );
	}

	/**
	 * Run a request.
	 *
	 * @param string $method Method.
	 * @param string $route  Route below the namespace.
	 * @param array  $params Body or query.
	 * @param bool   $header Send the guest header.
	 * @return WP_REST_Response
	 */
	private function call( $method, $route, array $params = array(), $header = true ) {
		$request = new WP_REST_Request( $method, '/wishcraft/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		if ( $header ) {
			$request->set_header( 'X-WishCraft', '1' );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes( 'wishcraft/v1' );
		foreach ( array( '/lists', '/lists/(?P<id>\d+)', '/lists/(?P<id>\d+)/share', '/lists/(?P<id>\d+)/items', '/items/(?P<id>\d+)', '/items/(?P<id>\d+)/move', '/toggle', '/status', '/shared/(?P<token>[a-f0-9]{32})' ) as $route ) {
			$this->assertArrayHasKey( '/wishcraft/v1' . $route, $routes );
		}
	}

	public function test_guest_toggle_needs_header_and_creates_default_list() {
		wp_set_current_user( 0 );
		$this->assertSame( 403, $this->call( 'POST', '/toggle', array( 'object_id' => $this->posts[0] ), false )->get_status() );

		$response = $this->call( 'POST', '/toggle', array( 'object_id' => $this->posts[0] ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['added'] );
		$this->assertSame( 1, $data['count'] );
		$this->assertSame( array( $data['list_id'] ), $data['lists'] );
		$this->assertTrue( Lists::get( $data['list_id'] )['is_default'] );

		$data = $this->call( 'POST', '/toggle', array( 'object_id' => $this->posts[0] ) )->get_data();
		$this->assertFalse( $data['added'] );
		$this->assertSame( 0, $data['count'] );
	}

	public function test_guests_off_needs_login() {
		Settings::save( array( 'guests' => false ) );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call( 'POST', '/toggle', array( 'object_id' => $this->posts[0] ) )->get_status() );
	}

	public function test_toggle_rejects_unsupported_posts() {
		wp_set_current_user( self::factory()->user->create() );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( 400, $this->call( 'POST', '/toggle', array( 'object_id' => $page ) )->get_status() );
		$this->assertSame( array(), Lists::for_user( get_current_user_id() ), 'No empty list is left behind.' );
	}

	public function test_lists_crud_and_limits() {
		Settings::save( array( 'max_lists' => 2 ) );
		$user = self::factory()->user->create();
		wp_set_current_user( $user );

		$first = $this->call( 'POST', '/lists', array( 'title' => 'Birthday' ) );
		$this->assertSame( 201, $first->get_status() );
		$this->assertTrue( $first->get_data()['is_default'] );
		$second = $this->call( 'POST', '/lists', array( 'title' => 'Kitchen', 'privacy' => 'link' ) )->get_data();
		$this->assertFalse( $second['is_default'] );
		$this->assertStringContainsString( 'wishlist=', $second['share_url'] );
		$this->assertSame( 403, $this->call( 'POST', '/lists', array( 'title' => 'Third' ) )->get_status() );

		$this->assertCount( 2, $this->call( 'GET', '/lists' )->get_data() );
		$updated = $this->call( 'PATCH', '/lists/' . $second['id'], array( 'title' => 'Home', 'privacy' => 'public' ) )->get_data();
		$this->assertSame( 'Home', $updated['title'] );
		$this->assertSame( 'public', $updated['privacy'] );
		$this->assertSame( 400, $this->call( 'PATCH', '/lists/' . $second['id'], array( 'privacy' => 'world' ) )->get_status() );

		$this->call( 'DELETE', '/lists/' . $first->get_data()['id'] );
		$this->assertTrue( Lists::get( $second['id'] )['is_default'], 'The remaining list becomes the default.' );
	}

	public function test_single_list_mode() {
		Settings::save( array( 'multiple' => false ) );
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, $this->call( 'POST', '/lists', array( 'title' => 'Another' ) )->get_status() );
	}

	public function test_other_users_cannot_see_or_change_a_list() {
		$owner = self::factory()->user->create();
		$list  = Lists::create( array( 'owner' => $owner, 'is_default' => 1 ) );
		$item  = Items::add( $list, $this->posts[0] );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 404, $this->call( 'GET', '/lists/' . $list )->get_status() );
		$this->assertSame( 404, $this->call( 'PATCH', '/lists/' . $list, array( 'title' => 'Mine' ) )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/lists/' . $list )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/lists/' . $list . '/items', array( 'object_id' => $this->posts[1] ) )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/items/' . $item )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/toggle', array( 'object_id' => $this->posts[0], 'list_id' => $list ) )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/lists/' . $list . '/share' )->get_status() );

		Lists::update( $list, array( 'privacy' => 'public' ) );
		$this->assertSame( 200, $this->call( 'GET', '/lists/' . $list )->get_status() );
		$this->assertSame( 403, $this->call( 'PATCH', '/lists/' . $list, array( 'title' => 'Mine' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', '/items/' . $item )->get_status() );
		$this->assertNotNull( Items::get( $item ) );
	}

	public function test_a_guest_cannot_use_another_guests_list() {
		wp_set_current_user( 0 );
		$_COOKIE[ Visitor::COOKIE ] = str_repeat( 'a', 64 );
		$list                       = Lists::create( array( 'guest_key' => Visitor::guest_key(), 'is_default' => 1 ) );
		$this->assertSame( 200, $this->call( 'GET', '/lists/' . $list )->get_status() );
		$_COOKIE[ Visitor::COOKIE ] = str_repeat( 'b', 64 );
		$this->assertSame( 404, $this->call( 'GET', '/lists/' . $list )->get_status() );
		$this->assertSame( array(), $this->call( 'GET', '/lists' )->get_data() );
	}

	public function test_items_update_move_and_status() {
		wp_set_current_user( self::factory()->user->create() );
		$a = $this->call( 'POST', '/lists', array( 'title' => 'A' ) )->get_data()['id'];
		$b = $this->call( 'POST', '/lists', array( 'title' => 'B' ) )->get_data()['id'];

		$item = $this->call( 'POST', "/lists/$a/items", array( 'object_id' => $this->posts[0], 'quantity' => 2, 'note' => 'Blue' ) );
		$this->assertSame( 201, $item->get_status() );
		$item = $item->get_data();
		$this->assertSame( 2, $item['quantity'] );
		$this->assertSame( get_the_title( $this->posts[0] ), $item['title'] );

		$item = $this->call( 'PATCH', '/items/' . $item['id'], array( 'quantity' => 3 ) )->get_data();
		$this->assertSame( 3, $item['quantity'] );
		$this->assertSame( 'Blue', $item['note'] );

		$moved = $this->call( 'POST', '/items/' . $item['id'] . '/move', array( 'list_id' => $b ) )->get_data();
		$this->assertSame( $b, $moved['list_id'] );

		$status = $this->call( 'GET', '/status', array( 'ids' => array( $this->posts[0], $this->posts[1] ) ) )->get_data();
		$this->assertSame( array( $this->posts[0] => array( $b ) ), (array) $status['items'] );
		$this->assertSame( 1, $status['count'] );
		$this->assertCount( 2, $status['lists'] );

		$this->assertTrue( $this->call( 'DELETE', '/items/' . $moved['id'] )->get_data()['deleted'] );
		$this->assertSame( 0, Items::count( $b ) );
	}

	public function test_shared_view_and_rotation() {
		$owner = self::factory()->user->create();
		wp_set_current_user( $owner );
		$list  = Lists::get( Lists::create( array( 'owner' => $owner, 'privacy' => 'link' ) ) );
		Items::add( $list['id'], $this->posts[0] );
		$token = $list['share_token'];

		wp_set_current_user( 0 );
		$shared = $this->call( 'GET', '/shared/' . $token );
		$this->assertSame( 200, $shared->get_status() );
		$data = $shared->get_data();
		$this->assertSame( 'viewer', $data['role'] );
		$this->assertCount( 1, $data['items'] );
		$this->assertArrayNotHasKey( 'share_url', $data );

		wp_set_current_user( $owner );
		$new = $this->call( 'POST', '/lists/' . $list['id'] . '/share' )->get_data();
		$this->assertStringNotContainsString( $token, $new['share_url'] );

		wp_set_current_user( 0 );
		$this->assertSame( 404, $this->call( 'GET', '/shared/' . $token )->get_status() );

		Lists::update( $list['id'], array( 'privacy' => 'private' ) );
		$fresh = Lists::get( $list['id'] )['share_token'];
		$this->assertSame( 404, $this->call( 'GET', '/shared/' . $fresh )->get_status() );
	}

	public function test_shared_view_hides_unpublished_posts() {
		$owner = self::factory()->user->create();
		$list  = Lists::get( Lists::create( array( 'owner' => $owner, 'privacy' => 'public' ) ) );
		Items::add( $list['id'], $this->posts[0] );
		Items::add( $list['id'], $this->posts[1] );
		wp_update_post( array( 'ID' => $this->posts[1], 'post_status' => 'private' ) );
		wp_set_current_user( 0 );
		$this->assertCount( 1, $this->call( 'GET', '/shared/' . $list['share_token'] )->get_data()['items'] );
	}

	public function test_collaborator_edits_items_but_not_the_list() {
		$owner  = self::factory()->user->create();
		$friend = self::factory()->user->create();
		$list   = Lists::create( array( 'owner' => $owner, 'is_default' => 1 ) );
		Members::accept( Members::invite( $list, 'f@example.com' )['key'], $friend );

		wp_set_current_user( $friend );
		$lists = $this->call( 'GET', '/lists' )->get_data();
		$this->assertSame( array( $list ), wp_list_pluck( $lists, 'id' ) );
		$this->assertSame( 'editor', $lists[0]['role'] );
		$this->assertSame( 201, $this->call( 'POST', "/lists/$list/items", array( 'object_id' => $this->posts[0] ) )->get_status() );
		$this->assertSame( $friend, Items::for_list( $list )[0]['added_by'] );
		$this->assertSame( 403, $this->call( 'PATCH', "/lists/$list", array( 'title' => 'Ours' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', "/lists/$list" )->get_status() );

		// Toggling without a list uses the friend's own default list, not the shared one.
		$data = $this->call( 'POST', '/toggle', array( 'object_id' => $this->posts[1] ) )->get_data();
		$this->assertNotSame( $list, $data['list_id'] );
		$this->assertSame( $friend, Lists::get( $data['list_id'] )['owner'] );
	}
}
