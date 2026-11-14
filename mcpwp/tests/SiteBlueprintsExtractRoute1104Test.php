<?php
/**
 * #1104 — GET /site-blueprints/extract must reach extract_blueprint().
 *
 * WordPress matches REST routes in registration order. The by-id route,
 * /site-blueprints/(?P<id>[a-z0-9_-]+), also matches the literal "extract",
 * and it used to be registered first, so GET /site-blueprints/extract
 * answered "Blueprint 'extract' not found." from get_blueprint().
 *
 * The suite's rest_do_request() double does not route, so these tests
 * dispatch through mcpwp_test_match_rest_route() — core's
 * match_request_to_handler() loop, over the routes the controller actually
 * registered — and then run the handler it picked.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/helpers/rest-route-uniqueness.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-site-blueprints.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-blueprints.php';

final class SiteBlueprintsExtractRoute1104Test extends TestCase {

	/** @var Mcpwp_REST_Site_Blueprints */
	private $controller;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$this->controller                         = new Mcpwp_REST_Site_Blueprints();
		$this->controller->register_routes();
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['mcpwp_test_options']            = array();
	}

	/** Route the request the way core does, then run the chosen handler. */
	private function dispatch( string $method, string $path ) {
		$match = mcpwp_test_match_rest_route( rest_get_server()->get_routes(), $method, $path );
		$this->assertNotNull( $match, "$method $path matched no route" );

		$request = new WP_REST_Request( $method, $path );
		foreach ( $match['params'] as $name => $value ) {
			$request->set_param( $name, $value );
		}
		foreach ( $match['handler']['args'] ?? array() as $name => $arg ) {
			if ( null === $request->get_param( $name ) && array_key_exists( 'default', $arg ) ) {
				$request->set_param( $name, $arg['default'] );
			}
		}
		return array( $match, call_user_func( $match['handler']['callback'], $request ) );
	}

	public function test_get_extract_reaches_the_extract_handler(): void {
		list( $match, $response ) = $this->dispatch( 'GET', '/mcpwp/v1/site-blueprints/extract' );

		$this->assertSame( '/mcpwp/v1/site-blueprints/extract', $match['route'] );
		$this->assertSame( array( $this->controller, 'extract_blueprint' ), $match['handler']['callback'] );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'blueprint', $data, 'Got the by-id handler\'s answer instead of an extraction.' );
		$this->assertFalse( $data['saved'] );
		$this->assertStringStartsWith( 'extracted-', $data['blueprint']['id'] );
	}

	// ── Extracting is read-only (saving stays on the admin POST) ─────────

	public static function truthy_save_values(): array {
		return array(
			'bool true' => array( true ),
			'"true"'    => array( 'true' ),
			'"1"'       => array( '1' ),
			'int 1'     => array( 1 ),
			'"yes"'     => array( 'yes' ),
			'"on"'      => array( 'on' ),
			'"TRUE"'    => array( 'TRUE' ),
		);
	}

	/**
	 * @dataProvider truthy_save_values
	 */
	public function test_a_truthy_save_on_extract_is_refused_and_stores_nothing( $save ): void {
		list( $match ) = $this->dispatch( 'GET', '/mcpwp/v1/site-blueprints/extract' );
		$request       = new WP_REST_Request( 'GET', '/mcpwp/v1/site-blueprints/extract', array( 'save' => $save ) );
		$response      = call_user_func( $match['handler']['callback'], $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'save_not_supported', $response->get_data()['code'] );
		$this->assertSame( array(), Mcpwp_Site_Blueprints::list_all( false ), 'Nothing may be stored by a GET.' );
		$this->assertFalse( get_option( Mcpwp_Site_Blueprints::OPTION_KEY, false ) );
	}

	public static function falsy_save_values(): array {
		return array(
			'bool false' => array( false ),
			'"false"'    => array( 'false' ),
			'"0"'        => array( '0' ),
			'empty'      => array( '' ),
		);
	}

	/**
	 * @dataProvider falsy_save_values
	 */
	public function test_a_falsy_save_on_extract_still_extracts( $save ): void {
		$request  = new WP_REST_Request( 'GET', '/mcpwp/v1/site-blueprints/extract', array( 'save' => $save ) );
		$response = $this->controller->extract_blueprint( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['saved'] );
		$this->assertSame( array(), Mcpwp_Site_Blueprints::list_all( false ) );
	}

	public function test_extract_route_declares_no_save_arg(): void {
		$match = mcpwp_test_match_rest_route( rest_get_server()->get_routes(), 'GET', '/mcpwp/v1/site-blueprints/extract' );
		$this->assertArrayNotHasKey( 'save', $match['handler']['args'] ?? array() );
	}

	public function test_two_extractions_get_distinct_ids(): void {
		$a = Mcpwp_Site_Blueprints::extract();
		$b = Mcpwp_Site_Blueprints::extract();
		$this->assertNotSame( $a['id'], $b['id'], 'Storing two same-day extractions must not overwrite one with the other.' );
		$this->assertMatchesRegularExpression( '/^extracted-\d{8}-[a-f0-9]{8}$/', $a['id'] );
		// Still routable by the by-id pattern.
		$this->assertMatchesRegularExpression( '@^[a-z0-9_-]+$@', $a['id'] );
	}

	public function test_mcp_extract_tool_is_read_only_and_cannot_store(): void {
		$free = new Mcpwp_MCP_Free_Tools();
		$this->assertTrue( $free->get_tool_annotations( 'wp_extract_site_blueprint' )['readOnlyHint'] );
		foreach ( $free->get_tools() as $tool ) {
			if ( 'wp_extract_site_blueprint' === $tool['name'] ) {
				$this->assertArrayNotHasKey( 'save', (array) ( $tool['inputSchema']['properties'] ?? array() ) );
			}
		}

		// tools/call still forwards a stray `save` to the route (the tool
		// declares no params, so there is no schema to refuse it against);
		// the handler is what refuses it, for every caller.
		$GLOBALS['mcpwp_test_current_user']        = 2;
		$GLOBALS['mcpwp_test_filters']             = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
		foreach ( array( true, 'yes', '1' ) as $save ) {
			$GLOBALS['mcpwp_test_dispatched_requests'] = array();
			( new Mcpwp_REST_MCP() )->handle_mcp(
				new WP_REST_Request(
					'POST',
					'/mcpwp/v1/mcp',
					array(),
					array(),
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'tools/call',
						'params'  => array(
							'name'      => 'wp_extract_site_blueprint',
							'arguments' => array( 'save' => $save ),
						),
					)
				)
			);
			$this->assertNotEmpty( $GLOBALS['mcpwp_test_dispatched_requests'], 'Premise: the call reaches the route.' );
			$sub = end( $GLOBALS['mcpwp_test_dispatched_requests'] );
			$this->assertSame( '/mcpwp/v1/site-blueprints/extract', $sub->get_route() );
			$match = mcpwp_test_match_rest_route( rest_get_server()->get_routes(), $sub->get_method(), $sub->get_route() );
			$this->assertSame( 400, call_user_func( $match['handler']['callback'], $sub )->get_status(), var_export( $save, true ) );
		}
		$this->assertFalse( get_option( Mcpwp_Site_Blueprints::OPTION_KEY, false ) );
	}

	public function test_get_by_id_still_reaches_the_by_id_handler(): void {
		list( $match, $response ) = $this->dispatch( 'GET', '/mcpwp/v1/site-blueprints/law-firm' );

		$this->assertSame( array( $this->controller, 'get_blueprint' ), $match['handler']['callback'] );
		$this->assertSame( 'law-firm', $match['params']['id'] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'law-firm', $response->get_data()['id'] );
	}

	public function test_other_methods_on_the_shared_prefix_are_unchanged(): void {
		// The extract route has no DELETE handler, so core falls through to the id route.
		$delete = mcpwp_test_match_rest_route( rest_get_server()->get_routes(), 'DELETE', '/mcpwp/v1/site-blueprints/abc' );
		$this->assertSame( array( $this->controller, 'delete_blueprint' ), $delete['handler']['callback'] );

		$deploy = mcpwp_test_match_rest_route( rest_get_server()->get_routes(), 'POST', '/mcpwp/v1/site-blueprints/abc/deploy' );
		$this->assertSame( array( $this->controller, 'deploy_blueprint' ), $deploy['handler']['callback'] );

		$list = mcpwp_test_match_rest_route( rest_get_server()->get_routes(), 'GET', '/mcpwp/v1/site-blueprints' );
		$this->assertSame( array( $this->controller, 'list_blueprints' ), $list['handler']['callback'] );
	}

	public function test_no_blueprints_route_is_shadowed(): void {
		$this->assertSame( array(), mcpwp_test_shadowed_rest_routes( rest_get_server()->get_routes() ) );
	}

	/** A blueprint stored as "extract" could be listed and deleted but never fetched. */
	public function test_extract_is_a_reserved_blueprint_id(): void {
		foreach ( array( 'extract', 'EXTRACT' ) as $id ) {
			$request = new WP_REST_Request( 'POST', '/mcpwp/v1/site-blueprints' );
			$request->set_body( wp_json_encode( array( 'id' => $id, 'name' => 'X', 'pages' => array( array( 'slug' => 'home', 'title' => 'Home' ) ) ) ) );
			$request->set_header( 'content-type', 'application/json' );
			$response = $this->controller->create_blueprint( $request );
			$this->assertSame( 400, $response->get_status(), $id );
		}
		$this->assertNull( Mcpwp_Site_Blueprints::get( 'extract' ) );
	}

	/** The capability map resolves in row order; it must agree with routing. */
	public function test_capability_map_resolves_extract_to_its_own_row(): void {
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-route-capability-map.php';
		$row = Mcpwp_Route_Capability_Map::resolve( 'GET', '/mcpwp/v1/site-blueprints/extract' );
		$this->assertSame( 'GET /site-blueprints/extract', $row['key'] );
	}
}
