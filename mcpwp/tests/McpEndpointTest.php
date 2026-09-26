<?php

use PHPUnit\Framework\TestCase;

final class McpEndpointTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']             = array();
		$GLOBALS['mcpwp_test_transients']          = array();
		$GLOBALS['mcpwp_test_dispatched_routes']   = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_dispatched_routes']   = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
	}

	/**
	 * Build a resources/read request for a given resource uri, carrying the
	 * given X-API-Key on the OUTER /mcp request — exactly how a real client's
	 * single request would carry it.
	 */
	private function resources_read_request( $uri, $api_key = null ) {
		$headers = null !== $api_key ? array( 'X-API-Key' => $api_key ) : array();

		return new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			$headers,
			array(
				'jsonrpc' => '2.0',
				'id'      => 9,
				'method'  => 'resources/read',
				'params'  => array( 'uri' => $uri ),
			)
		);
	}

	public function test_initialize_advertises_resources_capability(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'clientInfo' => array(
						'name'    => 'phpunit',
						'version' => '1.0',
					),
				),
			)
		);

		$response = $controller->handle_mcp( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'capabilities', $data['result'] );
		$this->assertArrayHasKey( 'resources', $data['result']['capabilities'] );
	}

	public function test_resources_list_returns_curated_resources(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'resources/list',
				'params'  => array(),
			)
		);

		$response  = $controller->handle_mcp( $request );
		$data      = $response->get_data();
		$resources = $data['result']['resources'];

		// resources/list exposes a curated, non-empty set of MCP resources.
		$this->assertIsArray( $resources );
		$this->assertNotEmpty( $resources );

		$names = array_column( $resources, 'name' );
		$this->assertContains( 'Site Info', $names );
		$this->assertContains( 'Elementor Status', $names );

		foreach ( $resources as $resource ) {
			$this->assertArrayHasKey( 'uri', $resource );
			$this->assertArrayHasKey( 'name', $resource );
			$this->assertArrayHasKey( 'mimeType', $resource );
		}
	}

	/**
	 * #902: resources/read used to build the internal sub-request's route as
	 * "/wp-json/mcpwp/v1/site-info" — a route no registered handler ever
	 * matches, because "/wp-json" is stripped by the front-controller
	 * rewrite before WP_REST_Server::dispatch() ever sees a route. That
	 * 404'd (rest_no_route) on every server, Apache included. The bare route
	 * ("/mcpwp/v1/site-info") is what rest_do_request() must receive.
	 */
	public function test_resources_read_dispatches_bare_route_for_every_advertised_resource(): void {
		$controller = new Mcpwp_REST_MCP();

		$list_response = $controller->handle_mcp(
			new WP_REST_Request(
				'POST',
				'/mcpwp/v1/mcp',
				array(),
				array(),
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'resources/list',
					'params'  => array(),
				)
			)
		);
		$resources = $list_response->get_data()['result']['resources'];
		$this->assertNotEmpty( $resources );

		foreach ( $resources as $resource ) {
			$GLOBALS['mcpwp_test_dispatched_routes'] = array();

			$response = $controller->handle_mcp( $this->resources_read_request( $resource['uri'] ) );
			$data     = $response->get_data();

			$this->assertArrayHasKey( 'result', $data, 'Resource "' . $resource['name'] . '" (' . $resource['uri'] . ') did not resolve.' );
			$this->assertNotEmpty( $GLOBALS['mcpwp_test_dispatched_routes'], 'Expected an internal REST dispatch for ' . $resource['uri'] );

			foreach ( $GLOBALS['mcpwp_test_dispatched_routes'] as $dispatched_route ) {
				$this->assertStringStartsWith( '/mcpwp/v1/', $dispatched_route );
				$this->assertStringNotContainsString( 'wp-json', $dispatched_route );
			}

			$contents = $data['result']['contents'][0];
			$this->assertSame( $resource['uri'], $contents['uri'] );
			$this->assertSame( $resource['mimeType'], $contents['mimeType'] );
			$this->assertIsString( $contents['text'] );
		}
	}

	/**
	 * #902: the API key was re-read from apache_request_headers() /
	 * getallheaders() — both undefined under php-fpm + nginx (and in this
	 * test process, same as any non-Apache SAPI), so it silently vanished.
	 * get_api_key_from_request() reads WP_REST_Request::get_header(), which
	 * carries the key on every server.
	 */
	public function test_resources_read_carries_api_key_onto_internal_sub_request(): void {
		$controller = new Mcpwp_REST_MCP();

		$controller->handle_mcp(
			$this->resources_read_request( 'https://example.com/wp-json/mcpwp/v1/site-info', 'mcpwp_secret_test_key' )
		);

		$this->assertNotEmpty( $GLOBALS['mcpwp_test_dispatched_requests'] );
		$sub_request = end( $GLOBALS['mcpwp_test_dispatched_requests'] );
		$this->assertSame( 'mcpwp_secret_test_key', $sub_request->get_header( 'X-API-Key' ) );
	}

	/**
	 * #902: resources/read must also accept the plugin's own mcpwp://
	 * shorthand — independent of the host/scheme mismatches a reverse proxy
	 * or a port-mapped dev host can introduce between get_site_url() and the
	 * URL an agent actually used to reach the site.
	 */
	public function test_resources_read_accepts_mcpwp_scheme_uri(): void {
		$controller = new Mcpwp_REST_MCP();

		$response = $controller->handle_mcp( $this->resources_read_request( 'mcpwp://site-info' ) );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'result', $data );
		$this->assertSame( '/mcpwp/v1/site-info', end( $GLOBALS['mcpwp_test_dispatched_routes'] ) );
		$this->assertSame( 'mcpwp://site-info', $data['result']['contents'][0]['uri'] );
	}

	/**
	 * #902: a client that copied the exact URI resources/list advertised
	 * must still resolve it even when the scheme/host it used to reach the
	 * site (e.g. a docker port mapping, or a reverse proxy) differs from
	 * what get_site_url() returns inside the PHP process — matching is by
	 * REST route, not by exact string equality against the site URL.
	 */
	public function test_resources_read_matches_by_route_despite_host_mismatch(): void {
		$controller = new Mcpwp_REST_MCP();

		$response = $controller->handle_mcp(
			$this->resources_read_request( 'http://127.0.0.1:8091/wp-json/mcpwp/v1/site-info' )
		);
		$data = $response->get_data();

		$this->assertArrayHasKey( 'result', $data );
		$this->assertSame( '/mcpwp/v1/site-info', end( $GLOBALS['mcpwp_test_dispatched_routes'] ) );
	}

	/**
	 * A plain-permalinks site advertises "?rest_route=" URIs, not
	 * "/wp-json/" ones. resources/read must resolve those too.
	 */
	public function test_resources_read_matches_plain_permalink_rest_route_uri(): void {
		$controller = new Mcpwp_REST_MCP();

		$response = $controller->handle_mcp(
			$this->resources_read_request( 'https://example.com/?rest_route=/mcpwp/v1/site-info' )
		);
		$data = $response->get_data();

		$this->assertArrayHasKey( 'result', $data );
		$this->assertSame( '/mcpwp/v1/site-info', end( $GLOBALS['mcpwp_test_dispatched_routes'] ) );
	}

	public function test_resources_read_unknown_uri_returns_resource_not_found_error(): void {
		$controller = new Mcpwp_REST_MCP();

		$response = $controller->handle_mcp(
			$this->resources_read_request( 'https://example.com/wp-json/mcpwp/v1/unlisted-endpoint' )
		);
		$data = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32002, $data['error']['code'] );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_routes'] );
	}

	public function test_tools_list_includes_annotations_hints(): void {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'tools/list',
				'params'  => array(),
			)
		);

		$response = $controller->handle_mcp( $request );
		$data     = $response->get_data();
		$tools    = $data['result']['tools'];

		$by_name = array();
		foreach ( $tools as $tool ) {
			$by_name[ $tool['name'] ] = $tool;
		}

		$this->assertArrayHasKey( 'wp_site_info', $by_name );
		$this->assertArrayHasKey( 'annotations', $by_name['wp_site_info'] );
		$this->assertArrayHasKey( 'readOnlyHint', $by_name['wp_site_info']['annotations'] );
		$this->assertArrayHasKey( 'openWorldHint', $by_name['wp_site_info']['annotations'] );
		$this->assertArrayHasKey( 'destructiveHint', $by_name['wp_site_info']['annotations'] );
		$this->assertTrue( $by_name['wp_site_info']['annotations']['readOnlyHint'] );
		$this->assertFalse( $by_name['wp_site_info']['annotations']['openWorldHint'] );
		$this->assertFalse( $by_name['wp_site_info']['annotations']['destructiveHint'] );

		$this->assertArrayHasKey( 'wp_delete_post', $by_name );
		$this->assertTrue( $by_name['wp_delete_post']['annotations']['destructiveHint'] );
		$this->assertFalse( $by_name['wp_delete_post']['annotations']['readOnlyHint'] );

		$this->assertArrayHasKey( 'wp_search', $by_name );
		$this->assertTrue( $by_name['wp_search']['annotations']['readOnlyHint'] );
		$this->assertArrayHasKey( 'wp_fetch', $by_name );
		$this->assertTrue( $by_name['wp_fetch']['annotations']['readOnlyHint'] );
	}
}
