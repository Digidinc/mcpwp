<?php

use PHPUnit\Framework\TestCase;

final class CustomCodeReportScopeTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['_mcpwp_test_posts'][15] = new WP_Post( array( 'ID' => 15, 'post_type' => 'elementor_snippet', 'post_status' => 'draft', 'post_title' => 'Quarantined' ) );
		update_post_meta( 15, '_elementor_conditions', array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_wpdb_intercept'] );
	}

	private function controller() {
		return new Mcpwp_REST_Elementor_Pro( new class {
			public function set_template_ref_gate( $gate ) {}
			public function get_templates_page( $args ) {
				return array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => 50, 'pages' => 0 );
			}
		} );
	}

	/** @dataProvider key_provider */
	public function test_unrelated_template_reads_do_not_disclose_or_scan_quarantine( $scopes, $role, $categories ): void {
		$controller = $this->controller();
		$key = $controller->create_scoped_api_key( 'report-scope', $scopes, $role, $categories );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/templates', array(), array( 'X-API-Key' => $key['key'] ) );
		$this->assertTrue( $controller->check_permission( $request ) );
		$scans = 0;
		$GLOBALS['mcpwp_test_wpdb_intercept'] = function ( $method, $query ) use ( &$scans ) {
			if ( 'get_col' === $method && false !== strpos( $query, 'elementor_snippet' ) ) {
				++$scans;
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		$data = $controller->get_templates( $request )->get_data();
		$this->assertArrayNotHasKey( 'skipped_custom_code_snippet_ids', $data );
		$this->assertSame( 0, $scans, 'Unrelated responses must not pay for the report scan.' );
	}

	public function key_provider(): array {
		return array(
			array( array( 'read' ), 'admin', array() ),
			array( array( 'read', 'write' ), 'custom', array( 'elementor-templates' ) ),
			array( array( 'read', 'write', 'admin' ), 'admin', array() ),
		);
	}

	public function test_admin_custom_code_get_and_list_mark_the_quarantined_snippet(): void {
		$controller = $this->controller();
		$key = $controller->create_scoped_api_key( 'report-admin', array( 'read', 'write', 'admin' ), 'admin', array() );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/custom-code/15', array( 'id' => 15 ), array( 'X-API-Key' => $key['key'] ) );
		$this->assertTrue( $controller->check_permission( $request ) );
		$data = $controller->get_custom_code( $request )->get_data();
		$this->assertTrue( $data['snippet']['quarantined'] ?? false );
		$this->assertNotEmpty( $data['snippet']['quarantine_reason'] ?? '' );
		$this->assertContains( 15, $data['skipped_custom_code_snippet_ids'] ?? array() );
		$list = $controller->list_custom_code( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/custom-code' ) )->get_data();
		$this->assertTrue( $list['snippets'][0]['quarantined'] ?? false );
	}

	public function test_a_read_key_does_not_get_admin_markers_even_if_a_handler_is_called_directly(): void {
		$controller = $this->controller();
		$key = $controller->create_scoped_api_key( 'report-read', array( 'read' ), 'admin', array() );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/templates', array(), array( 'X-API-Key' => $key['key'] ) );
		$this->assertTrue( $controller->check_permission( $request ) );
		$data = $controller->get_custom_code( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/custom-code/15', array( 'id' => 15 ) ) )->get_data();
		$this->assertArrayNotHasKey( 'skipped_custom_code_snippet_ids', $data );
		$this->assertArrayNotHasKey( 'quarantined', $data['snippet'] );
	}
}
