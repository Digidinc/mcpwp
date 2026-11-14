<?php
/**
 * "Add to ChatGPT" key vs Elementor templates and Custom Code snippets,
 * end to end (#1096 P2-2).
 *
 * The connector key authenticates against the generic `/elementor/{id}` and
 * `/posts/{id}` routes, so the refusal for an `elementor_library` template or
 * an `elementor_snippet` Custom Code post is not made at the auth check: it is
 * made by the handlers (Mcpwp_Elementor_Basic::validate_post() and the
 * Mcpwp_Posts category gate). This test mints the real connector link, sends a
 * request authenticated by that key alone, and drives the handler the route
 * dispatches to — so deleting either handler check fails here, and so does a
 * connector key that gains a template or snippet category.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-chatgpt-connector.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-elementor-basic.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';

final class ChatGptConnectorTemplateRefusalTest extends TestCase {

	private const PAGE_ID     = 9301;
	private const TEMPLATE_ID = 9302; // elementor_library
	private const SNIPPET_ID  = 9303; // elementor_snippet

	/** @var string Plaintext connector key. */
	private $key = '';

	protected function setUp(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0' );
		}
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['mcpwp_test_filters']            = array();
		$GLOBALS['mcpwp_test_user_caps']          = array();
		$GLOBALS['mcpwp_test_public_post_types']  = array( 'elementor_library' );
		$GLOBALS['_mcpwp_test_meta']              = array();
		$_GET                                     = array();

		$types = array(
			self::PAGE_ID     => array( 'page', 'About' ),
			self::TEMPLATE_ID => array( 'elementor_library', 'Saved template' ),
			self::SNIPPET_ID  => array( 'elementor_snippet', 'Header code' ),
		);
		$GLOBALS['_mcpwp_test_posts'] = array();
		foreach ( $types as $id => $t ) {
			$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post(
				array(
					'ID'           => $id,
					'post_type'    => $t[0],
					'post_title'   => $t[1],
					'post_name'    => sanitize_title( $t[1] ),
					'post_status'  => 'publish',
					'post_content' => '',
				)
			);
			$GLOBALS['_mcpwp_test_meta'][ $id ]['_elementor_data'] = '[]';
		}

		$auth      = new Mcpwp_REST_Elementor();
		$connector = new Mcpwp_ChatGPT_Connector( $auth );
		$url       = $connector->create_or_rotate()['url'];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$this->key = $q['api_key'];
	}

	protected function tearDown(): void {
		$_GET                               = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_options']      = array();
	}

	/**
	 * Authenticate the way ChatGPT does (key only in the URL), then call the
	 * handler the route dispatches to. If authentication itself refuses, that
	 * refusal is the real answer: WordPress never reaches the handler then.
	 */
	private function call( object $controller, string $handler, string $method, string $route, int $id ) {
		$_GET    = array( 'api_key' => $this->key );
		$request = new WP_REST_Request( $method, $route, array( 'id' => $id ) );
		$auth    = $controller->check_permission( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		return $controller->$handler( $request );
	}

	/**
	 * The handler layer on its own. A later hardening (#1030) also refuses a
	 * template/snippet id at the auth check, which would mask a deleted handler
	 * check. So authenticate the connector key on a route it may use (a page),
	 * which wires the same per-request category callback production wires, and
	 * hand the handler the template/snippet id directly.
	 */
	private function call_handler_only( object $controller, string $handler, string $method, int $id ) {
		$_GET    = array( 'api_key' => $this->key );
		$page    = new WP_REST_Request( $method, '/mcpwp/v1/elementor/' . self::PAGE_ID, array( 'id' => self::PAGE_ID ) );
		$this->assertTrue( $controller->check_permission( $page ), 'Premise: the key authenticates on a page route.' );
		return $controller->$handler( new WP_REST_Request( $method, '/mcpwp/v1/elementor/' . $id, array( 'id' => $id ) ) );
	}

	private function assert_not_found( $result, string $what ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $what );
		$this->assertSame( 'not_found', $result->get_error_code(), $what );
		$this->assertSame( 404, $result->get_error_data()['status'], $what );
	}

	public function test_the_connector_key_holds_no_template_or_snippet_category(): void {
		$this->assertNotContains( 'elementor-templates', Mcpwp_ChatGPT_Connector::CATEGORIES );
		$this->assertNotContains( 'elementor-theme', Mcpwp_ChatGPT_Connector::CATEGORIES );
	}

	public function test_the_key_can_read_a_plain_page_through_the_elementor_route(): void {
		$result = $this->call( new Mcpwp_REST_Elementor(), 'get_elementor_data', 'GET', '/mcpwp/v1/elementor/' . self::PAGE_ID, self::PAGE_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $result, 'Control: the same route works for a page, so the refusals below are the post type, not the route.' );
	}

	public function test_the_key_is_refused_a_template_through_the_elementor_route(): void {
		$result = $this->call( new Mcpwp_REST_Elementor(), 'get_elementor_data', 'GET', '/mcpwp/v1/elementor/' . self::TEMPLATE_ID, self::TEMPLATE_ID );
		$this->assert_not_found( $result, 'GET /elementor/{id} on an elementor_library template.' );
	}

	public function test_the_key_is_refused_a_snippet_through_the_elementor_route(): void {
		$result = $this->call( new Mcpwp_REST_Elementor(), 'get_elementor_data', 'GET', '/mcpwp/v1/elementor/' . self::SNIPPET_ID, self::SNIPPET_ID );
		$this->assert_not_found( $result, 'GET /elementor/{id} on an elementor_snippet post.' );
	}

	public function test_the_key_is_refused_a_template_through_the_posts_routes(): void {
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';

		foreach ( array(
			'get_post'    => 'GET',
			'update_post' => 'PUT',
			'delete_post' => 'DELETE',
		) as $handler => $method ) {
			foreach ( array( self::TEMPLATE_ID, self::SNIPPET_ID ) as $id ) {
				$result = $this->call( new Mcpwp_REST_Posts(), $handler, $method, '/mcpwp/v1/posts/' . $id, $id );
				$this->assert_not_found( $result, "{$method} /posts/{$id}" );
			}
		}
		$this->assertArrayHasKey( self::TEMPLATE_ID, $GLOBALS['_mcpwp_test_posts'], 'Nothing was deleted.' );
	}

	public function test_the_elementor_handler_alone_refuses_a_template_and_a_snippet(): void {
		foreach ( array( self::TEMPLATE_ID, self::SNIPPET_ID ) as $id ) {
			$result = $this->call_handler_only( new Mcpwp_REST_Elementor(), 'get_elementor_data', 'GET', $id );
			$this->assert_not_found( $result, "get_elementor_data({$id}) behind a connector key" );
		}
	}

	public function test_the_posts_handlers_alone_refuse_a_template_and_a_snippet(): void {
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';

		foreach ( array( 'get_post' => 'GET', 'update_post' => 'PUT', 'delete_post' => 'DELETE' ) as $handler => $method ) {
			foreach ( array( self::TEMPLATE_ID, self::SNIPPET_ID ) as $id ) {
				$result = $this->call_handler_only( new Mcpwp_REST_Posts(), $handler, $method, $id );
				$this->assert_not_found( $result, "{$handler}({$id}) behind a connector key" );
			}
		}
		$this->assertArrayHasKey( self::TEMPLATE_ID, $GLOBALS['_mcpwp_test_posts'], 'Nothing was deleted.' );
	}
}
