<?php
/**
 * #1082 review: an execution error after a Custom Code write must tell every
 * MCP consumer which resource was affected and that state may be partial.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class McpPartialWriteTransportTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 5;
		$GLOBALS['mcpwp_test_user_caps']    = array( 5 => array( 'edit_posts' => true, 'edit_others_posts' => true ) );
		$GLOBALS['mcpwp_test_filters']      = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
	}

	private function call( $name, array $arguments ): array {
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1082,
				'method'  => 'tools/call',
				'params'  => array( 'name' => $name, 'arguments' => $arguments ),
			)
		);

		return ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();
	}

	public function partial_write_error( $result, $request, $route = '' ) {
		if ( null === $result && ( preg_match( '#/elementor/widget-help/#', (string) $route ) || '/mcpwp/v1/batch' === $route ) ) {
			return new WP_Error(
				'conditions_save_failed',
				'Custom Code conditions could not be saved.',
				array( 'status' => 500, 'resource_id' => 682, 'partial_write' => true )
			);
		}
		return $result;
	}

	private function assert_partial_write( array $data ): void {
		$this->assertTrue( $data['result']['isError'] );
		$error = $data['result']['structuredContent']['error'];
		$this->assertSame( 'conditions_save_failed', $error['code'] );
		$this->assertSame( 682, $error['resource_id'] );
		$this->assertTrue( $error['partial_write'] );
		$this->assertStringContainsString( 'Resource ID: 682', $data['result']['content'][0]['text'] );
		$this->assertStringContainsString( 'Partial write: true', $data['result']['content'][0]['text'] );
	}

	public function test_direct_call_wrapper_and_batch_keep_partial_write_receipt(): void {
		add_filter( 'rest_dispatch_request', array( $this, 'partial_write_error' ), 10, 3 );

		$this->assert_partial_write( $this->call( 'wp_elementor_widget_help', array( 'widget_type' => 'heading' ) ) );
		$this->assert_partial_write(
			$this->call(
				'mcpwp_call',
				array( 'name' => 'wp_elementor_widget_help', 'arguments' => array( 'widget_type' => 'heading' ) )
			)
		);
		$this->assert_partial_write(
			$this->call( 'wp_batch', array( 'operations' => array( array( 'method' => 'GET', 'path' => '/posts/1' ) ) ) )
		);
	}

	public function test_webmcp_keeps_the_mcp_partial_write_receipt(): void {
		update_option( 'mcpwp_settings', array( 'webmcp_admin_enabled' => true ) );
		add_filter(
			'rest_dispatch_request',
			static function ( $result, $request, $route = '' ) {
				if ( null !== $result ) {
					return $result;
				}
				if ( '/mcpwp/v1/mcp' === $route ) {
					return ( new Mcpwp_REST_MCP() )->handle_mcp( $request );
				}
				if ( '/mcpwp/v1/posts' === $route ) {
					return new WP_Error(
						'conditions_save_failed',
						'Custom Code conditions could not be saved.',
						array( 'status' => 500, 'resource_id' => 682, 'partial_write' => true )
					);
				}
				return $result;
			},
			10,
			3
		);

		$response = ( new Mcpwp_REST_WebMCP() )->handle_call(
			new WP_REST_Request( 'POST', '/mcpwp/v1/webmcp/call', array( 'name' => 'wp_create_post', 'arguments' => array( 'title' => 'draft' ) ) )
		);
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['isError'] );
		$this->assertSame( 682, $response->get_data()['structuredContent']['error']['resource_id'] );
		$this->assertTrue( $response->get_data()['structuredContent']['error']['partial_write'] );
		$this->assertStringContainsString( 'Partial write: true', $response->get_data()['content'][0]['text'] );
	}
}
