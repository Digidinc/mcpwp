<?php
/**
 * #1112 — menu-item type parsing must be strict before a route can decide
 * whether object_id names a post. The REST, MCP, mcpwp_call, batch, WebMCP,
 * and OAuth transports all arrive at this same post-reference gate.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-webmcp.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-menus.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';

/** Exposes the shared pre-dispatch gate without changing authentication. */
class Mcpwp_Menu_Item_Type_1112_Harness {
	use Mcpwp_Api_Auth;

	public function check( WP_REST_Request $request ) {
		return $this->check_post_naming_params_category( $request );
	}

	public function set_key_record( array $record ) {
		$property = new ReflectionProperty( $this, 'current_api_key_record' );
		$property->setAccessible( true );
		$property->setValue( $this, $record );
	}
}

final class MenuItemTypeTransport1112Test extends TestCase {

	private const PRODUCT_ID = 111201;
	/** @var array */
	private $saved_users;
	/** @var array */
	private $saved_user_caps;
	/** @var int */
	private $saved_current_user;
	/** @var mixed */
	private $saved_rest_auth_cookie;

	protected function setUp(): void {
		$this->saved_users            = $GLOBALS['mcpwp_test_users'];
		$this->saved_user_caps        = $GLOBALS['mcpwp_test_user_caps'];
		$this->saved_current_user     = $GLOBALS['mcpwp_test_current_user'];
		$this->saved_rest_auth_cookie = $GLOBALS['wp_rest_auth_cookie'] ?? null;
		$GLOBALS['mcpwp_test_options']       = array();
		$GLOBALS['mcpwp_test_transients']    = array();
		$GLOBALS['mcpwp_test_current_user']  = 0;
		$GLOBALS['mcpwp_test_filters']       = array();
		$GLOBALS['mcpwp_test_post_ref_queries'] = array();
		$GLOBALS['_mcpwp_test_posts']        = array(
			self::PRODUCT_ID => new WP_Post(
				array(
					'ID'          => self::PRODUCT_ID,
					'post_type'   => 'product',
					'post_status' => 'publish',
				)
			),
		);
		$GLOBALS['mcpwp_test_dispatched_routes']   = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
		$GLOBALS['_mcpwp_test_nav_menu_item_writes'] = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['mcpwp_test_filters'] = array();
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
		$GLOBALS['mcpwp_test_users']            = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']        = $this->saved_user_caps;
		$GLOBALS['mcpwp_test_current_user']     = $this->saved_current_user;
		if ( null === $this->saved_rest_auth_cookie ) {
			unset( $GLOBALS['wp_rest_auth_cookie'] );
		} else {
			$GLOBALS['wp_rest_auth_cookie'] = $this->saved_rest_auth_cookie;
		}
	}

	private function gate(): Mcpwp_Menu_Item_Type_1112_Harness {
		$gate = new Mcpwp_Menu_Item_Type_1112_Harness();
		$gate->set_key_record( array( 'role' => 'custom', 'tool_categories' => array( 'site' ) ) );
		return $gate;
	}

	/** Every accepted spelling needs its own malformed near-neighbour. */
	public function malformed_type_provider(): array {
		$types = array( 'custom', 'post_type', 'taxonomy', 'post', 'page', 'category', 'tag', 'post_tag' );
		$cases = array();
		foreach ( $types as $type ) {
			$cases[ $type . ' case' ]    = array( strtoupper( $type ) );
			$cases[ $type . ' padding' ] = array( ' ' . $type . ' ' );
			$cases[ $type . ' nul' ]     = array( $type . "\0" );
			$lookalike = array(
				'custom'    => "\xD1\x81ustom",
				'post_type' => "\xD1\x80ost_type",
				'taxonomy'  => "t\xD0\xB0xonomy",
				'post'      => "\xD1\x80ost",
				'page'      => "p\xD0\xB0ge",
				'category'  => "c\xD0\xB0tegory",
				'tag'       => "t\xD0\xB0g",
				'post_tag'  => "\xD1\x80ost_tag",
			);
			$cases[ $type . ' lookalike' ] = array( $lookalike[ $type ] );
			$cases[ $type . ' array' ]   = array( array( $type ) );
		}
		return $cases;
	}

	/**
	 * The direct REST add route is the base case. A malformed explicit type
	 * cannot be allowed as a custom item or skipped as a non-post reference.
	 *
	 * @dataProvider malformed_type_provider
	 */
	public function test_direct_menu_item_write_refuses_every_malformed_type( $type ): void {
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/menus/16/items',
			array( 'type' => $type, 'object' => 'product', 'object_id' => self::PRODUCT_ID, 'title' => 'Example' )
		);

		$result = $this->gate()->check( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_menu_item_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Menu creation carries its item writes in items[]. This is also the shape
	 * used after /mcp tools/call, mcpwp_call, wp_batch, and WebMCP unwrap their
	 * arguments, so it pins the nested door rather than only the scalar route.
	 *
	 * @dataProvider malformed_type_provider
	 */
	public function test_nested_menu_item_write_refuses_every_malformed_type( $type ): void {
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/menus',
			array(
				'name'  => 'Example',
				'items' => array( array( 'type' => $type, 'object' => 'product', 'object_id' => self::PRODUCT_ID ) ),
			)
		);

		$result = $this->gate()->check( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_menu_item_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_canonical_post_type_is_still_gated_for_a_limited_key(): void {
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/menus/16/items',
			array( 'type' => 'post_type', 'object' => 'product', 'object_id' => self::PRODUCT_ID, 'title' => 'Example' )
		);

		$result = $this->gate()->check( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_missing_type_remains_the_handler_default_only(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/menus/16/items', array( 'title' => 'Example', 'url' => 'https://example.test' ) );
		$this->assertTrue( $this->gate()->check( $request ) );
	}

	public function test_explicit_null_type_is_not_treated_as_missing(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/menus/16/items', array( 'type' => null, 'title' => 'Example' ) );
		$result  = $this->gate()->check( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_menu_item_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * The lightweight test server records route dispatch but does not run the
	 * registered controller. Run the production permission chokepoint there,
	 * exactly where WordPress would run it before a menu write handler.
	 */
	private function install_inner_menu_gate(): ArrayObject {
		$gate = $this->gate();
		$seen = new ArrayObject();
		add_filter(
			'rest_dispatch_request',
			static function ( $pre, $request ) use ( $gate, &$seen ) {
				if ( 0 === strpos( $request->get_route(), '/mcpwp/v1/menus' ) ) {
					$seen[] = $request;
					return $gate->check( $request );
				}
				return $pre;
			},
			10,
			2
		);
		return $seen;
	}

	private function assert_inner_gate_refusal( ArrayObject $seen, $type ): void {
		$this->assertNotEmpty( $seen, 'The wrapper must dispatch its real menu route.' );
		$requests = $seen->getArrayCopy();
		$request  = end( $requests );
		$this->assertSame( $type, $request->get_param( 'type' ), 'The wrapper must not transform the type before the common gate sees it.' );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menu_item_writes'], 'The write handler must not run after the gate refuses.' );
	}

	private function mcp_request( $name, array $arguments ): WP_REST_Request {
		return new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array( 'name' => $name, 'arguments' => $arguments ),
			)
		);
	}

	/**
	 * @dataProvider malformed_type_provider
	 */
	public function test_tools_call_reaches_the_gate_before_a_menu_write( $type ): void {
		$seen = $this->install_inner_menu_gate();
		$result = ( new Mcpwp_REST_MCP() )->handle_mcp(
			$this->mcp_request( 'wp_add_menu_item', array( 'menu_id' => 16, 'title' => 'Example', 'type' => $type, 'object' => 'product', 'object_id' => self::PRODUCT_ID ) )
		)->get_data();

		$this->assertTrue( $result['result']['isError'] );
		$this->assert_inner_gate_refusal( $seen, $type );
	}

	/**
	 * @dataProvider malformed_type_provider
	 */
	public function test_mcpwp_call_reaches_the_gate_before_a_menu_write( $type ): void {
		$seen = $this->install_inner_menu_gate();
		$result = ( new Mcpwp_REST_MCP() )->handle_mcp(
			$this->mcp_request( 'mcpwp_call', array( 'name' => 'wp_add_menu_item', 'arguments' => array( 'menu_id' => 16, 'title' => 'Example', 'type' => $type, 'object' => 'product', 'object_id' => self::PRODUCT_ID ) ) )
		)->get_data();

		$this->assertTrue( $result['result']['isError'] );
		$this->assert_inner_gate_refusal( $seen, $type );
	}

	/**
	 * @dataProvider malformed_type_provider
	 */
	public function test_batch_reaches_the_gate_before_a_menu_write( $type ): void {
		$seen = $this->install_inner_menu_gate();
		$response = ( new Mcpwp_REST_Batch() )->handle_batch(
			new WP_REST_Request(
				'POST',
				'/mcpwp/v1/batch',
				array( 'operations' => array( array( 'method' => 'POST', 'path' => '/menus/16/items', 'body' => array( 'title' => 'Example', 'type' => $type, 'object' => 'product', 'object_id' => self::PRODUCT_ID ) ) ) )
			)
		);

		$this->assertSame( 400, $response->get_data()['results'][0]['status'] );
		$this->assertSame( 'invalid_menu_item_type', $response->get_data()['results'][0]['data']['code'] );
		$this->assert_inner_gate_refusal( $seen, $type );
	}

	public function test_webmcp_cannot_reach_menu_writes(): void {
		$GLOBALS['mcpwp_test_current_user'] = 2;
		$GLOBALS['wp_rest_auth_cookie']     = true;
		update_option( 'mcpwp_settings', array( 'webmcp_admin_enabled' => true ) );
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/webmcp/call',
			array( 'name' => 'wp_add_menu_item', 'arguments' => array( 'menu_id' => 16, 'title' => 'Example', 'type' => 'Page' ) ),
			array( 'Origin' => 'https://example.com', 'X-WP-Nonce' => 'test-nonce' )
		);

		$result = ( new Mcpwp_REST_WebMCP() )->handle_call( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'webmcp_tool_not_allowed', $result->get_error_code() );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_routes'], 'WebMCP does not expose a menu-item write tool.' );
	}

	/**
	 * OAuth uses the same REST permission callback before the handler.
	 *
	 * @dataProvider malformed_type_provider
	 */
	public function test_oauth_principal_is_refused_by_the_menu_type_gate( $type ): void {
		$user_id = 111202;
		$GLOBALS['mcpwp_test_users'][]      = (object) array( 'ID' => $user_id, 'roles' => array( 'editor' ), 'user_login' => 'editor1112' );
		$GLOBALS['mcpwp_test_user_caps'][$user_id] = array( 'read' => true, 'publish_posts' => true, 'edit_theme_options' => true, 'manage_options' => true );
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'author' );
		$auth  = $this->gate();
		$token = $auth->issue_oauth_access_token( array( 'read', 'write', 'admin' ), HOUR_IN_SECONDS, array( 'client_id' => 'menu-test' ) )['access_token']; // A user-bound token never carries admin (#1149), so it is refused on scope before the type gate: use a client-bound one to reach the gate.
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/menus/16/items',
			array( 'type' => $type, 'object' => 'product', 'object_id' => self::PRODUCT_ID, 'title' => 'Example' ),
			array( 'Authorization' => 'Bearer ' . $token )
		);

		// verify_api_key() authenticates the bearer then runs the common
		// post-naming gate as its final permission step.
		$result = $auth->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_menu_item_type', $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menu_item_writes'] );
	}
}
