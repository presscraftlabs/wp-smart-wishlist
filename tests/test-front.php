<?php
/**
 * Buttons, the wishlist page, blocks and shortcodes.
 *
 * @package WishCraft
 */

use WishCraft\Data\Items;
use WishCraft\Data\Lists;
use WishCraft\Front\Button;
use WishCraft\Front\Page;
use WishCraft\Plugin;
use WishCraft\Settings;
use WishCraft\Visitor;
use WishCraft\Wishlists;

/**
 * Front end.
 */
class Test_Front extends WishCraft_TestCase {

	/**
	 * A post that can be saved.
	 *
	 * @var int
	 */
	private $post;

	public function set_up() {
		parent::set_up();
		Settings::save( array( 'post_types' => array( 'post' ) ) );
		$this->post = self::factory()->post->create( array( 'post_title' => 'Blue Lamp' ) );
	}

	public function test_button_shows_state_and_labels() {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$html = Button::render( $this->post );
		$this->assertStringContainsString( 'aria-pressed="false"', $html );
		$this->assertStringContainsString( 'Add to wishlist', $html );
		$this->assertStringContainsString( 'action=wishcraft_toggle', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
		$this->assertTrue( wp_script_is( 'wishcraft', 'enqueued' ) );

		Wishlists::toggle( $this->post );
		Settings::save( array( 'button_added' => 'Saved!' ) );
		$html = Button::render( $this->post, array( 'show_label' => false ) );
		$this->assertStringContainsString( 'aria-pressed="true"', $html );
		$this->assertStringContainsString( 'is-saved', $html );
		$this->assertStringContainsString( 'screen-reader-text">Saved!', $html );
	}

	public function test_button_needs_supported_post_and_links_to_login_without_guests() {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( '', Button::render( $page ) );
		Settings::save( array( 'guests' => false ) );
		wp_set_current_user( 0 );
		$this->assertStringContainsString( 'wp-login.php', Button::render( $this->post ) );
	}

	public function test_automatic_button_in_content() {
		$this->go_to( get_permalink( $this->post ) );
		$this->assertTrue( have_posts() );
		the_post();
		$html = apply_filters( 'the_content', 'Text' );
		$this->assertMatchesRegularExpression( '/Text.*data-wishcraft-button/s', $html );
		Settings::save( array( 'content_position' => 'before' ) );
		$this->assertMatchesRegularExpression( '/data-wishcraft-button.*Text/s', apply_filters( 'the_content', 'Text' ) );
		Settings::save( array( 'content_position' => 'none' ) );
		$this->assertStringNotContainsString( 'data-wishcraft-button', apply_filters( 'the_content', 'Text' ) );
	}

	public function test_page_lists_tabs_and_items() {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		Wishlists::toggle( $this->post );
		$second = Lists::create( array( 'owner' => $user, 'title' => 'Kitchen' ) );
		$html   = Page::render( array( 'url' => 'http://example.org/wishlist/' ) );
		$this->assertStringContainsString( 'Blue Lamp', $html );
		$this->assertStringContainsString( 'My wishlist', $html );
		$this->assertStringContainsString( '?list=' . $second, $html );
		$this->assertStringContainsString( 'action=wishcraft_remove', $html );
		$this->assertStringContainsString( 'data-wishcraft-dialog="list"', $html );
		$this->assertStringContainsString( '<option value="' . $second . '">Kitchen</option>', $html, 'Move to the other list.' );

		$html = Page::render( array( 'list' => $second ) );
		$this->assertStringContainsString( 'Nothing saved in this list yet.', $html );
		$this->assertStringContainsString( 'data-list="' . $second . '"', $html );
	}

	public function test_share_link_for_owners_of_shared_lists() {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$list = Lists::get( Lists::create( array( 'owner' => $user, 'is_default' => 1 ) ) );
		$this->assertStringNotContainsString( 'data-wishcraft-share-url', Page::render( array() ) );
		Lists::update( $list['id'], array( 'privacy' => 'link' ) );
		$this->assertStringContainsString( 'wishlist=' . $list['share_token'], Page::render( array() ) );
		Settings::save( array( 'sharing' => false ) );
		$this->assertStringNotContainsString( 'data-wishcraft-share-url', Page::render( array() ) );
	}

	public function test_page_does_not_show_someone_elses_list() {
		$owner = self::factory()->user->create();
		$list  = Lists::create( array( 'owner' => $owner, 'title' => 'Secret' ) );
		Items::add( $list, $this->post );
		wp_set_current_user( self::factory()->user->create() );
		$html = Page::render( array( 'list' => $list ) );
		$this->assertStringNotContainsString( 'Secret', $html );
		$this->assertStringContainsString( 'Your wishlist is empty.', $html );
	}

	public function test_shared_view() {
		$owner = self::factory()->user->create( array( 'display_name' => 'Ana' ) );
		$list  = Lists::get( Lists::create( array( 'owner' => $owner, 'title' => 'Birthday', 'privacy' => 'link' ) ) );
		Items::add( $list['id'], $this->post, 0, 2, 'Size M' );
		wp_set_current_user( 0 );
		$html = Page::render( array( 'token' => $list['share_token'] ) );
		$this->assertStringContainsString( 'A wishlist by Ana', $html );
		$this->assertStringContainsString( 'Blue Lamp', $html );
		$this->assertStringContainsString( 'Size M', $html );
		$this->assertStringContainsString( 'Qty 2', $html );
		$this->assertStringNotContainsString( 'wishcraft_remove', $html );
		$this->assertStringContainsString( 'data-wishcraft-button', $html, 'Visitors can save items for themselves.' );

		Lists::update( $list['id'], array( 'privacy' => 'private' ) );
		$this->assertStringContainsString( 'This wishlist is not available', Page::render( array( 'token' => $list['share_token'] ) ) );
	}

	public function test_guests_off_page_asks_to_log_in() {
		Settings::save( array( 'guests' => false ) );
		wp_set_current_user( 0 );
		$this->assertStringContainsString( 'Log in</a> to keep a wishlist', Page::render( array() ) );
	}

	public function test_counter() {
		wp_set_current_user( self::factory()->user->create() );
		Wishlists::toggle( $this->post );
		$this->assertStringContainsString( 'data-count="1"', Page::counter() );
	}

	public function test_view_endpoint_renders_for_the_page_url() {
		wp_set_current_user( self::factory()->user->create() );
		Wishlists::toggle( $this->post );
		do_action( 'rest_api_init' );
		$request = new WP_REST_Request( 'GET', '/wishcraft/v1/view' );
		$request->set_query_params( array( 'url' => 'http://example.org/wishlist/?list=5' ) );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertStringContainsString( 'Blue Lamp', $data['html'] );
		$this->assertStringContainsString( rawurlencode( 'http://example.org/wishlist/' ), $data['html'], 'Remove links return to the page, not the REST URL.' );

		$request->set_query_params( array( 'url' => 'https://evil.example/' ) );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertStringNotContainsString( 'evil.example', $data['html'] );
	}

	public function test_blocks_and_shortcodes() {
		foreach ( array( 'wishcraft/button', 'wishcraft/wishlist', 'wishcraft/counter' ) as $name ) {
			$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( $name ), $name );
		}
		$this->assertStringContainsString( 'data-wishcraft-button', do_shortcode( '[wishcraft_button id="' . $this->post . '"]' ) );
		$this->assertStringContainsString( 'wishcraft-button--icon', do_shortcode( '[wishcraft_button id="' . $this->post . '" label="no"]' ) );
		$this->assertStringContainsString( 'data-wishcraft-page', do_shortcode( '[wishcraft]' ) );
		$this->assertStringContainsString( 'data-wishcraft-counter', do_shortcode( '[wishcraft_count]' ) );
		$this->assertStringContainsString( 'data-wishcraft-page', do_blocks( '<!-- wp:wishcraft/wishlist /-->' ) );
		$this->assertStringContainsString( 'data-wishcraft-counter', do_blocks( '<!-- wp:wishcraft/counter /-->' ) );
	}

	public function test_legacy_widget_keeps_its_id_base() {
		global $wp_widget_factory;
		$this->assertArrayHasKey( 'WishCraft\Legacy\Widget', $wp_widget_factory->widgets );
		$this->assertSame( 'wishlistwidget', $wp_widget_factory->widgets['WishCraft\Legacy\Widget']->id_base );

		wp_set_current_user( self::factory()->user->create() );
		Wishlists::toggle( $this->post );
		ob_start();
		the_widget(
			'WishCraft\Legacy\Widget',
			array( 'title' => 'Saved' ),
			array(
				'before_title' => '<h2>',
				'after_title'  => '</h2>',
			)
		);
		$html = ob_get_clean();
		$this->assertStringContainsString( '<h2>Saved</h2>', $html );
		$this->assertStringContainsString( 'Blue Lamp', $html );
	}

	public function test_activation_creates_the_page_once() {
		$page = Plugin::create_page();
		$this->assertGreaterThan( 0, $page );
		$this->assertSame( $page, Settings::get( 'page' ) );
		$this->assertStringContainsString( 'wp:wishcraft/wishlist', get_post( $page )->post_content );
		$this->assertSame( $page, Plugin::create_page() );
		wp_trash_post( $page );
		$this->assertNotSame( $page, Plugin::create_page() );
	}

	public function test_guest_cookie_toggle_and_merge_through_wishlists() {
		wp_set_current_user( 0 );
		$result = Wishlists::toggle( $this->post );
		$this->assertTrue( $result['added'] );
		$this->assertArrayHasKey( Visitor::COOKIE, $_COOKIE );
		$user = self::factory()->user->create();
		$this->assertSame( array( $this->post ), wp_list_pluck( Items::for_list( Lists::default_for_user( $user )['id'] ), 'object_id' ) );
	}
}
