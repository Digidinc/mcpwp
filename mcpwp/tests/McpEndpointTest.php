<?php
use PHPUnit\Framework\TestCase;

final class McpEndpointTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array( 'mcpwp_api_key' => 'test-key', 'mcpwp_settings' => array( 'enable_logging' => true ), 'mcpwp_rate_limit_settings' => array( 'enabled' => false ) );
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_rest_dispatcher'] = null;
		$GLOBALS['wpdb']->inserted = array();
	}
	private function call( $method, $params = array() ) {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array(), array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ) );
		return ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();
	}
	private function call_authenticated( $method, $params = array() ) {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => 'test-key' ), array( 'jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params ) );
		$controller = new Mcpwp_REST_MCP();
		$this->assertTrue( $controller->check_permission( $request ) );
		return $controller->handle_mcp( $request )->get_data();
	}
	private function install_test_router(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		register_rest_route( 'mcpwp/v1', '/site-info', array( 'methods' => 'GET', 'permission_callback' => array( $auth, 'verify_api_key' ), 'callback' => static function () { return new WP_REST_Response( array( 'name' => 'Example Site' ), 200 ); } ) );
		register_rest_route( 'mcpwp/v1', '/posts/(?P<id>\\d+)', array( 'methods' => 'GET', 'permission_callback' => array( $auth, 'verify_api_key' ), 'callback' => static function () { return new WP_REST_Response( array( 'code' => 'not_found', 'message' => 'Post not found.', 'data' => array( 'status' => 404 ) ), 404 ); } ) );
		$GLOBALS['_mcpwp_test_rest_dispatcher'] = static function ( $request ) {
			foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $registration ) {
				$pattern = '#^/mcpwp/v1' . $registration['route'] . '$#';
				if ( 1 !== preg_match( $pattern, $request->get_route(), $matches ) ) { continue; }
				$configurations = isset( $registration['args']['methods'] ) ? array( $registration['args'] ) : $registration['args'];
				foreach ( $configurations as $configuration ) {
					if ( ! in_array( $request->get_method(), explode( ',', $configuration['methods'] ), true ) ) { continue; }
					foreach ( $matches as $key => $value ) { if ( is_string( $key ) ) { $request->set_param( $key, $value ); } }
					$permission = call_user_func( $configuration['permission_callback'], $request );
					return true === $permission ? call_user_func( $configuration['callback'], $request ) : $permission;
				}
			}
			return new WP_Error( 'route_not_found', 'Route not found.', array( 'status' => 404 ) );
		};
	}
	public function test_initialize_advertises_only_approved_capabilities(): void {
		$data = $this->call( 'initialize' );
		$this->assertSame( array( 'tools' ), array_keys( $data['result']['capabilities'] ) );
		$this->assertInstanceOf( stdClass::class, $data['result']['capabilities']['tools'] );
	}
	public function test_tools_list_is_exact_approved_inventory(): void {
		$data = $this->call( 'tools/list' );
		$names = array_column( $data['result']['tools'], 'name' );
		$this->assertSame( array( 'wp_site_info', 'wp_list_posts', 'wp_get_post', 'wp_create_post', 'wp_update_post', 'wp_list_pages', 'wp_get_page', 'wp_create_page', 'wp_update_page', 'wp_list_drafts', 'wp_delete_draft', 'wp_list_media', 'wp_get_media', 'wp_upload_media_b64', 'wp_list_categories', 'wp_list_tags', 'wp_create_term' ), $names );
	}
	public function test_delete_draft_is_the_only_destructive_tool(): void {
		$data = $this->call( 'tools/list' ); $destructive = array();
		foreach ( $data['result']['tools'] as $tool ) { if ( $tool['annotations']['destructiveHint'] ) { $destructive[] = $tool['name']; } }
		$this->assertSame( array( 'wp_delete_draft' ), $destructive );
	}
	public function test_only_approved_protocol_methods_are_supported(): void {
		foreach ( array( 'resources/list', 'resources/read', 'prompts/list' ) as $method ) {
			$data = $this->call( $method );
			$this->assertSame( -32601, $data['error']['code'] );
		}
	}
	public function test_internal_tool_request_forwards_outer_api_key_to_target_permission_callback(): void {
		$this->install_test_router();
		$data = $this->call_authenticated( 'tools/call', array( 'name' => 'wp_site_info', 'arguments' => array() ) );
		$this->assertSame( 'Example Site', $data['result']['structuredContent']['name'] );
	}
	public function test_rest_error_response_becomes_jsonrpc_error(): void {
		$this->install_test_router();
		$data = $this->call_authenticated( 'tools/call', array( 'name' => 'wp_get_post', 'arguments' => array( 'id' => 999 ) ) );
		$this->assertSame( -32000, $data['error']['code'] );
		$this->assertSame( 'Post not found.', $data['error']['message'] );
		$this->assertSame( 404, $data['error']['data']['status'] );
	}
	public function test_authenticated_tool_call_logs_only_minimal_local_metadata(): void {
		$this->install_test_router();
		$this->call_authenticated( 'tools/call', array( 'name' => 'wp_site_info', 'arguments' => array( 'secret' => 'do-not-store' ) ) );
		$this->assertCount( 1, $GLOBALS['wpdb']->inserted );
		$logged = $GLOBALS['wpdb']->inserted[0]['data'];
		$this->assertSame( 'wp_site_info', $logged['action'] );
		$this->assertSame( 200, $logged['status_code'] );
		$this->assertArrayHasKey( 'duration_ms', $logged );
		$this->assertArrayHasKey( 'api_key_id', $logged );
		$this->assertArrayNotHasKey( 'request_data', $logged );
		$this->assertArrayNotHasKey( 'response_data', $logged );
	}
}
