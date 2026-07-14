<?php
use PHPUnit\Framework\TestCase;

final class McpEndpointTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array(
			'mcpwp_api_keys' => array(
				array( 'id' => 'test-key-id', 'hash' => 'test-key', 'user_id' => 2, 'scopes' => array( 'read', 'write', 'admin' ), 'tool_categories' => array(), 'revoked_at' => null ),
			),
			'mcpwp_settings' => array( 'enable_logging' => true ),
			'mcpwp_rate_limit_settings' => array( 'enabled' => false ),
		);
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_rest_dispatcher'] = null;
		$GLOBALS['_mcpwp_test_nested_permission_calls'] = 0;
		$GLOBALS['wpdb']->inserted = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
		$this->reset_limiter_singleton();
	}
	private function call( $method, $params = array() ) {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array(), array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ) );
		return ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();
	}
	private function call_authenticated( $method, $params = array() ) {
		return $this->call_authenticated_body( array( 'jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params ) );
	}
	private function call_authenticated_body( $body ) {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => 'test-key' ), $body );
		$controller = new Mcpwp_REST_MCP();
		$this->assertTrue( $controller->check_permission( $request ) );
		return $controller->handle_mcp( $request )->get_data();
	}
	private function install_test_router(): void {
		( new Mcpwp_REST_Site_Info() )->register_routes();
		( new Mcpwp_REST_Posts() )->register_routes();
		$GLOBALS['_mcpwp_test_rest_dispatcher'] = static function ( $request ) {
			foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $registration ) {
				$pattern = '#^/mcpwp/v1' . $registration['route'] . '$#';
				if ( 1 !== preg_match( $pattern, $request->get_route(), $matches ) ) { continue; }
				$configurations = isset( $registration['args']['methods'] ) ? array( $registration['args'] ) : $registration['args'];
				foreach ( $configurations as $configuration ) {
					if ( ! in_array( $request->get_method(), explode( ',', $configuration['methods'] ), true ) ) { continue; }
					foreach ( $matches as $key => $value ) { if ( is_string( $key ) ) { $request->set_param( $key, $value ); } }
					$GLOBALS['_mcpwp_test_nested_permission_calls']++;
					$permission = call_user_func( $configuration['permission_callback'], $request );
					return true === $permission ? call_user_func( $configuration['callback'], $request ) : $permission;
				}
			}
			return new WP_Error( 'route_not_found', 'Route not found.', array( 'status' => 404 ) );
		};
	}
	private function install_permission_only_router(): void {
		foreach ( array( new Mcpwp_REST_Site_Info(), new Mcpwp_REST_Posts(), new Mcpwp_REST_Pages(), new Mcpwp_REST_Media(), new Mcpwp_REST_Site_Taxonomy() ) as $controller ) { $controller->register_routes(); }
		$GLOBALS['_mcpwp_test_rest_dispatcher'] = static function ( $request ) {
			foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $registration ) {
				$pattern = '#^/mcpwp/v1' . $registration['route'] . '$#';
				if ( 1 !== preg_match( $pattern, $request->get_route(), $matches ) ) { continue; }
				$configurations = isset( $registration['args']['methods'] ) ? array( $registration['args'] ) : $registration['args'];
				foreach ( $configurations as $configuration ) {
					if ( ! in_array( $request->get_method(), explode( ',', $configuration['methods'] ), true ) ) { continue; }
					foreach ( $matches as $key => $value ) { if ( is_string( $key ) ) { $request->set_param( $key, $value ); } }
					$GLOBALS['_mcpwp_test_nested_permission_calls']++;
					$permission = call_user_func( $configuration['permission_callback'], $request );
					return true === $permission ? new WP_REST_Response( array( 'allowed' => true ) ) : $permission;
				}
			}
			return new WP_Error( 'route_not_found', 'Route not found.', array( 'status' => 404 ) );
		};
	}
	private function permission_post( $id, $type, $status ) {
		return (object) array( 'ID' => $id, 'post_type' => $type, 'post_title' => 'Item', 'post_name' => 'item', 'post_status' => $status, 'post_date' => '2026-01-01', 'post_date_gmt' => '2026-01-01', 'post_modified' => '2026-01-01', 'post_modified_gmt' => '2026-01-01', 'post_parent' => 0, 'menu_order' => 0, 'post_author' => 2, 'post_content' => '', 'post_excerpt' => '', 'post_mime_type' => 'attachment' === $type ? 'image/png' : '' );
	}
	private function reset_limiter_singleton(): void {
		$reflection = new ReflectionClass( Mcpwp_Rate_Limiter::class );
		$instance = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null );
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

	/** @dataProvider invalidEnvelopes */
	public function test_invalid_jsonrpc_envelopes_are_rejected( $body ): void {
		$data = $this->call_authenticated_body( $body );
		$this->assertSame( -32600, $data['error']['code'] );
	}

	public function invalidEnvelopes(): array {
		return array(
			'missing jsonrpc' => array( array( 'id' => 1, 'method' => 'ping', 'params' => array() ) ),
			'wrong jsonrpc' => array( array( 'jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping', 'params' => array() ) ),
			'extra key' => array( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => array(), 'extra' => true ) ),
			'boolean id' => array( array( 'jsonrpc' => '2.0', 'id' => true, 'method' => 'ping', 'params' => array() ) ),
			'non-object params' => array( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => 'bad' ) ),
		);
	}

	public function test_protocol_params_and_tool_names_are_exact(): void {
		$initialize = $this->call_authenticated( 'initialize', array( 'protocolVersion' => '1900-01-01' ) );
		$this->assertSame( -32602, $initialize['error']['code'] );
		$list = $this->call_authenticated( 'tools/list', array( 'cursor' => 'not-supported' ) );
		$this->assertSame( -32602, $list['error']['code'] );
		$category = $this->call_authenticated( 'tools/list', array( 'category' => 'content' ) );
		$this->assertSame( -32602, $category['error']['code'] );
		$tool = $this->call_authenticated( 'tools/call', array( 'name' => 'wp_site_info!!!', 'arguments' => array() ) );
		$this->assertSame( -32602, $tool['error']['code'] );
	}

	public function test_malformed_request_without_id_is_an_error_not_a_notification(): void {
		$data = $this->call_authenticated_body( array( 'jsonrpc' => '1.0', 'method' => 'ping', 'params' => array() ) );
		$this->assertSame( -32600, $data['error']['code'] );
		$this->assertNull( $data['id'] );
	}

	/** @dataProvider invalidToolArguments */
	public function test_tool_arguments_are_validated_against_exact_advertised_schema( $name, $arguments ): void {
		$this->install_permission_only_router();
		$data = $this->call_authenticated( 'tools/call', array( 'name' => $name, 'arguments' => $arguments ) );
		$this->assertSame( -32602, $data['error']['code'] );
		$this->assertSame( 0, $GLOBALS['_mcpwp_test_nested_permission_calls'] );
	}

	public function invalidToolArguments(): array {
		return array(
			'unknown argument' => array( 'wp_site_info', array( 'secret' => true ) ),
			'numeric string integer' => array( 'wp_get_post', array( 'id' => '7' ) ),
			'boolean string' => array( 'wp_list_categories', array( 'hide_empty' => 'false' ) ),
			'missing required' => array( 'wp_create_term', array( 'taxonomy' => 'category' ) ),
			'empty update' => array( 'wp_update_post', array( 'id' => 7 ) ),
		);
	}

	public function test_notifications_are_omitted_and_mixed_batch_order_is_preserved(): void {
		$data = $this->call_authenticated_body( array(
			array( 'jsonrpc' => '2.0', 'method' => 'ping', 'params' => array() ),
			array( 'jsonrpc' => '2.0', 'id' => 41, 'method' => 'ping', 'params' => array() ),
			array( 'jsonrpc' => '2.0', 'method' => 'tools/list', 'params' => array() ),
			array( 'jsonrpc' => '2.0', 'id' => 42, 'method' => 'tools/list', 'params' => array() ),
		) );
		$this->assertSame( array( 41, 42 ), array_column( $data, 'id' ) );
	}

	public function test_empty_batch_is_invalid_and_notification_only_is_204(): void {
		$empty = $this->call_authenticated_body( array() );
		$this->assertSame( -32600, $empty['error']['code'] );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => 'test-key' ), array( array( 'jsonrpc' => '2.0', 'method' => 'ping', 'params' => array() ) ) );
		$controller = new Mcpwp_REST_MCP();
		$this->assertTrue( $controller->check_permission( $request ) );
		$response = $controller->handle_mcp( $request );
		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}

	public function test_non_object_batch_member_returns_ordered_invalid_request_error(): void {
		$data = $this->call_authenticated_body( array(
			array( 'jsonrpc' => '2.0', 'id' => 51, 'method' => 'ping', 'params' => array() ),
			'not-an-object',
			array( 'jsonrpc' => '2.0', 'id' => 52, 'method' => 'ping', 'params' => array() ),
		) );
		$this->assertSame( 51, $data[0]['id'] );
		$this->assertSame( -32600, $data[1]['error']['code'] );
		$this->assertSame( 52, $data[2]['id'] );
	}

	public function test_nested_error_mapping_drops_unreviewed_sensitive_context(): void {
		$GLOBALS['_mcpwp_test_rest_dispatcher'] = static function () {
			return new WP_Error( 'bounded_failure', 'Safe message.', array( 'status' => 403, 'path' => '/srv/private.php', 'sql' => 'SELECT secret', 'hash' => 'api-key-hash' ) );
		};
		$data = $this->call_authenticated( 'tools/call', array( 'name' => 'wp_site_info', 'arguments' => array() ) );
		$this->assertSame( array( 'status' => 403, 'code' => 'bounded_failure' ), $data['error']['data'] );
		$this->assertStringNotContainsString( '/srv/', wp_json_encode( $data ) );
		$this->assertStringNotContainsString( 'SELECT', wp_json_encode( $data ) );
		$this->assertStringNotContainsString( 'api-key-hash', wp_json_encode( $data ) );
	}

	/** @dataProvider nestedStatusMappings */
	public function test_nested_rest_statuses_map_to_stable_jsonrpc_codes( $status, $expected ): void {
		$GLOBALS['_mcpwp_test_rest_dispatcher'] = static function () use ( $status ) { return new WP_Error( 'nested_failure', 'Safe failure.', array( 'status' => $status ) ); };
		$data = $this->call_authenticated( 'tools/call', array( 'name' => 'wp_site_info', 'arguments' => array() ) );
		$this->assertSame( $expected, $data['error']['code'] );
		$this->assertSame( $status, $data['error']['data']['status'] );
	}

	public function nestedStatusMappings(): array {
		return array( '403' => array( 403, -32003 ), '404' => array( 404, -32004 ), '413' => array( 413, -32013 ), '415' => array( 415, -32015 ), '429' => array( 429, -32029 ) );
	}
	public function test_internal_tool_request_forwards_outer_api_key_to_target_permission_callback(): void {
		$this->install_test_router();
		$data = $this->call_authenticated( 'tools/call', array( 'name' => 'wp_site_info', 'arguments' => array() ) );
		$this->assertSame( 'Example Site', $data['result']['structuredContent']['name'] );
	}
	public function test_authenticated_mcp_tool_call_consumes_one_rate_limit_unit_across_nested_rest_auth(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = array(
			array( 'id' => 'rate-key', 'hash' => 'test-key', 'user_id' => 2, 'scopes' => array( 'read', 'write', 'admin' ), 'tool_categories' => array(), 'revoked_at' => null ),
		);
		$GLOBALS['mcpwp_test_options']['mcpwp_rate_limit_settings'] = array(
			'enabled' => true, 'requests_per_minute' => 10, 'requests_per_hour' => 100, 'burst_limit' => 10, 'whitelist' => array(),
		);
		$this->reset_limiter_singleton();
		$this->install_test_router();

		$data = $this->call_authenticated( 'tools/call', array( 'name' => 'wp_site_info', 'arguments' => array() ) );

		$this->assertSame( 'Example Site', $data['result']['structuredContent']['name'] );
		$this->assertSame( 1, $GLOBALS['_mcpwp_test_nested_permission_calls'], 'The nested REST permission callback must still run.' );
		foreach ( $GLOBALS['mcpwp_test_transients'] as $window ) {
			$this->assertSame( 1, $window['value']['count'], 'Outer plus nested MCP dispatch must consume exactly one rate-limit unit.' );
		}
	}
	public function test_authenticated_jsonrpc_batch_consumes_one_rate_limit_unit_per_tool_call(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = array(
			array( 'id' => 'rate-key', 'hash' => 'test-key', 'user_id' => 2, 'scopes' => array( 'read', 'write', 'admin' ), 'tool_categories' => array(), 'revoked_at' => null ),
		);
		$GLOBALS['mcpwp_test_options']['mcpwp_rate_limit_settings'] = array(
			'enabled' => true, 'requests_per_minute' => 10, 'requests_per_hour' => 100, 'burst_limit' => 10, 'whitelist' => array(),
		);
		$this->reset_limiter_singleton();
		$this->install_test_router();

		$data = $this->call_authenticated_body( array(
			array( 'jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_site_info', 'arguments' => array() ) ),
			array( 'jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_site_info', 'arguments' => array() ) ),
		) );

		$this->assertCount( 2, $data );
		$this->assertSame( array( 10, 11 ), array_column( $data, 'id' ) );
		$this->assertSame( 2, $GLOBALS['_mcpwp_test_nested_permission_calls'], 'Every nested tool permission callback must still run.' );
		foreach ( $GLOBALS['mcpwp_test_transients'] as $window ) {
			$this->assertSame( 2, $window['value']['count'], 'A two-tool JSON-RPC batch must consume two total rate-limit units.' );
		}
	}
	public function test_rest_error_response_becomes_jsonrpc_error(): void {
		$this->install_test_router();
		$data = $this->call_authenticated( 'tools/call', array( 'name' => 'wp_get_post', 'arguments' => array( 'id' => 999 ) ) );
		$this->assertSame( -32004, $data['error']['code'] );
		$this->assertSame( 'Post not found.', $data['error']['message'] );
		$this->assertSame( 404, $data['error']['data']['status'] );
	}
	public function test_mcp_mutation_forwards_canonical_json_body_to_permission_and_handler(): void {
		$this->install_test_router();
		$data = $this->call_authenticated( 'tools/call', array( 'name' => 'wp_create_post', 'arguments' => array( 'title' => 'MCP draft', 'status' => 'draft' ) ) );
		$this->assertSame( 'MCP draft', $data['result']['structuredContent']['title'] );
		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][1]->post_status );
	}

	public function test_mcp_publish_uses_canonical_body_for_dynamic_admin_scope(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'][0]['scopes'] = array( 'read', 'write' );
		$this->install_test_router();
		$data = $this->call_authenticated( 'tools/call', array( 'name' => 'wp_create_post', 'arguments' => array( 'title' => 'No publish', 'status' => 'publish' ) ) );
		$this->assertSame( 'insufficient_scope', $data['error']['data']['code'] );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
	}

	public function test_mcp_update_to_publish_uses_dynamic_admin_scope(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'][0]['scopes'] = array( 'read', 'write' );
		$GLOBALS['_mcpwp_test_posts'][7] = (object) array( 'ID' => 7, 'post_type' => 'post', 'post_title' => 'Draft', 'post_name' => 'draft', 'post_status' => 'draft', 'post_date' => '2026-01-01', 'post_date_gmt' => '2026-01-01', 'post_modified' => '2026-01-01', 'post_modified_gmt' => '2026-01-01', 'post_parent' => 0, 'menu_order' => 0, 'post_author' => 2, 'post_content' => '', 'post_excerpt' => '' );
		$this->install_test_router();
		$data = $this->call_authenticated( 'tools/call', array( 'name' => 'wp_update_post', 'arguments' => array( 'id' => 7, 'status' => 'publish' ) ) );
		$this->assertSame( 'insufficient_scope', $data['error']['data']['code'] );
		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][7]->post_status );
	}

	public function test_protocol_and_tool_batch_charge_exactly_once_per_item(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_rate_limit_settings'] = array( 'enabled' => true, 'requests_per_minute' => 10, 'requests_per_hour' => 100, 'burst_limit' => 10, 'whitelist' => array() );
		$this->reset_limiter_singleton();
		$this->install_test_router();
		$data = $this->call_authenticated_body( array(
			array( 'jsonrpc' => '2.0', 'id' => 30, 'method' => 'ping', 'params' => array() ),
			array( 'jsonrpc' => '2.0', 'id' => 31, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_site_info', 'arguments' => array() ) ),
		) );
		$this->assertCount( 2, $data );
		foreach ( $GLOBALS['mcpwp_test_transients'] as $window ) { $this->assertSame( 2, $window['value']['count'] ); }
	}

	public function test_invalid_unknown_and_denied_batch_items_each_consume_one_rate_unit(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_rate_limit_settings'] = array( 'enabled' => true, 'requests_per_minute' => 20, 'requests_per_hour' => 100, 'burst_limit' => 20, 'whitelist' => array() );
		$GLOBALS['mcpwp_test_options']['mcpwp_disabled_tool_categories'] = array( 'site' );
		$this->reset_limiter_singleton();
		$data = $this->call_authenticated_body( array(
			array( 'jsonrpc' => '1.0', 'id' => 60, 'method' => 'ping', 'params' => array() ),
			array( 'jsonrpc' => '2.0', 'id' => 61, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_unknown', 'arguments' => array() ) ),
			array( 'jsonrpc' => '2.0', 'id' => 62, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_get_post', 'arguments' => array( 'id' => '7' ) ) ),
			array( 'jsonrpc' => '2.0', 'id' => 63, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_site_info', 'arguments' => array() ) ),
		) );
		$this->assertCount( 4, $data );
		foreach ( $GLOBALS['mcpwp_test_transients'] as $window ) { $this->assertSame( 4, $window['value']['count'] ); }
	}

	public function test_mcp_read_tool_uses_same_get_multiplier_as_direct_rest(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_rate_limit_settings'] = array( 'enabled' => true, 'requests_per_minute' => 10, 'requests_per_hour' => 100, 'burst_limit' => 1, 'whitelist' => array() );
		$this->reset_limiter_singleton();
		$this->install_test_router();
		$this->assertArrayHasKey( 'result', $this->call_authenticated( 'tools/call', array( 'name' => 'wp_site_info', 'arguments' => array() ) ) );
		$this->assertArrayHasKey( 'result', $this->call_authenticated( 'tools/call', array( 'name' => 'wp_site_info', 'arguments' => array() ) ) );
		$limited = $this->call_authenticated( 'tools/call', array( 'name' => 'wp_site_info', 'arguments' => array() ) );
		$this->assertSame( 'rate_limit_exceeded', $limited['error']['data']['code'] );
	}
	public function test_all_seventeen_tools_execute_real_nested_permission_callbacks(): void {
		$this->install_permission_only_router();
		$GLOBALS['_mcpwp_test_posts'] = array( 1 => $this->permission_post( 1, 'post', 'draft' ), 2 => $this->permission_post( 2, 'page', 'draft' ), 3 => $this->permission_post( 3, 'post', 'draft' ), 4 => $this->permission_post( 4, 'attachment', 'inherit' ) );
		$arguments = array(
			'wp_site_info' => array(), 'wp_list_posts' => array(), 'wp_get_post' => array( 'id' => 1 ), 'wp_create_post' => array( 'title' => 'Post' ),
			'wp_update_post' => array( 'id' => 1, 'title' => 'Updated' ), 'wp_list_pages' => array(), 'wp_get_page' => array( 'id' => 2 ), 'wp_create_page' => array( 'title' => 'Page' ),
			'wp_update_page' => array( 'id' => 2, 'title' => 'Updated' ), 'wp_list_drafts' => array(), 'wp_delete_draft' => array( 'id' => 3 ), 'wp_list_media' => array(),
			'wp_get_media' => array( 'id' => 4 ), 'wp_upload_media_b64' => array( 'data' => 'AA==', 'filename' => 'x.png' ), 'wp_list_categories' => array(),
			'wp_list_tags' => array(), 'wp_create_term' => array( 'taxonomy' => 'category', 'name' => 'News' ),
		);
		foreach ( $arguments as $tool => $tool_arguments ) {
			$before = $GLOBALS['_mcpwp_test_nested_permission_calls'];
			$data = $this->call_authenticated( 'tools/call', array( 'name' => $tool, 'arguments' => $tool_arguments ) );
			$this->assertSame( true, $data['result']['structuredContent']['allowed'], $tool );
			$this->assertSame( $before + 1, $GLOBALS['_mcpwp_test_nested_permission_calls'], $tool );
		}
	}
	public function test_authenticated_tool_call_logs_only_minimal_local_metadata(): void {
		$this->install_test_router();
		$this->call_authenticated( 'tools/call', array( 'name' => 'wp_site_info', 'arguments' => array() ) );
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
