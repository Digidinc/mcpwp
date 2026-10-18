<?php
/**
 * "Add to ChatGPT" connector link.
 *
 * The link is the site's MCP endpoint with an ordinary scoped key in
 * `?api_key=`. These tests pin the two promises the Setup page makes about
 * it: the key is least-privilege (Editor role, read + write, never admin),
 * and rotating or revoking cuts the old link off at once.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-chatgpt-connector.php';

class Mcpwp_ChatGpt_Connector_Test_Auth {
	use Mcpwp_Api_Auth;
}

final class ChatGptConnectorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters']      = array();
		$_GET                               = array();
	}

	protected function tearDown(): void {
		$_GET = array();
	}

	/**
	 * Authenticate a request the way ChatGPT sends it: the key only in the URL.
	 */
	private function verify_with_link( $auth, string $url, string $method, string $route ) {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $params );
		$_GET = $params;

		return $auth->verify_api_key( new WP_REST_Request( $method, $route ) );
	}

	public function test_link_is_the_mcp_endpoint_with_the_key_in_the_query(): void {
		$auth      = new Mcpwp_ChatGpt_Connector_Test_Auth();
		$connector = new Mcpwp_ChatGPT_Connector( $auth );

		$result = $connector->create_or_rotate();

		$this->assertIsArray( $result );
		$this->assertStringStartsWith( 'https://example.com/wp-json/mcpwp/v1/mcp?api_key=mcpwp_', $result['url'] );
		$this->assertArrayNotHasKey( 'key', $result['key'], 'Plaintext key must only travel inside the URL.' );
	}

	public function test_key_is_editor_read_write_never_admin(): void {
		$auth      = new Mcpwp_ChatGpt_Connector_Test_Auth();
		$connector = new Mcpwp_ChatGPT_Connector( $auth );
		$connector->create_or_rotate();

		$active = $connector->get_active_key();

		$this->assertSame( 'editor', $active['role'] );
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
}
