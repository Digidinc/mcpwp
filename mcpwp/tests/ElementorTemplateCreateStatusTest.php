<?php
/**
 * #1211 — library template creators default to draft, accept status, and
 * report the stored post_status after the write.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-elementor-pro.php';
require_once __DIR__ . '/fixtures/elementor-template-type-doubles.php';

final class ElementorTemplateCreateStatusTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_document_types'] = array(
			'page'    => \Elementor\Mcpwp_Fake_Doc_Page::class,
			'section' => \Elementor\Mcpwp_Fake_Doc_Section::class,
		);
		$GLOBALS['mcpwp_test_document_properties'] = array(
			\Elementor\Mcpwp_Fake_Doc_Page::class    => array( 'show_in_library' => true ),
			\Elementor\Mcpwp_Fake_Doc_Section::class => array( 'show_in_library' => true ),
		);
		\Elementor\Plugin::$instance               = new \Elementor\Plugin();
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;
		$GLOBALS['_mcpwp_test_posts']                         = array();
		$GLOBALS['_mcpwp_test_meta']                          = array();

		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		if ( method_exists( 'Mcpwp_REST_API', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_API::reset_verdict_memo();
		}
	}

	private function write_key( Mcpwp_REST_Elementor_Pro $controller ): string {
		return $controller->create_scoped_api_key( 'write key', array( 'write' ), 'admin' )['key'];
	}

	private function admin_key( Mcpwp_REST_Elementor_Pro $controller ): string {
		return $controller->create_scoped_api_key( 'admin key', array( 'admin' ), 'admin' )['key'];
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_template_defaults_to_draft_and_reports_stored_status(): void {
		$result = ( new Mcpwp_Elementor_Pro() )->create_template(
			array(
				'title' => 'Draft by default',
				'type'  => 'page',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'draft', get_post_status( (int) $result['id'] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_template_with_publish_reports_publish_after_write(): void {
		$result = ( new Mcpwp_Elementor_Pro() )->create_template(
			array(
				'title'  => 'Published library template',
				'type'   => 'page',
				'status' => 'publish',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertSame( 'publish', get_post_status( (int) $result['id'] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_template_refuses_invalid_status(): void {
		$result = ( new Mcpwp_Elementor_Pro() )->create_template(
			array(
				'title'  => 'Bad status',
				'type'   => 'page',
				'status' => 'pending',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_status', $result->get_error_code() );
		$library = array_filter(
			$GLOBALS['_mcpwp_test_posts'] ?? array(),
			static function ( $post ) {
				return $post instanceof WP_Post && 'elementor_library' === $post->post_type;
			}
		);
		$this->assertEmpty( $library, 'An invalid status must refuse before creating a library template.' );
	}

	public function test_write_key_cannot_create_a_published_elementor_template(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( null );
		$key        = $this->write_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/templates',
			array(
				'title'  => 'Published via API',
				'type'   => 'page',
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$result = $controller->verify_api_key( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	public function test_admin_key_can_target_publish_on_elementor_template_create(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( null );
		$key        = $this->admin_key( $controller );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/templates',
			array(
				'title'  => 'Published via API',
				'type'   => 'page',
				'status' => 'publish',
			),
			array( 'X-API-Key' => $key )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );
	}

}