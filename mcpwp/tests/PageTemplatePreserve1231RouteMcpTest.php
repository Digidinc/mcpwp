<?php
/**
 * #1231 — template preservation through REST and MCP tools/call dispatch.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-pages.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-page-builder.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-elementor-pro.php';

final class PageTemplatePreserve1231RouteMcpTest extends TestCase {

	private const PAGE_ID = 12311;

	/** @var array */
	private $meta_before = array();

	/** @var array */
	private $posts_before = array();

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_filters']            = array();
		$GLOBALS['mcpwp_test_dispatched_routes']  = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();

		$this->meta_before  = $GLOBALS['_mcpwp_test_meta'] ?? array();
		$this->posts_before = $GLOBALS['_mcpwp_test_posts'] ?? array();

		$GLOBALS['_mcpwp_test_meta']  = array();
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PAGE_ID => new WP_Post(
				array(
					'ID'           => self::PAGE_ID,
					'post_type'    => 'page',
					'post_title'   => 'REST template preserve',
					'post_status'  => 'draft',
					'post_content' => "<!-- wp:paragraph -->\n<p>Keep me</p>\n<!-- /wp:paragraph -->",
				)
			),
		);

		update_post_meta( self::PAGE_ID, '_wp_page_template', 'elementor_canvas' );

		$this->wire_rest_dispatch_to_elementor_controllers();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
		$GLOBALS['_mcpwp_test_meta']   = $this->meta_before;
		$GLOBALS['_mcpwp_test_posts']  = $this->posts_before;
	}

	private function pretend_elementor_is_installed(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.4.1' );
		}
	}

	private function minimal_section_json(): string {
		return wp_json_encode(
			array(
				array(
					'id'       => 'sec1231',
					'elType'   => 'section',
					'settings' => array(),
					'elements' => array(
						array(
							'id'       => 'col1231',
							'elType'   => 'column',
							'settings' => array( '_column_size' => 100 ),
							'elements' => array(),
						),
					),
				),
			)
		);
	}

	private function wire_rest_dispatch_to_elementor_controllers(): void {
		add_filter(
			'rest_dispatch_request',
			function ( $result, $request ) {
				if ( ! $request instanceof WP_REST_Request ) {
					return $result;
				}
				$route = (string) $request->get_route();
				if ( preg_match( '#^/mcpwp/v1/elementor/(\d+)$#', $route, $matches ) ) {
					$request->set_url_params( array( 'id' => (int) $matches[1] ) );
					$controller = new Mcpwp_REST_Elementor();
					$answer     = $controller->set_elementor_data( $request );
					if ( $answer instanceof WP_Error ) {
						return $answer;
					}
					return $answer instanceof WP_REST_Response ? $answer : rest_ensure_response( $answer );
				}
				if ( '/mcpwp/v1/elementor/page' === $route ) {
					$controller = new Mcpwp_REST_Elementor();
					$answer     = $controller->create_elementor_page( $request );
					if ( $answer instanceof WP_Error ) {
						return $answer;
					}
					return $answer instanceof WP_REST_Response ? $answer : rest_ensure_response( $answer );
				}
				if ( '/mcpwp/v1/elementor/landing-page' === $route ) {
					$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;
					$controller = new Mcpwp_REST_Elementor_Pro( new Mcpwp_Elementor_Pro() );
					$answer     = $controller->create_landing_page( $request );
					if ( $answer instanceof WP_Error ) {
						return $answer;
					}
					return $answer instanceof WP_REST_Response ? $answer : rest_ensure_response( $answer );
				}
				if ( '/mcpwp/v1/pages' === $route && 'POST' === $request->get_method() ) {
					$controller = new Mcpwp_REST_Pages();
					$answer     = $controller->create_page( $request );
					if ( $answer instanceof WP_Error ) {
						return $answer;
					}
					return $answer instanceof WP_REST_Response ? $answer : rest_ensure_response( $answer );
				}
				if ( preg_match( '#^/mcpwp/v1/elementor/templates/(\d+)/apply$#', $route, $matches ) ) {
					$request->set_url_params( array( 'id' => (int) $matches[1] ) );
					$controller = new Mcpwp_REST_Elementor_Pro( new Mcpwp_Elementor_Pro() );
					$answer     = $controller->apply_template( $request );
					if ( $answer instanceof WP_Error ) {
						return $answer;
					}
					return $answer instanceof WP_REST_Response ? $answer : rest_ensure_response( $answer );
				}
				return $result;
			},
			10,
			2
		);
	}

	private function mcp_call( string $tool, array $arguments ): array {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => $tool,
					'arguments' => $arguments,
				),
			)
		);

		return $controller->handle_mcp( $request )->get_data();
	}

	private function mcp_tool_payload( array $payload ): array {
		$this->assertArrayHasKey( 'result', $payload );
		if ( isset( $payload['result']['structuredContent'] ) && is_array( $payload['result']['structuredContent'] ) ) {
			return $payload['result']['structuredContent'];
		}
		$this->assertArrayHasKey( 'content', $payload['result'] );
		$this->assertNotEmpty( $payload['result']['content'] );
		$decoded = json_decode( (string) $payload['result']['content'][0]['text'], true );
		$this->assertIsArray( $decoded, 'MCP tool result text must be JSON.' );
		return $decoded;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_set_elementor_refuses_invalid_template_in_dry_run(): void {
		$this->pretend_elementor_is_installed();

		$params = array(
			'id'             => self::PAGE_ID,
			'elementor_data' => $this->minimal_section_json(),
			'dry_run'        => true,
			'template'       => '../../wp-config.php',
		);
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/' . self::PAGE_ID, $params );
		$request->set_body_params( $params );

		$result = ( new Mcpwp_REST_Elementor() )->set_elementor_data( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_template', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'elementor_canvas', get_post_meta( self::PAGE_ID, '_wp_page_template', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_mcp_wp_set_elementor_forwards_template_and_honors_explicit_canvas(): void {
		$this->pretend_elementor_is_installed();

		$before = get_post_meta( self::PAGE_ID, '_wp_page_template', true );

		$payload = $this->mcp_call(
			'wp_set_elementor',
			array(
				'id'             => self::PAGE_ID,
				'elementor_data' => $this->minimal_section_json(),
				'dry_run'        => true,
				'template'       => 'elementor_header_footer',
				'convert'        => true,
			)
		);

		$this->assertArrayNotHasKey( 'error', $payload );
		$structured = $this->mcp_tool_payload( $payload );
		$this->assertTrue( $structured['template']['requested'] );
		$this->assertSame( Mcpwp_Page_Template::response_slug( $before ), $structured['template']['before'] );
		$this->assertSame( 'elementor_header_footer', $structured['template']['after'] );
		$this->assertSame( $before, get_post_meta( self::PAGE_ID, '_wp_page_template', true ), 'dry_run must not persist template.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_mcp_wp_set_elementor_refuses_bogus_template_without_changing_meta(): void {
		$this->pretend_elementor_is_installed();

		$payload = $this->mcp_call(
			'wp_set_elementor',
			array(
				'id'             => self::PAGE_ID,
				'elementor_data' => $this->minimal_section_json(),
				'dry_run'        => true,
				'template'       => 'not-a-real-slug',
				'convert'        => true,
			)
		);

		$this->assertArrayHasKey( 'result', $payload );
		$this->assertTrue( $payload['result']['isError'] );
		$error = $payload['result']['structuredContent']['error'] ?? json_decode( (string) $payload['result']['content'][0]['text'], true );
		$this->assertIsArray( $error );
		$this->assertSame( 'invalid_template', $error['code'] ?? ( $error['error']['code'] ?? '' ) );
		$this->assertSame( 'elementor_canvas', get_post_meta( self::PAGE_ID, '_wp_page_template', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_failed_save_leaves_page_template_unchanged_when_template_requested(): void {
		$this->pretend_elementor_is_installed();

		$before = get_post_meta( self::PAGE_ID, '_wp_page_template', true );

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/' . self::PAGE_ID );
		$request->set_body_params(
			array(
				'id'             => self::PAGE_ID,
				'elementor_data' => $this->minimal_section_json(),
				'template'       => 'elementor_header_footer',
			)
		);

		$result = ( new Mcpwp_REST_Elementor() )->set_elementor_data( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'would_convert_existing_content', $result->get_error_code() );
		$this->assertSame( $before, get_post_meta( self::PAGE_ID, '_wp_page_template', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_create_elementor_page_validates_template_before_insert(): void {
		$this->pretend_elementor_is_installed();

		$count_before = count( $GLOBALS['_mcpwp_test_posts'] );

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/page' );
		$request->set_body_params(
			array(
				'title'    => 'Should not exist',
				'status'   => 'publish',
				'template' => 'bogus-template',
			)
		);

		$result = ( new Mcpwp_REST_Elementor() )->create_elementor_page( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_template', $result->get_error_code() );
		$this->assertSame( $count_before, count( $GLOBALS['_mcpwp_test_posts'] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_build_page_reports_creation_template_not_inner_save(): void {
		$this->pretend_elementor_is_installed();

		$builder = new Mcpwp_Page_Builder();
		$result  = $builder->build(
			'Canvas build',
			array(
				array(
					'type'    => 'hero',
					'heading' => 'Hello',
				),
			),
			'draft',
			'elementor_canvas'
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['template']['requested'] );
		$this->assertSame( 'default', $result['template']['before'] );
		$this->assertSame( 'elementor_canvas', $result['template']['after'] );
		$this->assertSame( 'elementor_canvas', get_post_meta( (int) $result['id'], '_wp_page_template', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_landing_page_without_template_leaves_page_template_unset(): void {
		$this->pretend_elementor_is_installed();
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;

		$result = ( new Mcpwp_Elementor_Pro() )->create_landing_page(
			array(
				'title'          => 'Landing',
				'elementor_data' => $this->minimal_section_json(),
			)
		);

		$this->assertIsArray( $result );
		$page_id = (int) $result['id'];
		$this->assertSame( '', (string) get_post_meta( $page_id, '_wp_page_template', true ) );
		$this->assertFalse( $result['template']['requested'] );
		$this->assertSame( 'default', $result['template']['after'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_build_page_without_template_reports_false_requested(): void {
		$this->pretend_elementor_is_installed();

		$builder = new Mcpwp_Page_Builder();
		$result  = $builder->build(
			'Default build',
			array(
				array(
					'type'    => 'hero',
					'heading' => 'Hello',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['template']['requested'] );
		$this->assertSame( 'default', $result['template']['before'] );
		$this->assertSame( 'default', $result['template']['after'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_create_landing_page_refuses_bogus_template_before_insert(): void {
		$this->pretend_elementor_is_installed();
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;

		$count_before = count( $GLOBALS['_mcpwp_test_posts'] );
		$request      = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/landing-page' );
		$request->set_body_params(
			array(
				'title'          => 'Should not exist',
				'elementor_data' => $this->minimal_section_json(),
				'template'       => 'bogus-template-slug',
			)
		);

		$result = ( new Mcpwp_REST_Elementor_Pro( new Mcpwp_Elementor_Pro() ) )->create_landing_page( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_template', $result->get_error_code() );
		$this->assertSame( $count_before, count( $GLOBALS['_mcpwp_test_posts'] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_create_landing_page_forwards_template_to_core(): void {
		$this->pretend_elementor_is_installed();
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/landing-page' );
		$request->set_body_params(
			array(
				'title'          => 'Canvas landing',
				'elementor_data' => $this->minimal_section_json(),
				'template'       => 'elementor_canvas',
			)
		);

		$response = ( new Mcpwp_REST_Elementor_Pro( new Mcpwp_Elementor_Pro() ) )->create_landing_page( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['template']['requested'] );
		$this->assertSame( 'elementor_canvas', $data['template']['after'] );
		$this->assertSame( 'elementor_canvas', get_post_meta( (int) $data['id'], '_wp_page_template', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_mcp_wp_create_page_keeps_template_slug_and_template_change(): void {
		$payload = $this->mcp_call(
			'wp_create_page',
			array(
				'title'    => 'MCP plain page',
				'template' => 'elementor_header_footer',
			)
		);

		$this->assertArrayNotHasKey( 'error', $payload );
		$structured = $this->mcp_tool_payload( $payload );
		$this->assertSame( 'elementor_header_footer', $structured['template'] );
		$this->assertTrue( $structured['template_change']['requested'] );
		$this->assertSame( 'elementor_header_footer', $structured['template_change']['after'] );
	}

	public function test_rest_apply_part_insert_refuses_template_argument(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/parts/1/apply' );
		$request->set_url_params( array( 'id' => 1 ) );
		$request->set_body_params(
			array(
				'page_id'  => self::PAGE_ID,
				'mode'     => 'insert',
				'template' => 'elementor_canvas',
			)
		);

		$mock = $this->getMockBuilder( Mcpwp_Elementor_Pro::class )
			->onlyMethods( array( 'insert_part_into_page' ) )
			->getMock();
		$mock->expects( $this->never() )->method( 'insert_part_into_page' );

		$result = ( new Mcpwp_REST_Elementor_Pro( $mock ) )->apply_part( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'template_not_applicable_in_insert_mode', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertSame( 'insert', $data['mode'] );
		$this->assertSame( 'elementor_canvas', $data['template'] );
	}

	public function test_rest_apply_part_insert_refuses_invalid_template_argument(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/parts/1/apply' );
		$request->set_url_params( array( 'id' => 1 ) );
		$request->set_body_params(
			array(
				'page_id'  => self::PAGE_ID,
				'mode'     => 'insert',
				'template' => 'not-a-real-template',
			)
		);

		$mock = $this->getMockBuilder( Mcpwp_Elementor_Pro::class )->getMock();
		$result = ( new Mcpwp_REST_Elementor_Pro( $mock ) )->apply_part( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_template', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_mcp_apply_tools_accept_template_parameter(): void {
		foreach ( array( 'wp_apply_elementor_template', 'wp_apply_elementor_archetype', 'wp_apply_elementor_part' ) as $tool ) {
			$payload = $this->mcp_call(
				$tool,
				array(
					'id'       => 99991,
					'page_id'  => self::PAGE_ID,
					'template' => 'elementor_canvas',
				)
			);
			$this->assertArrayHasKey( 'result', $payload );
			$is_error = ! empty( $payload['result']['isError'] );
			if ( $is_error ) {
				$error = $payload['result']['structuredContent']['error'] ?? json_decode( (string) $payload['result']['content'][0]['text'], true );
				$this->assertIsArray( $error );
				$this->assertNotSame( 'invalid_arguments', $error['code'] ?? ( $error['error']['code'] ?? '' ), "{$tool} must accept template in the schema." );
			}
		}
	}

	public function test_rest_apply_template_surfaces_template_report(): void {
		$mock = $this->getMockBuilder( Mcpwp_Elementor_Pro::class )
			->onlyMethods( array( 'apply_template_to_page' ) )
			->getMock();
		$mock->method( 'apply_template_to_page' )->willReturn(
			array(
				'success'  => true,
				'template' => array(
					'before'    => 'default',
					'after'     => 'elementor_canvas',
					'requested' => true,
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/templates/1/apply' );
		$request->set_url_params( array( 'id' => 1 ) );
		$request->set_body_params(
			array(
				'page_id'  => self::PAGE_ID,
				'template' => 'elementor_canvas',
			)
		);

		$response = ( new Mcpwp_REST_Elementor_Pro( $mock ) )->apply_template( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['template']['requested'] );
		$this->assertSame( 'elementor_canvas', $data['template']['after'] );
	}
}