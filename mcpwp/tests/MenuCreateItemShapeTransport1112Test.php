<?php
/**
 * #1112 round 1 — menu creation must validate every item before it creates
 * a menu.  A malformed list member cannot turn a rejected write into an
 * empty menu.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-site-manager.php';

class Mcpwp_Menu_Create_Shape_1112_Harness {
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

final class MenuCreateItemShapeTransport1112Test extends TestCase {
	/** @var mixed */
	private $saved_fs;

	protected function setUp(): void {
		$this->saved_fs = $GLOBALS['mcpwp_test_fs'];
		$GLOBALS['mcpwp_test_fs']                         = new Mcpwp_Test_Fs_Stub( true, true, false, 'pro' );
		$this->reset_license();
		$GLOBALS['mcpwp_test_options']                    = array();
		$GLOBALS['mcpwp_test_transients']                 = array();
		$GLOBALS['mcpwp_test_current_user']               = 0;
		$GLOBALS['mcpwp_test_filters']                    = array();
		$GLOBALS['mcpwp_test_dispatched_routes']          = array();
		$GLOBALS['mcpwp_test_dispatched_requests']        = array();
		$GLOBALS['_mcpwp_test_nav_menus']                 = array();
		$GLOBALS['_mcpwp_test_nav_menu_item_writes']      = array();
		Mcpwp_REST_Batch::$is_batch_sub_request           = false;
		Mcpwp_REST_MCP::reset_verdict_memo();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
		$GLOBALS['mcpwp_test_fs'] = $this->saved_fs;
		$this->reset_license();
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
	}

	private function reset_license(): void {
		$property = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	public function malformed_item_provider(): array {
		$invalid = array(
			'string'       => 'x',
			'null'         => null,
			'integer'      => 5,
			'empty array'  => array(),
			'nested array' => array( array( 'type' => 'custom' ) ),
			'object'       => (object) array( 'type' => 'custom' ),
		);
		$cases = array();
		foreach ( $invalid as $label => $item ) {
			$cases[ $label ] = array( array( $item ) );
			$cases[ 'mixed ' . $label ] = array(
				array(
					array( 'title' => 'Good', 'type' => 'custom', 'url' => 'https://example.test' ),
					$item,
				)
			);
		}
		return $cases;
	}

	private function gate(): Mcpwp_Menu_Create_Shape_1112_Harness {
		$gate = new Mcpwp_Menu_Create_Shape_1112_Harness();
		$gate->set_key_record( array( 'role' => 'custom', 'tool_categories' => array( 'site' ) ) );
		return $gate;
	}

	private function request( array $items ): WP_REST_Request {
		return new WP_REST_Request(
			'POST',
			'/mcpwp/v1/menus',
			array( 'name' => 'Example', 'items' => $items )
		);
	}

	private function assert_invalid_item( $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_menu_item', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menus'] );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menu_item_writes'] );
	}

	private function install_menu_gate(): ArrayObject {
		$gate = $this->gate();
		$seen = new ArrayObject();
		add_filter(
			'rest_dispatch_request',
			static function ( $pre, $request ) use ( $gate, &$seen ) {
				if ( '/mcpwp/v1/batch' === $request->get_route() ) {
					return ( new Mcpwp_REST_Batch() )->handle_batch( $request );
				}
				if ( '/mcpwp/v1/menus' === $request->get_route() ) {
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

	private function assert_transport_refusal( ArrayObject $seen, array $items ): void {
		$this->assertNotEmpty( $seen, 'The transport must dispatch the /menus route.' );
		$requests = $seen->getArrayCopy();
		$request  = end( $requests );
		$this->assertSame( $items, $request->get_param( 'items' ) );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menus'] );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menu_item_writes'] );
	}

	/**
	 * @dataProvider malformed_item_provider
	 */
	public function test_gate_refuses_each_malformed_item_before_creation( array $items ): void {
		$this->assert_invalid_item( $this->gate()->check( $this->request( $items ) ) );
	}

	/**
	 * @dataProvider malformed_item_provider
	 */
	public function test_manager_refuses_each_malformed_item_without_creating_a_menu( array $items ): void {
		$this->assert_invalid_item( ( new Mcpwp_Site_Manager() )->create_menu( array( 'name' => 'Example', 'items' => $items ) ) );
	}

	/**
	 * @dataProvider malformed_item_provider
	 */
	public function test_tools_call_refuses_malformed_items_before_menu_creation( array $items ): void {
		$seen   = $this->install_menu_gate();
		$result = ( new Mcpwp_REST_MCP() )->handle_mcp(
			$this->mcp_request( 'wp_create_menu', array( 'name' => 'Example', 'items' => $items ) )
		)->get_data();

		$this->assertTrue( $result['result']['isError'] );
		$this->assert_transport_refusal( $seen, $items );
	}

	/**
	 * @dataProvider malformed_item_provider
	 */
	public function test_mcpwp_call_refuses_malformed_items_before_menu_creation( array $items ): void {
		$seen   = $this->install_menu_gate();
		$result = ( new Mcpwp_REST_MCP() )->handle_mcp(
			$this->mcp_request( 'mcpwp_call', array( 'name' => 'wp_create_menu', 'arguments' => array( 'name' => 'Example', 'items' => $items ) ) )
		)->get_data();

		$this->assertTrue( $result['result']['isError'] );
		$this->assert_transport_refusal( $seen, $items );
	}

	/**
	 * @dataProvider malformed_item_provider
	 */
	public function test_rest_batch_refuses_malformed_items_before_menu_creation( array $items ): void {
		$seen     = $this->install_menu_gate();
		$response = ( new Mcpwp_REST_Batch() )->handle_batch(
			new WP_REST_Request( 'POST', '/mcpwp/v1/batch', array( 'operations' => array( array( 'method' => 'POST', 'path' => '/menus', 'body' => array( 'name' => 'Example', 'items' => $items ) ) ) ) )
		);

		$this->assertSame( 400, $response->get_data()['results'][0]['status'] );
		$this->assertSame( 'invalid_menu_item', $response->get_data()['results'][0]['data']['code'] );
		$this->assert_transport_refusal( $seen, $items );
	}

	/**
	 * @dataProvider malformed_item_provider
	 */
	public function test_wp_batch_refuses_malformed_items_before_menu_creation( array $items ): void {
		$seen   = $this->install_menu_gate();
		$result = ( new Mcpwp_REST_MCP() )->handle_mcp(
			$this->mcp_request(
				'wp_batch',
				array( 'operations' => array( array( 'method' => 'POST', 'path' => '/menus', 'body' => array( 'name' => 'Example', 'items' => $items ) ) ) )
			)
		)->get_data();

		$this->assertArrayHasKey( 'result', $result );
		$body = json_decode( $result['result']['content'][0]['text'], true );
		$this->assertSame( 400, $body['results'][0]['status'] );
		$this->assertSame( 'invalid_menu_item', $body['results'][0]['data']['code'] );
		$this->assert_transport_refusal( $seen, $items );
	}
}
