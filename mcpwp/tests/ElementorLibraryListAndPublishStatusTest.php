<?php
/**
 * #1211 follow-up — library lists include drafts for write keys; publish on update; theme draft+scope.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
	require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';
}
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-elementor-pro.php';

final class ElementorLibraryListAndPublishStatusTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts']       = array();
		$GLOBALS['_mcpwp_test_meta']        = array();
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		if ( method_exists( 'Mcpwp_REST_API', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_API::reset_verdict_memo();
		}
	}

	private function seed_library_posts(): void {
		$GLOBALS['_mcpwp_test_posts'][101] = new WP_Post(
			(object) array(
				'ID'          => 101,
				'post_title'  => 'Published template',
				'post_name'   => 'published-template',
				'post_type'   => 'elementor_library',
				'post_status' => 'publish',
			)
		);
		$GLOBALS['_mcpwp_test_posts'][102] = new WP_Post(
			(object) array(
				'ID'          => 102,
				'post_title'  => 'Draft template',
				'post_name'   => 'draft-template',
				'post_type'   => 'elementor_library',
				'post_status' => 'draft',
			)
		);
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;
	}

	public function test_write_scope_list_includes_drafts_by_default(): void {
		$this->seed_library_posts();
		$page = ( new Mcpwp_Elementor_Pro() )->get_templates_page( array( 'caller_read_only' => false ) );
		$ids  = array_column( $page['items'], 'id' );
		sort( $ids );
		$this->assertSame( array( 101, 102 ), $ids );
		$this->assertSame( 'draft', $page['items'][1]['status'] );
	}

	public function test_read_scope_list_defaults_to_publish_only(): void {
		$this->seed_library_posts();
		$page = ( new Mcpwp_Elementor_Pro() )->get_templates_page( array( 'caller_read_only' => true ) );
		$ids  = array_column( $page['items'], 'id' );
		$this->assertSame( array( 101 ), $ids );
	}

	public function test_admin_key_can_publish_existing_template_via_update_route_gate(): void {
		$GLOBALS['_mcpwp_test_posts'][55] = new WP_Post(
			(object) array(
				'ID'          => 55,
				'post_title'  => 'Draft to publish',
				'post_name'   => 'draft-to-publish',
				'post_type'   => 'elementor_library',
				'post_status' => 'draft',
			)
		);
		$GLOBALS['mcpwp_test_meta'][55] = array(
			'_elementor_template_type' => array( 'page' ),
			'_elementor_edit_mode'     => array( 'builder' ),
		);
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;

		$controller = new Mcpwp_REST_Elementor_Pro( null );
		$admin_key  = $controller->create_scoped_api_key( 'admin', array( 'admin' ), 'admin' )['key'];

		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/elementor/templates/55',
			array( 'status' => 'publish' ),
			array( 'X-API-Key' => $admin_key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
		$updated = ( new Mcpwp_Elementor_Pro() )->update_template( 55, array( 'status' => 'publish' ) );
		$this->assertIsArray( $updated );
		$this->assertSame( 'publish', $updated['status'] );
		$this->assertTrue( $updated['live'] );
	}

	public function test_write_key_cannot_target_publish_on_template_update_verification(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( null );
		$key        = $controller->create_scoped_api_key( 'write', array( 'write' ), 'admin' )['key'];
		$request    = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/elementor/templates/1',
			array( 'status' => 'publish' ),
			array( 'X-API-Key' => $key )
		);
		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

}