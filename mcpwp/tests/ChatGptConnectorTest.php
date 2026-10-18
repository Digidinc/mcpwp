<?php
/**
 * "Add to ChatGPT" connector link.
 *
 * The link is the site's MCP endpoint with an ordinary scoped key in
 * `?api_key=`. These tests pin the promises the Setup page makes about it,
 * one test per "Can" / "Cannot" claim, and that rotating or revoking cuts
 * the old link off — including against a request in flight (#1090 P1-2).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-chatgpt-connector.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-broker-auth.php';

class Mcpwp_ChatGpt_Connector_Test_Auth {
	use Mcpwp_Api_Auth;
}

final class ChatGptConnectorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$_GET                               = array();
	}

	protected function tearDown(): void {
		$_GET                               = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array();
	}

	/**
	 * Authenticate a request the way ChatGPT sends it: the key only in the URL.
	 */
	private function verify_with_link( $auth, string $url, string $method, string $route, array $body = array() ) {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $params );
		$_GET = $params;

		return $auth->verify_api_key( new WP_REST_Request( $method, $route, $body ) );
	}

	private function link(): array {
		$auth      = new Mcpwp_ChatGpt_Connector_Test_Auth();
		$connector = new Mcpwp_ChatGPT_Connector( $auth );
		return array( $auth, $connector->create_or_rotate()['url'] );
	}

	/**
	 * Every "Can" on the Setup page.
	 *
	 * @return array<string,array{string,string,array}>
	 */
	public static function can_routes(): array {
		return array(
			'create a draft post'          => array( 'POST', '/mcpwp/v1/posts', array( 'status' => 'draft' ) ),
			'edit a page'                  => array( 'PUT', '/mcpwp/v1/pages/5', array( 'title' => 'x' ) ),
			'unpublish a page (disclosed)' => array( 'PUT', '/mcpwp/v1/pages/5', array( 'status' => 'draft' ) ),
			'trash a page (disclosed)'     => array( 'DELETE', '/mcpwp/v1/pages/5', array() ),
			'upload media'                 => array( 'POST', '/mcpwp/v1/media/from-url', array() ),
			'manage categories'            => array( 'POST', '/mcpwp/v1/categories', array() ),
			'set SEO fields'               => array( 'POST', '/mcpwp/v1/seo/5', array() ),
			'edit an Elementor layout'     => array( 'POST', '/mcpwp/v1/elementor/5', array() ),
			'kit CSS (disclosed)'          => array( 'POST', '/mcpwp/v1/elementor/kit-css', array( 'mode' => 'replace' ) ),
			'global styles (disclosed)'    => array( 'POST', '/mcpwp/v1/elementor/globals', array() ),
		);
	}

	/**
	 * Every "Cannot" on the Setup page.
	 *
	 * @return array<string,array{string,string,array}>
	 */
	public static function cannot_routes(): array {
		return array(
			'publish a new post'               => array( 'POST', '/mcpwp/v1/posts', array( 'status' => 'publish' ) ),
			'publish an existing page'         => array( 'PUT', '/mcpwp/v1/pages/5', array( 'status' => 'publish' ) ),
			'create a site-wide theme header'  => array( 'POST', '/mcpwp/v1/theme-builder/templates', array( 'type' => 'header' ) ),
			'retarget a theme template'        => array( 'POST', '/mcpwp/v1/theme-builder/templates/9/assign', array() ),
			'create an Elementor template'     => array( 'POST', '/mcpwp/v1/elementor/templates', array() ),
			'change site settings'             => array( 'PUT', '/mcpwp/v1/settings', array() ),
			'change menus'                     => array( 'POST', '/mcpwp/v1/menus', array() ),
			'manage plugins'                   => array( 'POST', '/mcpwp/v1/plugins', array() ),
			'manage users'                     => array( 'POST', '/mcpwp/v1/users', array() ),
			'create or see API keys'           => array( 'GET', '/mcpwp/v1/api-keys', array() ),
			'site custom CSS'                  => array( 'POST', '/mcpwp/v1/theme/custom-css', array() ),
		);
	}

	/**
	 * @dataProvider can_routes
	 */
	public function test_every_can_claim_holds( string $method, string $route, array $body ): void {
		list( $auth, $url ) = $this->link();
		$this->assertTrue( $this->verify_with_link( $auth, $url, $method, $route, $body ) );
	}

	/**
	 * @dataProvider cannot_routes
	 */
	public function test_every_cannot_claim_holds( string $method, string $route, array $body ): void {
		list( $auth, $url ) = $this->link();
		$this->assertInstanceOf( WP_Error::class, $this->verify_with_link( $auth, $url, $method, $route, $body ) );
	}

	public function test_a_request_in_flight_cannot_resurrect_a_revoked_key(): void {
		list( $auth, $url ) = $this->link();
		$connector          = new Mcpwp_ChatGPT_Connector( $auth );

		// A successful request touches last-used...
		$this->assertTrue( $this->verify_with_link( $auth, $url, 'POST', '/mcpwp/v1/posts' ) );
		$store_before = get_option( 'mcpwp_api_keys' );

		// ...and must never write the key store, so it holds no stale snapshot
		// it could write back over a revoke. Clear the once-a-minute throttle so
		// this request really does take the touch path.
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys_last_used'] = array();
		$this->assertTrue( $this->verify_with_link( $auth, $url, 'GET', '/mcpwp/v1/posts' ) );
		$this->assertNotEmpty( get_option( 'mcpwp_api_keys_last_used' ), 'The touch path must have run.' );
		$this->assertSame( $store_before, get_option( 'mcpwp_api_keys' ) );
		foreach ( get_option( 'mcpwp_api_keys' ) as $record ) {
			$this->assertTrue( empty( $record['last_used_at'] ), 'The request path wrote the key store.' );
		}

		$connector->revoke();
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys_last_used'] = array();
		$this->verify_with_link( $auth, $url, 'POST', '/mcpwp/v1/posts' );
		$this->assertInstanceOf( WP_Error::class, $this->verify_with_link( $auth, $url, 'POST', '/mcpwp/v1/posts' ) );
	}

	public function test_last_used_is_still_reported(): void {
		list( $auth, $url ) = $this->link();
		$connector          = new Mcpwp_ChatGPT_Connector( $auth );
		$this->assertNull( $connector->get_active_key()['last_used_at'] );

		$this->verify_with_link( $auth, $url, 'POST', '/mcpwp/v1/posts' );

		$this->assertNotEmpty( $connector->get_active_key()['last_used_at'] );
	}

	public function test_link_is_the_mcp_endpoint_with_the_key_in_the_query(): void {
		$auth      = new Mcpwp_ChatGpt_Connector_Test_Auth();
		$connector = new Mcpwp_ChatGPT_Connector( $auth );

		$result = $connector->create_or_rotate();

		$this->assertIsArray( $result );
		$this->assertStringStartsWith( 'https://example.com/wp-json/mcpwp/v1/mcp?api_key=mcpwp_', $result['url'] );
		$this->assertArrayNotHasKey( 'key', $result['key'], 'Plaintext key must only travel inside the URL.' );
	}

	public function test_key_is_limited_categories_read_write_never_admin(): void {
		$auth      = new Mcpwp_ChatGpt_Connector_Test_Auth();
		$connector = new Mcpwp_ChatGPT_Connector( $auth );
		$connector->create_or_rotate();

		$active = $connector->get_active_key();

		$this->assertSame( 'custom', $active['role'] );
		$this->assertSame( Mcpwp_ChatGPT_Connector::CATEGORIES, $active['tool_categories'] );
		$this->assertNotContains( 'elementor-theme', $active['tool_categories'] );
		$this->assertNotContains( 'elementor-templates', $active['tool_categories'] );
		$this->assertSame( array( 'read', 'write' ), $active['scopes'] );
		$this->assertNotContains( 'admin', $active['scopes'] );
	}

	public function test_link_authenticates_a_write_but_not_an_admin_route(): void {
		$auth      = new Mcpwp_ChatGpt_Connector_Test_Auth();
		$connector = new Mcpwp_ChatGPT_Connector( $auth );
		$url       = $connector->create_or_rotate()['url'];

		$this->assertTrue( $this->verify_with_link( $auth, $url, 'POST', '/mcpwp/v1/posts' ) );

		$denied = $this->verify_with_link( $auth, $url, 'PUT', '/mcpwp/v1/settings' );
		$this->assertInstanceOf( WP_Error::class, $denied );
	}

	public function test_rotate_kills_the_old_link_and_keeps_exactly_one(): void {
		$auth      = new Mcpwp_ChatGpt_Connector_Test_Auth();
		$connector = new Mcpwp_ChatGPT_Connector( $auth );

		$old_url = $connector->create_or_rotate()['url'];
		$new_url = $connector->create_or_rotate()['url'];

		$this->assertNotSame( $old_url, $new_url );
		$this->assertInstanceOf( WP_Error::class, $this->verify_with_link( $auth, $old_url, 'POST', '/mcpwp/v1/posts' ) );
		$this->assertTrue( $this->verify_with_link( $auth, $new_url, 'POST', '/mcpwp/v1/posts' ) );

		$live = array_filter(
			$auth->list_scoped_api_keys( false ),
			static function ( $key ) {
				return 'ChatGPT connector' === $key['label'];
			}
		);
		$this->assertCount( 1, $live );
	}

	public function test_revoke_kills_the_link_and_clears_status(): void {
		$auth      = new Mcpwp_ChatGpt_Connector_Test_Auth();
		$connector = new Mcpwp_ChatGPT_Connector( $auth );
		$url       = $connector->create_or_rotate()['url'];

		$this->assertTrue( $connector->revoke() );
		$this->assertNull( $connector->get_active_key() );
		$this->assertInstanceOf( WP_Error::class, $this->verify_with_link( $auth, $url, 'POST', '/mcpwp/v1/posts' ) );
		$this->assertFalse( $connector->revoke(), 'A second revoke has nothing left to revoke.' );
	}

	public function test_rotation_leaves_other_keys_alone(): void {
		$auth      = new Mcpwp_ChatGpt_Connector_Test_Auth();
		$other     = $auth->create_scoped_api_key( 'ChatGPT connector', array( 'read' ), 'author' );
		$connector = new Mcpwp_ChatGPT_Connector( $auth );

		$connector->create_or_rotate();
		$connector->create_or_rotate();
		$connector->revoke();

		$ids = wp_list_pluck( $auth->list_scoped_api_keys( false ), 'id' );
		$this->assertContains( $other['id'], $ids, 'A hand-made key with the same label must survive rotate and revoke.' );
	}

	public function test_key_revoked_elsewhere_reads_as_no_active_link(): void {
		$auth      = new Mcpwp_ChatGpt_Connector_Test_Auth();
		$connector = new Mcpwp_ChatGPT_Connector( $auth );
		$connector->create_or_rotate();

		$auth->revoke_scoped_api_key( get_option( Mcpwp_ChatGPT_Connector::OPTION_KEY_ID ) );

		$this->assertNull( $connector->get_active_key() );
	}
	public function test_key_has_full_entropy(): void {
		list( , $url ) = $this->link();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertMatchesRegularExpression( '/^mcpwp_[0-9a-f]{48}$/', $q['api_key'], '24 random bytes, hex-encoded.' );
	}

	private function admin_post( string $action, string $nonce = 'test-nonce' ): array {
		return array(
			'mcpwp_chatgpt_connector_action' => $action,
			'mcpwp_chatgpt_connector_nonce'  => $nonce,
		);
	}

	public function test_handler_creates_for_an_admin_with_a_valid_nonce(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$connector                          = new Mcpwp_ChatGPT_Connector( new Mcpwp_ChatGpt_Connector_Test_Auth() );

		$result = $connector->handle_request( $this->admin_post( 'create' ) );

		$this->assertSame( 'updated', $result['type'] );
		$this->assertStringContainsString( 'api_key=mcpwp_', $result['url'] );
		$this->assertNotNull( $connector->get_active_key() );
	}

	public function test_handler_refuses_a_bad_nonce_and_mints_nothing(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$auth                               = new Mcpwp_ChatGpt_Connector_Test_Auth();
		$connector                          = new Mcpwp_ChatGPT_Connector( $auth );

		$result = $connector->handle_request( $this->admin_post( 'create', 'forged' ) );

		$this->assertSame( 'error', $result['type'] );
		$this->assertArrayNotHasKey( 'url', $result );
		$this->assertSame( array(), $auth->list_scoped_api_keys( true ) );
	}

	public function test_handler_refuses_a_user_who_cannot_activate_plugins(): void {
		$GLOBALS['mcpwp_test_current_user']    = 7;
		$GLOBALS['mcpwp_test_user_caps'][7]    = array( 'activate_plugins' => false, 'edit_posts' => true );
		$auth                                  = new Mcpwp_ChatGpt_Connector_Test_Auth();
		$connector                             = new Mcpwp_ChatGPT_Connector( $auth );

		$create = $connector->handle_request( $this->admin_post( 'create' ) );
		$this->assertSame( 'error', $create['type'] );
		$this->assertSame( array(), $auth->list_scoped_api_keys( true ) );

		// And cannot revoke an existing link either.
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$connector->handle_request( $this->admin_post( 'create' ) );
		$GLOBALS['mcpwp_test_current_user'] = 7;
		$connector->handle_request( $this->admin_post( 'revoke' ) );
		$this->assertNotNull( $connector->get_active_key() );
	}

	public function test_handler_ignores_unrelated_posts(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$connector                          = new Mcpwp_ChatGPT_Connector( new Mcpwp_ChatGpt_Connector_Test_Auth() );
		$this->assertNull( $connector->handle_request( array( 'mcpwp_regenerate_key' => '1' ) ) );
	}

	public function test_oauth_discovery_conflict_is_detected(): void {
		$this->assertSame( '', Mcpwp_ChatGPT_Connector::oauth_discovery_conflict() );

		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
		$this->assertSame( 'legacy', Mcpwp_ChatGPT_Connector::oauth_discovery_conflict() );

		update_option(
			'mcpwp_broker_enrollment',
			array( 'site_id' => 's1', 'site_credential' => 'c', 'audience' => 'https://example.com' )
		);
		$this->assertSame( 'broker', Mcpwp_ChatGPT_Connector::oauth_discovery_conflict() );
	}
}
