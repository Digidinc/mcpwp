<?php
/**
 * MCP protocol version negotiation and the version-gated tool shape (#906).
 *
 * - initialize echoes a supported requested version, otherwise answers the
 *   latest supported one (missing, unknown, future, or not a string).
 * - Every later request is governed by its MCP-Protocol-Version header (an
 *   initialize inside the same batch wins); no header means 2025-03-26, per
 *   the spec; an unsupported or malformed header is a 400.
 * - Pre-2025-06-18: tools carry no top-level `title` / `outputSchema` and
 *   results carry no structuredContent for a tool's own output — but
 *   `annotations.title` (#934) and the isError structuredContent.error
 *   envelope (#936) stay under every version.
 * - 2025-06-18+: tools with a stable result shape declare an outputSchema,
 *   and their successful results carry structuredContent that validates
 *   against it — checked here against results produced by the real
 *   formatting code (Mcpwp_Pages / Mcpwp_Posts / Mcpwp_Core), dispatched
 *   through the real tools/call path.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class McpProtocolVersionTest extends TestCase {

	const OLD_VERSIONS        = array( '2024-11-05', '2025-03-26' );
	const STRUCTURED_VERSIONS = array( '2025-06-18', '2025-11-25' );

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$GLOBALS['_mcpwp_test_posts']            = array();
		$GLOBALS['_mcpwp_test_meta']             = array();
		unset( $GLOBALS['mcpwp_test_rest_response_override'] );
		Mcpwp_REST_MCP::reset_verdict_memo();
		// Core always defines it; get_site_info() reads it.
		$this->previous_wp_version = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : null;
		$GLOBALS['wp_version']     = '6.9';
		unset( $GLOBALS['mcpwp_test_theme_headers'] );
	}

	/** @var string|null */
	private $previous_wp_version;

	protected function tearDown(): void {
		$GLOBALS['wp_version'] = $this->previous_wp_version;
		unset( $GLOBALS['mcpwp_test_theme_headers'] );
		$GLOBALS['mcpwp_test_filters'] = array();
		Mcpwp_Integration::clear_cache();
		unset( $GLOBALS['mcpwp_test_rest_response_override'] );
		$GLOBALS['_mcpwp_test_posts'] = array();
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * POST one JSON-RPC message (or a batch) to /mcp.
	 *
	 * @param array       $body    JSON-RPC body.
	 * @param string|null $version MCP-Protocol-Version header, or none.
	 * @return mixed Decoded response data (via a JSON round trip, as the wire sees it).
	 */
	private function post( array $body, $version = null, $assoc = true ) {
		return json_decode( wp_json_encode( $this->post_raw( $body, $version )->get_data() ), $assoc );
	}

	private function post_raw( array $body, $version = null ) {
		$headers = null === $version ? array() : array( 'MCP-Protocol-Version' => $version );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), $headers, $body );
		return ( new Mcpwp_REST_MCP() )->handle_mcp( $request );
	}

	private function initialize( $requested, $include = true ): array {
		$params = array(
			'capabilities' => new stdClass(),
			'clientInfo'   => array( 'name' => 'test', 'version' => '1' ),
		);
		if ( $include ) {
			$params['protocolVersion'] = $requested;
		}
		return $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => $params,
			)
		);
	}

	private function tools_list( $version ): array {
		$data  = $this->post( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ), $version );
		$tools = array();
		foreach ( $data['result']['tools'] as $tool ) {
			$tools[ $tool['name'] ] = $tool;
		}
		return $tools;
	}

	private function tools_call( string $name, array $arguments, $version, $assoc = true ) {
		return $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => $name,
					'arguments' => $arguments,
				),
			),
			$version,
			$assoc
		);
	}

	private function seed_page( int $id, string $slug ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'                => $id,
			'post_type'         => 'page',
			'post_title'        => 'About &amp; Team',
			'post_name'         => $slug,
			'post_status'       => 'publish',
			'post_date'         => '2026-06-01 00:00:00',
			'post_date_gmt'     => '2026-06-01 00:00:00',
			'post_modified'     => '2026-06-02 00:00:00',
			'post_modified_gmt' => '2026-06-02 00:00:00',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_author'       => 1,
			'post_content'      => 'We build things.',
			'post_excerpt'      => '',
		);
	}

	private function seed_post( int $id ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'                => $id,
			'post_type'         => 'post',
			'post_title'        => 'Hello',
			'post_name'         => 'hello',
			'post_status'       => 'publish',
			'post_date'         => '2026-06-01 00:00:00',
			'post_date_gmt'     => '2026-06-01 00:00:00',
			'post_modified'     => '2026-06-02 00:00:00',
			'post_modified_gmt' => '2026-06-02 00:00:00',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_author'       => 1,
			'post_content'      => 'Body text here.',
			'post_excerpt'      => '',
		);
	}

	/**
	 * Every tool that declares an outputSchema, with a real result for it:
	 * the value the tool's REST route answers with, produced by the same
	 * core method that route calls. Adding a schema without adding it here
	 * fails test_every_declared_output_schema_has_a_validated_real_result().
	 *
	 * @return array<string,array{0:array,1:callable}> name => [arguments, producer]
	 */
	private function real_results(): array {
		return array(
			'wp_site_info'        => array(
				array(),
				function () {
					return ( new Mcpwp_Core() )->get_site_info();
				},
			),
			// A deleted/broken active theme: WP_Theme::get() answers false
			// for Name/Version (review BLOCK-1).
			'wp_site_info (missing theme)' => array(
				array(),
				function () {
					$GLOBALS['mcpwp_test_theme_headers'] = array();
					return ( new Mcpwp_Core() )->get_site_info();
				},
			),
			'wp_list_pages'       => array(
				array(),
				function () {
					$this->seed_page( 41, 'about' );
					return ( new Mcpwp_Pages() )->list_pages( array() );
				},
			),
			'wp_get_page_by_slug' => array(
				array( 'slug' => 'about' ),
				function () {
					$this->seed_page( 41, 'about' );
					return ( new Mcpwp_Pages() )->get_page( 41 );
				},
			),
			'wp_list_posts'       => array(
				array(),
				function () {
					$this->seed_post( 51 );
					return ( new Mcpwp_Posts() )->list_posts( array() );
				},
			),
		);
	}

	/**
	 * Tool names covered by real_results() (a case key may carry a suffix).
	 *
	 * @return string[]
	 */
	private function schema_tool_names(): array {
		$names = array_values( array_unique( array_map( function ( $case ) { return explode( ' ', $case )[0]; }, array_keys( $this->real_results() ) ) ) );
		sort( $names );
		return $names;
	}

	// ---------------------------------------------------------------
	// Negotiation matrix
	// ---------------------------------------------------------------

	public function test_initialize_echoes_every_supported_version(): void {
		foreach ( array_merge( self::OLD_VERSIONS, self::STRUCTURED_VERSIONS ) as $version ) {
			$data = $this->initialize( $version );
			$this->assertSame( $version, $data['result']['protocolVersion'], "A supported requested version ($version) is echoed." );
		}
	}

	public function test_initialize_answers_latest_for_unknown_future_missing_or_malformed(): void {
		$this->assertSame( '2025-11-25', $this->initialize( '2099-01-01' )['result']['protocolVersion'], 'Unknown future version.' );
		$this->assertSame( '2025-11-25', $this->initialize( '2023-01-01' )['result']['protocolVersion'], 'Unknown past version.' );
		$this->assertSame( '2025-11-25', $this->initialize( null, false )['result']['protocolVersion'], 'Missing protocolVersion.' );
		$this->assertSame( '2025-11-25', $this->initialize( array( '2025-06-18' ) )['result']['protocolVersion'], 'Non-string protocolVersion.' );
		$this->assertSame( '2025-11-25', $this->initialize( '' )['result']['protocolVersion'], 'Empty protocolVersion.' );
	}

	public function test_get_capability_probe_advertises_latest(): void {
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/mcp' );
		$data    = ( new Mcpwp_REST_MCP() )->handle_mcp_get( $request )->get_data();
		$this->assertSame( '2025-11-25', $data['result']['protocolVersion'] );
	}

	public function test_initialize_in_a_batch_governs_the_batch_over_the_header(): void {
		$data = $this->post(
			array(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array( 'protocolVersion' => '2024-11-05' ),
				),
				array(
					'jsonrpc' => '2.0',
					'id'      => 2,
					'method'  => 'tools/list',
				),
			),
			'2025-11-25'
		);

		$this->assertSame( '2024-11-05', $data[0]['result']['protocolVersion'] );
		foreach ( $data[1]['result']['tools'] as $tool ) {
			$this->assertArrayNotHasKey( 'outputSchema', $tool, 'The batch answered under the version its initialize negotiated.' );
			$this->assertArrayNotHasKey( 'title', $tool );
		}
	}

	// ---------------------------------------------------------------
	// tools/list per version
	// ---------------------------------------------------------------

	public function test_tools_list_under_old_versions_has_no_2025_06_18_tool_fields(): void {
		foreach ( self::OLD_VERSIONS as $version ) {
			$tools = $this->tools_list( $version );
			$this->assertNotEmpty( $tools );
			foreach ( $tools as $name => $tool ) {
				$this->assertArrayNotHasKey( 'title', $tool, "$version: $name has no top-level title." );
				$this->assertArrayNotHasKey( 'outputSchema', $tool, "$version: $name has no outputSchema." );
				// #934's fallback for exactly these clients stays.
				$this->assertNotEmpty( $tool['annotations']['title'], "$version: $name keeps annotations.title." );
				$this->assertArrayHasKey( 'inputSchema', $tool );
			}
		}
	}

	public function test_no_header_means_2025_03_26_and_the_old_shape(): void {
		foreach ( array( null, '', '  ' ) as $header ) {
			$tools = $this->tools_list( $header );
			$this->assertNotEmpty( $tools );
			foreach ( $tools as $name => $tool ) {
				$this->assertArrayNotHasKey( 'title', $tool, "no header: $name has no top-level title." );
				$this->assertArrayNotHasKey( 'outputSchema', $tool, "no header: $name has no outputSchema." );
				$this->assertNotEmpty( $tool['annotations']['title'] );
			}
		}

		$GLOBALS['mcpwp_test_rest_response_override'] = ( new Mcpwp_Core() )->get_site_info();
		$result = $this->tools_call( 'wp_site_info', array(), null )['result'];
		$this->assertArrayNotHasKey( 'structuredContent', $result );
	}

	public function test_an_unsupported_or_malformed_header_is_a_400_naming_the_supported_versions(): void {
		foreach ( array( '2099-01-01', '2025-6-18', 'latest', '2025-11-25,2025-11-25', "<script>" ) as $header ) {
			$response = $this->post_raw( array( 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list' ), $header );
			$data     = $response->get_data();

			$this->assertSame( 400, $response->get_status(), "'$header' is refused." );
			$this->assertSame( 7, $data['id'] );
			$this->assertSame( -32600, $data['error']['code'] );
			$this->assertSame( array( '2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25' ), $data['error']['data']['supported'] );
			$this->assertStringContainsString( '2025-11-25', $data['error']['message'] );
			$this->assertStringNotContainsString( $header, wp_json_encode( $data ), 'The rejected value is not echoed back.' );
			$this->assertArrayNotHasKey( 'result', $data );
		}

		// Nothing ran: a refused tools/call never dispatches.
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$response = $this->post_raw(
			array( 'jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_site_info', 'arguments' => array() ) ),
			'1999-01-01'
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'] );
	}

	public function test_initialize_is_never_refused_for_its_header(): void {
		$response = $this->post_raw(
			array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-06-18' ) ),
			'garbage'
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2025-06-18', $response->get_data()['result']['protocolVersion'] );
	}

	public function test_cors_allows_the_protocol_version_and_session_headers(): void {
		$headers = $this->post_raw( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ), '2025-11-25' )->get_headers();
		$allowed = array_map( 'trim', explode( ',', strtolower( (string) $headers['Access-Control-Allow-Headers'] ) ) );
		$this->assertContains( 'mcp-protocol-version', $allowed );
		$this->assertContains( 'mcp-session-id', $allowed );
	}

	public function test_core_preflight_allow_list_gains_the_mcp_headers_on_the_mcp_route_only(): void {
		$controller = new Mcpwp_REST_MCP();
		$core       = array( 'Authorization', 'Content-Type' );

		$mcp = $controller->allow_mcp_cors_headers( $core, new WP_REST_Request( 'OPTIONS', '/mcpwp/v1/mcp' ) );
		foreach ( array( 'X-API-Key', 'Mcp-Session-Id', 'MCP-Protocol-Version' ) as $header ) {
			$this->assertContains( $header, $mcp );
		}
		$this->assertSame( $core, $controller->allow_mcp_cors_headers( $core, new WP_REST_Request( 'OPTIONS', '/wp/v2/posts' ) ), 'Other routes untouched.' );
		$this->assertSame( $core, $controller->allow_mcp_cors_headers( $core ), 'No request (pre-6.3 core): untouched.' );
	}

	public function test_error_envelope_wrapping_is_idempotent(): void {
		$raw  = array( 'type' => 'object', 'properties' => array( 'a' => array( 'type' => 'string' ) ), 'required' => array( 'a' ) );
		$once = Mcpwp_MCP_Tool_Registry::with_error_envelope( $raw );
		$this->assertSame( $once, Mcpwp_MCP_Tool_Registry::with_error_envelope( $once ) );
		$this->assertSame( array( 'required' => array( 'a' ), 'not' => array( 'required' => array( 'error' ) ) ), $once['anyOf'][0] );
	}

	public function test_tools_list_under_structured_versions_has_title_and_output_schema(): void {
		foreach ( self::STRUCTURED_VERSIONS as $version ) {
			$label = $version;
			$tools = $this->tools_list( $version );
			foreach ( $tools as $name => $tool ) {
				$this->assertNotEmpty( $tool['title'], "$label: $name has a top-level title." );
				$this->assertNotEmpty( $tool['annotations']['title'], "$label: $name keeps annotations.title." );
			}
			foreach ( $this->schema_tool_names() as $name ) {
				$this->assertArrayHasKey( $name, $tools );
				$this->assertArrayHasKey( 'outputSchema', $tools[ $name ], "$label: $name declares an outputSchema." );
				$this->assertSame( 'object', $tools[ $name ]['outputSchema']['type'], 'An outputSchema root is an object schema.' );
			}
		}
	}

	// ---------------------------------------------------------------
	// tools/call per version
	// ---------------------------------------------------------------

	public function test_tools_call_under_structured_versions_carries_structured_content_and_compact_text(): void {
		$GLOBALS['mcpwp_test_rest_response_override'] = ( new Mcpwp_Core() )->get_site_info();

		foreach ( self::STRUCTURED_VERSIONS as $version ) {
			$data   = $this->tools_call( 'wp_site_info', array(), $version );
			$result = $data['result'];

			$this->assertFalse( $result['isError'] );
			$this->assertArrayHasKey( 'structuredContent', $result );
			$this->assertSame( 'text', $result['content'][0]['type'], 'The text block stays for clients that only read content.' );
			$this->assertSame( $result['structuredContent'], json_decode( $result['content'][0]['text'], true ), 'Text and structuredContent carry the same data.' );
			$this->assertStringNotContainsString( "\n", $result['content'][0]['text'], 'Duplicate text is compact JSON.' );
			$this->assertArrayHasKey( 'plugin_version', $result['_meta'], '_meta is still attached.' );
		}
	}

	public function test_tools_call_under_old_versions_has_no_structured_content_and_unchanged_text(): void {
		$GLOBALS['mcpwp_test_rest_response_override'] = ( new Mcpwp_Core() )->get_site_info();

		foreach ( self::OLD_VERSIONS as $version ) {
			$result = $this->tools_call( 'wp_site_info', array(), $version )['result'];

			$this->assertFalse( $result['isError'] );
			$this->assertArrayNotHasKey( 'structuredContent', $result, "$version defines no structuredContent." );
			$this->assertStringContainsString( "\n", $result['content'][0]['text'], "$version keeps the pretty-printed text it always had." );
			$this->assertSame( 'MCPWP', json_decode( $result['content'][0]['text'], true )['plugin']['name'] );
		}
	}

	public function test_a_tool_without_an_output_schema_never_gets_structured_content(): void {
		$GLOBALS['mcpwp_test_rest_response_override'] = array( array( 'id' => 1, 'name' => 'Uncategorized' ) );

		$result = $this->tools_call( 'wp_list_categories', array(), '2025-11-25' )['result'];

		$this->assertFalse( $result['isError'] );
		$this->assertArrayNotHasKey( 'structuredContent', $result );
		$this->assertStringContainsString( "\n", $result['content'][0]['text'], 'Text formatting unchanged for schema-less tools.' );
	}

	public function test_a_result_that_fails_its_schema_gets_the_mismatch_envelope_text_intact_and_is_logged_once(): void {
		$schema = $this->tools_list( '2025-11-25' )['wp_site_info']['outputSchema'];

		$log      = tempnam( sys_get_temp_dir(), 'mcpwp906' );
		$previous = ini_set( 'error_log', $log );

		try {
			foreach ( array(
				'empty result'          => array(),
				'wrong leaf type'       => array_merge( ( new Mcpwp_Core() )->get_site_info(), array( 'wp_version' => false ) ),
				'success with an error' => array_merge( ( new Mcpwp_Core() )->get_site_info(), array( 'error' => 'boom' ) ),
				'top-level list'        => array( 1, 2, 3 ),
			) as $label => $bad ) {
				$GLOBALS['mcpwp_test_rest_response_override'] = $bad;
				file_put_contents( $log, '' );

				$response = $this->post_raw(
					array(
						array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_site_info', 'arguments' => array() ) ),
						array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_site_info', 'arguments' => array() ) ),
					),
					'2025-11-25'
				);
				$data = json_decode( wp_json_encode( $response->get_data() ), true );
				$wire = json_decode( wp_json_encode( $response->get_data() ) );

				$this->assertSame( 200, $response->get_status(), "$label: no exception, a normal answer." );
				foreach ( $data as $i => $message ) {
					$this->assertFalse( $message['result']['isError'], "$label: isError unchanged." );
					// The failing copy is withheld; what replaces it is the one
					// shape the schema always admits — omitting it outright would
					// make the reference SDK client throw ("did not return
					// structured content").
					$this->assertSame( 'output_schema_mismatch', $message['result']['structuredContent']['error']['code'], "$label: failing structured copy withheld." );
					$this->assertSame( array(), $this->validate( $wire[ $i ]->result->structuredContent, $schema ), "$label: what is sent validates." );
					$this->assertSame( $bad, json_decode( $message['result']['content'][0]['text'], true ), "$label: text block intact." );
				}

				$logged = (string) file_get_contents( $log );
				$this->assertSame( 1, substr_count( $logged, 'wp_site_info result does not match its outputSchema' ), "$label: logged once per request, not per message." );
			}
		} finally {
			ini_set( 'error_log', (string) $previous );
			@unlink( $log ); // phpcs:ignore
		}
	}

	public function test_the_log_line_names_the_failing_path(): void {
		$log      = tempnam( sys_get_temp_dir(), 'mcpwp906' );
		$previous = ini_set( 'error_log', $log );
		try {
			$GLOBALS['mcpwp_test_rest_response_override'] = array_merge( ( new Mcpwp_Core() )->get_site_info(), array( 'theme' => array( 'name' => false, 'version' => '1' ) ) );
			$this->tools_call( 'wp_site_info', array(), '2025-11-25' );
			$this->assertStringContainsString( '$.theme.name', (string) file_get_contents( $log ) );
		} finally {
			ini_set( 'error_log', (string) $previous );
			@unlink( $log ); // phpcs:ignore
		}
	}

	public function test_a_third_party_raw_output_schema_gets_the_error_envelope_and_a_list_result_is_wrapped_as_items(): void {
		$integration = new class() extends Mcpwp_Integration {
			public function get_info() {
				return array( 'id' => 'zz', 'name' => 'ZZ', 'version' => '1.0' );
			}
			public function get_tool_map() {
				return array( 'zz_list_things' => array( 'route' => '/mcpwp/v1/things', 'method' => 'GET' ) );
			}
			public function get_tools() {
				return array(
					array(
						'name'         => 'zz_list_things',
						'description'  => 'lists things',
						'inputSchema'  => array( 'type' => 'object', 'properties' => new stdClass() ),
						'outputSchema' => array(
							'type'       => 'object',
							'properties' => array( 'items' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ) ),
							'required'   => array( 'items' ),
						),
					),
				);
			}
		};
		add_filter( 'mcpwp_integrations', function () use ( $integration ) { return array( $integration ); } );
		Mcpwp_Integration::clear_cache();

		$schema = $this->tools_list( '2025-11-25' )['zz_list_things']['outputSchema'];
		$this->assertArrayHasKey( 'error', $schema['properties'], 'Wrapped at tools/list time.' );
		$this->assertSame( array( 'error' ), $schema['anyOf'][1]['required'] );

		$GLOBALS['mcpwp_test_rest_response_override'] = array( 1, 2, 3 );
		$result = $this->tools_call( 'zz_list_things', array(), '2025-11-25' )['result'];
		$this->assertSame( array( 'items' => array( 1, 2, 3 ) ), $result['structuredContent'], 'A top-level list is sent as {"items": [...]}.' );
	}

	// ---------------------------------------------------------------
	// isError under every version (#936)
	// ---------------------------------------------------------------

	public function test_iserror_keeps_structured_error_under_every_version_and_validates_against_the_schema(): void {
		$schemas = array();
		foreach ( $this->tools_list( '2025-11-25' ) as $name => $tool ) {
			if ( isset( $tool['outputSchema'] ) ) {
				$schemas[ $name ] = $tool['outputSchema'];
			}
		}

		foreach ( array_merge( self::OLD_VERSIONS, self::STRUCTURED_VERSIONS, array( null ) ) as $version ) {
			$label = null === $version ? 'no header' : $version;
			Mcpwp_REST_MCP::reset_verdict_memo();

			// wp_get_page_by_slug declares an outputSchema; "slug" is required and omitted.
			$wire   = $this->tools_call( 'wp_get_page_by_slug', array(), $version, false );
			$result = $this->tools_call( 'wp_get_page_by_slug', array(), $version )['result'];

			$this->assertTrue( $result['isError'], "$label: isError." );
			$this->assertSame( 'invalid_arguments', $result['structuredContent']['error']['code'], "$label: #936 envelope kept." );
			$this->assertStringContainsString( 'Fix:', $result['content'][0]['text'] );

			// The reference SDK client validates structuredContent against the
			// outputSchema even on isError — the envelope must pass it.
			$errors = $this->validate( $wire->result->structuredContent, $schemas['wp_get_page_by_slug'] );
			$this->assertSame( array(), $errors, "$label: the error envelope validates against the declared outputSchema." );
		}
	}

	public function test_a_wp_error_wrapped_in_a_200_is_reported_as_iserror_not_success(): void {
		$GLOBALS['mcpwp_test_rest_response_override'] = new WP_Error( 'invalid_post_type', 'Invalid post type.', array( 'status' => 400 ) );

		foreach ( array( '2024-11-05', '2025-11-25' ) as $version ) {
			$result = $this->tools_call( 'wp_list_posts', array(), $version )['result'];

			$this->assertTrue( $result['isError'], "$version: a WP_Error body is a failure." );
			$this->assertSame( 'invalid_post_type', $result['structuredContent']['error']['code'] );
			$this->assertSame( 400, $result['structuredContent']['error']['status'] );
		}
	}

	// ---------------------------------------------------------------
	// outputSchema validity against real results
	// ---------------------------------------------------------------

	public function test_every_declared_output_schema_has_a_validated_real_result(): void {
		$tools    = $this->tools_list( '2025-11-25' );
		$declared = array();
		foreach ( $tools as $name => $tool ) {
			if ( isset( $tool['outputSchema'] ) ) {
				$declared[] = $name;
			}
		}
		sort( $declared );
		$this->assertSame( $this->schema_tool_names(), $declared, 'Every tool that declares an outputSchema is validated against a real result here.' );

		foreach ( $this->real_results() as $case => $spec ) {
			$name                         = explode( ' ', $case )[0];
			list( $arguments, $producer ) = $spec;
			$real = $producer();
			$this->assertIsArray( $real, "$name produced a real result." );

			$GLOBALS['mcpwp_test_rest_response_override'] = $real;
			$wire = $this->tools_call( $name, $arguments, '2025-11-25', false );

			$this->assertFalse( $wire->result->isError, "$name succeeded." );
			$this->assertTrue( isset( $wire->result->structuredContent ), "$name carries structuredContent." );

			$schema = json_decode( wp_json_encode( $tools[ $name ]['outputSchema'] ) );
			$this->assertSame( array(), $this->validate( $wire->result->structuredContent, $schema ), "$name structuredContent validates against its outputSchema." );
		}
	}

	public function test_the_validator_rejects_a_result_missing_a_required_key(): void {
		// Guard against a validator that accepts everything.
		$schema = json_decode( wp_json_encode( $this->tools_list( '2025-11-25' )['wp_list_pages']['outputSchema'] ) );

		$this->assertNotSame( array(), $this->validate( json_decode( '{"pages":[]}' ), $schema ), 'Missing total/pages_count/page/per_page.' );
		$this->assertNotSame( array(), $this->validate( json_decode( '{"pages":{},"total":1,"pages_count":1,"page":1,"per_page":10}' ), $schema ), 'pages must be an array.' );
		$this->assertNotSame( array(), $this->validate( json_decode( '{"pages":[{"id":"7"}],"total":1,"pages_count":1,"page":1,"per_page":10}' ), $schema ), 'id must be an integer.' );
		$this->assertNotSame( array(), $this->validate( json_decode( '{"error":{"message":"x"}}' ), $schema ), 'An error envelope needs a code.' );
		$this->assertSame( array(), $this->validate( json_decode( '{"pages":[],"total":0,"pages_count":0,"page":1,"per_page":10}' ), $schema ) );
	}

	public function test_a_success_shaped_result_carrying_an_error_key_fails_validation(): void {
		$schema  = $this->tools_list( '2025-11-25' )['wp_list_pages']['outputSchema'];
		$success = '{"pages":[],"total":0,"pages_count":0,"page":1,"per_page":10}';
		$mixed   = '{"pages":[],"total":0,"pages_count":0,"page":1,"per_page":10,"error":"boom"}';
		$error   = '{"error":{"code":"not_found","message":"nope"}}';

		foreach ( array( 'test validator' => array( $this, 'validate' ), 'server validator' => array( 'Mcpwp_MCP_Output_Schema', 'validate' ) ) as $label => $validator ) {
			$this->assertSame( array(), call_user_func( $validator, json_decode( $success ), $schema ), "$label: success passes." );
			$this->assertSame( array(), call_user_func( $validator, json_decode( $error ), $schema ), "$label: error envelope passes." );
			$this->assertNotSame( array(), call_user_func( $validator, json_decode( $mixed ), $schema ), "$label: success shape plus an error key fails." );
		}
	}

	public function test_server_validator_is_strict_where_core_would_coerce(): void {
		$this->assertNotSame( array(), Mcpwp_MCP_Output_Schema::validate( '7', array( 'type' => 'integer' ) ), 'A numeric string is not an integer.' );
		$this->assertNotSame( array(), Mcpwp_MCP_Output_Schema::validate( array(), array( 'type' => 'object' ) ), 'An array is not an object.' );
		$this->assertSame( array(), Mcpwp_MCP_Output_Schema::validate( 3.0, array( 'type' => 'integer' ) ), 'An integral float is an integer.' );
		$this->assertSame( array(), Mcpwp_MCP_Output_Schema::validate( null, array( 'type' => array( 'string', 'null' ) ) ) );
		$this->assertNotSame( array(), Mcpwp_MCP_Output_Schema::validate( 'c', array( 'enum' => array( 'a', 'b' ) ) ) );
		$this->assertNotSame( array(), Mcpwp_MCP_Output_Schema::validate( json_decode( '{"a":1}' ), array( 'type' => 'object', 'additionalProperties' => false ) ) );
	}

	// ---------------------------------------------------------------
	// Idempotent replay across versions
	// ---------------------------------------------------------------

	public function test_a_replay_is_conformed_to_the_version_of_the_request_replaying_it(): void {
		$controller = new Mcpwp_REST_MCP();
		$conform    = new ReflectionMethod( $controller, 'conform_tool_call_result' );
		$conform->setAccessible( true );
		$version = new ReflectionProperty( $controller, 'negotiated_protocol_version' );
		$version->setAccessible( true );

		$site       = ( new Mcpwp_Core() )->get_site_info();
		$stored_old = array(
			'jsonrpc' => '2.0',
			'id'      => 9,
			'result'  => array(
				'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $site, JSON_PRETTY_PRINT ) ) ),
				'isError' => false,
			),
		);

		// Stored under 2024-11-05, replayed to a 2025-11-25 client: the
		// schema-declaring tool must still carry structuredContent.
		$version->setValue( $controller, '2025-11-25' );
		$out = $conform->invoke( $controller, $stored_old, 'wp_site_info' );
		$this->assertSame( $site, json_decode( wp_json_encode( $out['result']['structuredContent'] ), true ) );

		// Stored under 2025-11-25, replayed to a 2024-11-05 client: stripped.
		$stored_new                                = $out;
		$version->setValue( $controller, '2024-11-05' );
		$out = $conform->invoke( $controller, $stored_new, 'wp_site_info' );
		$this->assertArrayNotHasKey( 'structuredContent', $out['result'] );

		// isError is never touched, under any version.
		$error = array(
			'jsonrpc' => '2.0',
			'id'      => 9,
			'result'  => array(
				'content'           => array( array( 'type' => 'text', 'text' => 'nope' ) ),
				'isError'           => true,
				'structuredContent' => array( 'error' => array( 'code' => 'x', 'message' => 'nope' ) ),
			),
		);
		foreach ( array( '2024-11-05', '2025-11-25' ) as $v ) {
			$version->setValue( $controller, $v );
			$this->assertSame( wp_json_encode( $error ), wp_json_encode( $conform->invoke( $controller, $error, 'wp_site_info' ) ), "$v: isError envelope kept as-is." );
		}
	}

	// ---------------------------------------------------------------
	// Minimal JSON Schema validator — the subset the declared schemas use
	// (type, properties, required, items, additionalProperties, anyOf, not,
	// enum), deliberately separate from Mcpwp_MCP_Output_Schema so the
	// production validator is not checked against itself,
	// applied to json_decode()d wire data so objects and arrays, integers
	// and floats are told apart exactly as a client's validator does.
	// ---------------------------------------------------------------

	private function validate( $value, $schema, string $path = '$' ): array {
		$schema = is_array( $schema ) ? json_decode( wp_json_encode( $schema ) ) : $schema;
		$errors = array();

		if ( isset( $schema->type ) ) {
			$types = is_array( $schema->type ) ? $schema->type : array( $schema->type );
			$ok    = false;
			foreach ( $types as $type ) {
				if ( $this->is_type( $value, $type ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				return array( "$path: expected " . implode( '|', $types ) . ', got ' . gettype( $value ) );
			}
		}

		if ( $value instanceof stdClass ) {
			foreach ( isset( $schema->required ) ? $schema->required : array() as $key ) {
				if ( ! property_exists( $value, $key ) ) {
					$errors[] = "$path: missing required '$key'";
				}
			}
			$props = isset( $schema->properties ) ? get_object_vars( $schema->properties ) : array();
			foreach ( get_object_vars( $value ) as $key => $child ) {
				if ( isset( $props[ $key ] ) ) {
					$errors = array_merge( $errors, $this->validate( $child, $props[ $key ], "$path.$key" ) );
				} elseif ( isset( $schema->additionalProperties ) && false === $schema->additionalProperties ) {
					$errors[] = "$path: unexpected '$key'";
				}
			}
		}

		if ( is_array( $value ) && isset( $schema->items ) ) {
			foreach ( $value as $i => $item ) {
				$errors = array_merge( $errors, $this->validate( $item, $schema->items, "$path[$i]" ) );
			}
		}

		if ( isset( $schema->enum ) && ! in_array( $value, $schema->enum, false ) ) {
			$errors[] = "$path: not in enum";
		}

		if ( isset( $schema->not ) && array() === $this->validate( $value, $schema->not, $path ) ) {
			$errors[] = "$path: matches not";
		}

		if ( isset( $schema->anyOf ) ) {
			$any = false;
			foreach ( $schema->anyOf as $branch ) {
				if ( array() === $this->validate( $value, $branch, $path ) ) {
					$any = true;
					break;
				}
			}
			if ( ! $any ) {
				$errors[] = "$path: matches no anyOf branch";
			}
		}

		return $errors;
	}

	private function is_type( $value, string $type ): bool {
		switch ( $type ) {
			case 'object':
				return $value instanceof stdClass;
			case 'array':
				return is_array( $value );
			case 'string':
				return is_string( $value );
			case 'integer':
				return is_int( $value ) || ( is_float( $value ) && floor( $value ) === $value );
			case 'number':
				return is_int( $value ) || is_float( $value );
			case 'boolean':
				return is_bool( $value );
			case 'null':
				return null === $value;
		}
		return false;
	}
}
