<?php
/**
 * MCP protocol version negotiation and the version-gated tool shape (#906).
 *
 * - initialize echoes a supported requested version, otherwise answers the
 *   latest supported one (missing, unknown, future, or not a string).
 * - Every later request is governed by its MCP-Protocol-Version header (an
 *   initialize inside the same batch wins); no header means latest.
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
	}

	/** @var string|null */
	private $previous_wp_version;

	protected function tearDown(): void {
		$GLOBALS['wp_version'] = $this->previous_wp_version;
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
		$headers = null === $version ? array() : array( 'MCP-Protocol-Version' => $version );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), $headers, $body );
		$data    = ( new Mcpwp_REST_MCP() )->handle_mcp( $request )->get_data();
		return json_decode( wp_json_encode( $data ), $assoc );
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

	public function test_tools_list_under_structured_versions_and_no_header_has_title_and_output_schema(): void {
		foreach ( array_merge( self::STRUCTURED_VERSIONS, array( null, '2099-01-01' ) ) as $version ) {
			$label = null === $version ? 'no header' : $version;
			$tools = $this->tools_list( $version );
			foreach ( $tools as $name => $tool ) {
				$this->assertNotEmpty( $tool['title'], "$label: $name has a top-level title." );
				$this->assertNotEmpty( $tool['annotations']['title'], "$label: $name keeps annotations.title." );
			}
			foreach ( array_keys( $this->real_results() ) as $name ) {
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

		foreach ( array_merge( self::STRUCTURED_VERSIONS, array( null ) ) as $version ) {
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

	public function test_empty_result_data_is_sent_as_a_json_object(): void {
		$GLOBALS['mcpwp_test_rest_response_override'] = array();

		$data = $this->tools_call( 'wp_site_info', array(), '2025-11-25', false );

		$this->assertInstanceOf( stdClass::class, $data->result->structuredContent, 'structuredContent is an object on the wire, never [].' );
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
		$covered = array_keys( $this->real_results() );
		sort( $covered );

		$this->assertSame( $covered, $declared, 'Every tool that declares an outputSchema is validated against a real result here.' );

		foreach ( $this->real_results() as $name => $spec ) {
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

	// ---------------------------------------------------------------
	// Idempotent replay across versions
	// ---------------------------------------------------------------

	public function test_a_replay_is_conformed_to_the_version_of_the_request_replaying_it(): void {
		$controller = new Mcpwp_REST_MCP();
		$conform    = new ReflectionMethod( $controller, 'conform_tool_call_result' );
		$conform->setAccessible( true );
		$version = new ReflectionProperty( $controller, 'negotiated_protocol_version' );
		$version->setAccessible( true );

		$stored_old = array(
			'jsonrpc' => '2.0',
			'id'      => 9,
			'result'  => array(
				'content' => array( array( 'type' => 'text', 'text' => "{\n    \"name\": \"Site\"\n}" ) ),
				'isError' => false,
			),
		);

		// Stored under 2024-11-05, replayed to a 2025-11-25 client: the
		// schema-declaring tool must still carry structuredContent.
		$version->setValue( $controller, '2025-11-25' );
		$out = $conform->invoke( $controller, $stored_old, 'wp_site_info' );
		$this->assertSame( array( 'name' => 'Site' ), $out['result']['structuredContent'] );

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
			$this->assertSame( $error, $conform->invoke( $controller, $error, 'wp_site_info' ) );
		}
	}

	// ---------------------------------------------------------------
	// Minimal JSON Schema validator — the subset the declared schemas use
	// (type, properties, required, items, additionalProperties, anyOf),
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
