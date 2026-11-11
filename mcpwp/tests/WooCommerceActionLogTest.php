<?php
/**
 * Regression coverage for #1037: audit writes using registry annotations.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-license.php';

final class WooCommerceActionLogTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_wpdb_rows']         = array();
		$GLOBALS['mcpwp_test_wpdb_insert_fails'] = false;
		$GLOBALS['mcpwp_test_fs'] = new Mcpwp_Test_Fs_Stub( true, true, false, 'pro' );
		$license = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$license->setAccessible( true );
		$license->setValue( null, null );
		Mcpwp_REST_MCP::reset_verdict_memo();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_rest_response_override'] );
		$GLOBALS['mcpwp_test_fs'] = null;
		$license = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$license->setAccessible( true );
		$license->setValue( null, null );
		$GLOBALS['mcpwp_test_filters']   = array();
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
	}

	private function call_tool( string $name, array $arguments, array $response = array( 'id' => 73 ) ): array {
		$GLOBALS['mcpwp_test_rest_response_override'] = $response;
		$controller = new Mcpwp_REST_MCP();
		$capabilities = new ReflectionProperty( Mcpwp_REST_MCP::class, 'active_capabilities' );
		$capabilities->setAccessible( true );
		$capabilities->setValue( $controller, array( 'woocommerce' => true ) );
		$key        = $controller->create_scoped_api_key( 'audit test', array( 'admin' ) );
		$request    = new WP_REST_Request(
			'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $key['key'] ),
			array(
				'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
				'params' => array( 'name' => $name, 'arguments' => $arguments ),
			)
		);
		$this->assertTrue( $controller->verify_api_key( $request ) );
		$result = $controller->handle_mcp( $request )->get_data();
		$this->assertArrayHasKey( 'result', $result, wp_json_encode( $result ) );
		$this->assertEmpty( $result['result']['isError'], wp_json_encode( $result ) );
		return $GLOBALS['mcpwp_test_wpdb_rows']['wp_mcpwp_action_log'] ?? array();
	}

	/** @dataProvider woo_write_provider */
	public function test_every_woocommerce_write_is_logged_with_its_resource_id( string $name, array $arguments, array $response, string $id ): void {
		$rows = $this->call_tool( $name, $arguments, $response );
		$this->assertCount( 1, $rows, $name . ' must be recorded' );
		$this->assertSame( $name, $rows[0]['tool_name'] );
		$this->assertSame( 'woocommerce', $rows[0]['category'] );
		$this->assertSame( $id, $rows[0]['resource_id'] );
		$this->assertSame( 1, $rows[0]['success'] );
		$this->assertSame( 0, $rows[0]['rollback_supported'], 'WooCommerce needs dedicated snapshots before rollback can be offered.' );
	}

	public static function woo_write_provider(): array {
		return array(
			'create product' => array( 'wc_create_product', array( 'name' => 'Test product' ), array( 'id' => 73 ), '73' ),
			'update product' => array( 'wc_update_product', array( 'id' => 41, 'name' => 'Updated' ), array( 'id' => 41 ), '41' ),
			'delete product' => array( 'wc_delete_product', array( 'id' => 42 ), array( 'deleted' => true ), '42' ),
			'update order' => array( 'wc_update_order', array( 'id' => 43, 'status' => 'completed' ), array( 'id' => 43 ), '43' ),
			'create category' => array( 'wc_create_product_category', array( 'name' => 'Test category' ), array( 'id' => 74 ), '74' ),
			'update category' => array( 'wc_update_product_category', array( 'id' => 44, 'name' => 'Updated' ), array( 'id' => 44 ), '44' ),
			'create archetype' => array( 'wc_create_product_archetype', array( 'name' => 'Test archetype', 'archetype_class' => 'simple_product' ), array( 'id' => 75 ), '75' ),
			'apply creates product' => array( 'wc_apply_product_archetype', array( 'id' => 45, 'name' => 'Test' ), array( 'archetype_id' => 45, 'product' => array( 'id' => 76 ) ), '76' ),
			'apply updates product' => array( 'wc_apply_product_archetype', array( 'id' => 45, 'product_id' => 46 ), array( 'archetype_id' => 45, 'product' => array( 'id' => 46 ) ), '46' ),
		);
	}

	public function test_read_only_woocommerce_tool_does_not_log(): void {
		$this->assertSame( array(), $this->call_tool( 'wc_get_product', array( 'id' => 41 ) ) );
	}

	public function test_annotations_override_a_write_shaped_name(): void {
		$rows = $this->call_tool( 'wp_parse_blocks', array( 'content' => '<p>Test</p>' ) );
		$this->assertSame( array(), $rows );
		$this->assertNull( Mcpwp_Action_Log::begin( 'wp_update_post', array( 'id' => 41 ), '', '', array( 'readOnlyHint' => true ) ) );
	}

	public function test_non_prefixed_custom_write_is_logged(): void {
		add_filter( 'mcpwp_register_tools', static function ( $tools ) {
			$tools[] = array(
				'name' => 'digid_save_record', 'description' => 'Save a record.',
				'rest_path' => '/digid/v1/records', 'method' => 'POST',
			);
			return $tools;
		} );
		$rows = $this->call_tool( 'digid_save_record', array() );
		$this->assertCount( 1, $rows );
		$this->assertSame( '73', $rows[0]['resource_id'] );
	}
}
