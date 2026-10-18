<?php
/**
 * Token budgets for what a new MCP connection pays before doing anything (#1064).
 *
 * Builds the real `tools/list` payload through Mcpwp_REST_MCP::handle_mcp()
 * — Free and Pro, `core` and `full`, negotiated at protocol 2025-11-25 —
 * and the default `wp_introspect` result, and holds each to a hard budget.
 * Tokens are estimated as bytes / 4 of the compact JSON a client receives
 * (wp_json_encode() with WordPress's default flags, as the REST server
 * sends it).
 *
 * Every site plugin a tool can require (Elementor + Pro, WooCommerce, an SEO
 * plugin, a forms plugin, Gutenberg) is reported active, so each budget is
 * measured against the largest catalog the plugin can advertise — a real site
 * can only be lighter.
 *
 * A failure prints every measured number, so a regression names itself.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-figma.php';
require_once dirname( __DIR__ ) . '/includes/mcp/class-mcpwp-mcp-ai-integration.php';
require_once dirname( __DIR__ ) . '/includes/mcp/class-mcpwp-mcp-figma-integration.php';

final class ToolListBudgetTest extends TestCase {

	/** `core` profile tools/list, Free or Pro. */
	const BUDGET_CORE = 5000;

	/**
	 * Free `full` profile tools/list.
	 *
	 * #1064 set 25,000. The central diet plus hand-tuning reaches 33,034 on
	 * this worst-case catalog (194 tools; 44,185 before), and the floor
	 * without removing parameters is ~25,300 even with EVERY property
	 * description and every tool description past 150 characters deleted.
	 * The rest needs the tool consolidation in #1047. Until then the
	 * budget is the measured number plus 5%, so it can only go down.
	 */
	const BUDGET_FREE_FULL = 34700;

	/**
	 * Pro `full` profile tools/list.
	 *
	 * #1064 set 35,000 "after the #1047 consolidation". Without it the
	 * diet reaches 52,641 on this worst-case catalog (309 tools; 68,324
	 * before). Measured plus 5% until #1047 lands.
	 */
	const BUDGET_PRO_FULL = 55300;

	/** Any single tool definition, as tools/list emits it. */
	const BUDGET_SINGLE_TOOL = 450;

	/** Default (summary) wp_introspect / GET /introspect result. */
	const BUDGET_INTROSPECT = 2000;

	const PROTOCOL = '2025-11-25';

	/** @var array<string,string> Every measurement, printed on failure. */
	private static $report = array();

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
		Mcpwp_Integration::clear_cache();
		add_filter(
			'mcpwp_integrations',
			static function ( $integrations ) {
				$integrations[] = new Mcpwp_MCP_AI_Integration();
				$integrations[] = new Mcpwp_MCP_Figma_Integration();
				return $integrations;
			}
		);
		$this->reset_license();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_fs']      = null;
		$GLOBALS['mcpwp_test_filters'] = array();
		$GLOBALS['mcpwp_test_options'] = array();
		Mcpwp_Integration::clear_cache();
		$this->reset_license();
	}

	private function reset_license(): void {
		$ref = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	private function set_tier( bool $pro ): void {
		$GLOBALS['mcpwp_test_fs'] = $pro ? new Mcpwp_Test_Fs_Stub( true, true, false, 'pro' ) : null;
		$this->reset_license();
	}

	/**
	 * A controller that sees every optional site plugin as active.
	 */
	private function controller(): Mcpwp_REST_MCP {
		$controller = new Mcpwp_REST_MCP();
		$ref        = new ReflectionProperty( Mcpwp_REST_MCP::class, 'active_capabilities' );
		$ref->setAccessible( true );
		$ref->setValue(
			$controller,
			array(
				'gutenberg'             => true,
				'elementor'             => true,
				'elementor_pro'         => true,
				'elementor_layout_mode' => 'container',
				'woocommerce'           => true,
				'yoast'                 => true,
				'seo'                   => true,
				'forms'                 => true,
				'cf7'                   => true,
				'learnpress'            => true,
				'wpml'                  => true,
				'polylang'              => true,
			)
		);
		return $controller;
	}

	/**
	 * The `tools` array of a real tools/list, as a client decodes it.
	 *
	 * @param string|null $profile ?profile= query arg, or null for the site default.
	 * @return array[]
	 */
	private function tools_list( ?string $profile, bool $assoc = true ): array {
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'MCP-Protocol-Version' => self::PROTOCOL ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
			)
		);
		if ( null !== $profile ) {
			$request->set_query_params( array( 'profile' => $profile ) );
		}
		$data = $this->controller()->handle_mcp( $request )->get_data();
		$this->assertArrayHasKey( 'result', $data, 'tools/list must answer.' );
		return json_decode( wp_json_encode( $data['result']['tools'] ), $assoc );
	}

	private static function tokens( $value ): int {
		return (int) ceil( strlen( (string) wp_json_encode( $value ) ) / 4 );
	}

	private function measure( string $label, array $tools ): int {
		$tokens                 = self::tokens( $tools );
		self::$report[ $label ] = sprintf( '%d tools, %d tokens', count( $tools ), $tokens );
		return $tokens;
	}

	private function report(): string {
		$lines = array( 'Measured (tokens = bytes/4 of compact JSON):' );
		foreach ( self::$report as $label => $value ) {
			$lines[] = sprintf( '  %-28s %s', $label, $value );
		}
		return implode( "\n", $lines );
	}

	/**
	 * @return array<int,array{0:bool,1:string,2:int}>
	 */
	public function profile_cases(): array {
		return array(
			'free core' => array( false, 'core', self::BUDGET_CORE ),
			'pro core'  => array( true, 'core', self::BUDGET_CORE ),
			'free full' => array( false, 'full', self::BUDGET_FREE_FULL ),
			'pro full'  => array( true, 'full', self::BUDGET_PRO_FULL ),
		);
	}

	/**
	 * @dataProvider profile_cases
	 */
	public function test_tools_list_fits_its_budget( bool $pro, string $profile, int $budget ): void {
		$this->set_tier( $pro );
		$label  = ( $pro ? 'pro ' : 'free ' ) . $profile;
		$tools  = $this->tools_list( $profile );
		$tokens = $this->measure( $label, $tools );

		$this->assertNotEmpty( $tools );
		$this->assertLessThanOrEqual( $budget, $tokens, "tools/list ($label) is over its $budget-token budget.\n" . $this->report() );
	}

	/**
	 * @dataProvider profile_cases
	 */
	public function test_every_tool_fits_the_single_tool_budget( bool $pro, string $profile ): void {
		$this->set_tier( $pro );
		$over = array();
		foreach ( $this->tools_list( $profile ) as $tool ) {
			$tokens = self::tokens( $tool );
			if ( $tokens > self::BUDGET_SINGLE_TOOL ) {
				$over[ $tool['name'] ] = $tokens;
			}
		}
		arsort( $over );
		$this->assertSame( array(), $over, 'Tools over the ' . self::BUDGET_SINGLE_TOOL . "-token single-tool budget (name => tokens).\n" . print_r( $over, true ) );
	}

	/**
	 * A connection whose site never saved a profile gets `core` (#1064).
	 */
	public function test_a_site_that_never_chose_gets_the_core_profile(): void {
		$this->set_tier( true );
		$names = array_column( $this->tools_list( null ), 'name' );
		$core  = array_column( $this->tools_list( 'core' ), 'name' );
		$this->assertSame( $core, $names );
		$this->assertContains( 'mcpwp_find_tools', $names );
		$this->assertContains( 'mcpwp_call', $names );
		$this->assertNotContains( 'wp_delete_all_drafts', $names );
	}

	/**
	 * The diet's rules hold for every tool in the largest catalog: text is
	 * capped, examples are gone, annotations.title is not duplicated — and
	 * nothing a tool ACCEPTS changed. Stripping the annotation text
	 * (descriptions, examples, empty/false defaults) from the registry's
	 * own inputSchema and from the emitted one must give the same schema:
	 * same properties, types, required lists, enums, items, bounds.
	 */
	public function test_the_diet_only_removes_annotation_text(): void {
		$this->set_tier( true );
		$emitted = array();
		foreach ( $this->tools_list( 'full' ) as $tool ) {
			$emitted[ $tool['name'] ] = $tool;
		}

		// Decoded with objects preserved (#1064 review N3): `{}` and `[]`
		// must stay distinguishable, or an emitted `[]` schema would pass.
		$emitted_objects = array();
		foreach ( $this->tools_list( 'full', false ) as $tool ) {
			$emitted_objects[ $tool->name ] = $tool;
		}

		$controller = $this->controller();
		$ref        = new ReflectionMethod( Mcpwp_REST_MCP::class, 'get_all_tools' );
		$ref->setAccessible( true );
		$registry = array();
		foreach ( $ref->invoke( $controller ) as $tool ) {
			$registry[ $tool['name'] ] = json_decode( wp_json_encode( $tool ) );
		}

		$this->assertSame( array_keys( $registry ), array_keys( $emitted ), 'tools/list must list the same tools in the same order.' );

		foreach ( $emitted as $name => $tool ) {
			$this->assertLessThanOrEqual( Mcpwp_MCP_Tool_List_Diet::DESCRIPTION_MAX_CHARS, mb_strlen( $tool['description'] ), "$name: description over the cap." );
			$this->assertStringNotContainsString( '"examples"', wp_json_encode( $tool['inputSchema'] ), "$name: tools/list must not carry #912 examples." );
			$this->assertArrayNotHasKey( 'title', $tool['annotations'], "$name: annotations.title duplicates the top-level title under 2025-11-25." );
			foreach ( array( 'readOnlyHint', 'destructiveHint', 'openWorldHint' ) as $hint ) {
				$this->assertIsBool( $tool['annotations'][ $hint ] ?? null, "$name: $hint must always be sent (ChatGPT conformance)." );
			}
			$this->assert_property_descriptions_capped( $name, $tool['inputSchema'] );

			$this->assertSame(
				wp_json_encode( self::structure( $registry[ $name ]->inputSchema ) ),
				wp_json_encode( self::structure( $emitted_objects[ $name ]->inputSchema ) ),
				"$name: tools/list changed what the tool accepts."
			);
			$this->assertSame(
				wp_json_encode( $registry[ $name ]->outputSchema ?? null ),
				wp_json_encode( $emitted_objects[ $name ]->outputSchema ?? null ),
				"$name: outputSchema (#906) must be emitted unchanged."
			);
		}
	}

	private function assert_property_descriptions_capped( string $name, $schema ): void {
		if ( ! is_array( $schema ) ) {
			return;
		}
		foreach ( array( 'properties' ) as $map ) {
			if ( isset( $schema[ $map ] ) && is_array( $schema[ $map ] ) ) {
				foreach ( $schema[ $map ] as $property => $subschema ) {
					if ( is_array( $subschema ) && isset( $subschema['description'] ) ) {
						$this->assertLessThanOrEqual( Mcpwp_MCP_Tool_List_Diet::PROPERTY_DESCRIPTION_MAX_CHARS, mb_strlen( $subschema['description'] ), "$name.$property: property description over the cap." );
					}
					$this->assert_property_descriptions_capped( $name, $subschema );
				}
			}
		}
		if ( isset( $schema['items'] ) ) {
			$this->assert_property_descriptions_capped( $name, $schema['items'] );
		}
	}

	/**
	 * A schema with its annotation text removed.
	 *
	 * @param mixed $schema Schema.
	 * @return mixed
	 */
	private static function structure( $schema ) {
		if ( is_array( $schema ) ) {
			return array_map( array( __CLASS__, 'structure' ), $schema );
		}
		if ( ! is_object( $schema ) ) {
			return $schema;
		}
		$schema = clone $schema;
		unset( $schema->description, $schema->examples );
		if ( property_exists( $schema, 'default' ) && ( '' === $schema->default || false === $schema->default ) ) {
			unset( $schema->default );
		}
		foreach ( get_object_vars( $schema ) as $key => $value ) {
			if ( 'properties' === $key && is_object( $value ) ) {
				$properties = new stdClass();
				foreach ( get_object_vars( $value ) as $property => $subschema ) {
					$properties->$property = self::structure( $subschema );
				}
				$schema->properties = $properties;
			} elseif ( is_array( $value ) || is_object( $value ) ) {
				$schema->$key = self::structure( $value );
			}
		}
		return $schema;
	}

	/**
	 * A client negotiated below 2025-06-18 has no top-level title (#906),
	 * so it keeps annotations.title.
	 */
	public function test_an_old_protocol_client_keeps_annotations_title(): void {
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'MCP-Protocol-Version' => '2025-03-26' ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
			)
		);
		$request->set_query_params( array( 'profile' => 'core' ) );
		$data = $this->controller()->handle_mcp( $request )->get_data();
		foreach ( $data['result']['tools'] as $tool ) {
			$this->assertArrayNotHasKey( 'title', $tool );
			$this->assertNotEmpty( $tool['annotations']['title'] ?? '', "{$tool['name']}: annotations.title is the only display name an old client gets." );
		}
	}

	private function call( string $method, array $params = array(), ?string $profile = null ): array {
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'MCP-Protocol-Version' => self::PROTOCOL ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 7,
				'method'  => $method,
				'params'  => $params,
			)
		);
		if ( null !== $profile ) {
			$request->set_query_params( array( 'profile' => $profile ) );
		}
		return json_decode( wp_json_encode( $this->controller()->handle_mcp( $request )->get_data() ), true );
	}

	/**
	 * What tools/list leaves out is one mcpwp_find_tools call away: the
	 * registry's full description and the #912 examples.
	 */
	public function test_find_tools_returns_the_full_definition_with_examples(): void {
		$this->set_tier( true );
		$data    = $this->call( 'tools/call', array( 'name' => 'mcpwp_find_tools', 'arguments' => array( 'query' => 'edit widget elementor' ) ) );
		$matches = json_decode( $data['result']['content'][0]['text'], true )['matches'];
		$found   = array_column( $matches, null, 'name' );

		$this->assertArrayHasKey( 'wp_edit_widget', $found );
		$full = ( new Mcpwp_MCP_Free_Tools() )->get_tools();
		$full = array_column( $full, null, 'name' )['wp_edit_widget'];
		$this->assertSame( $full['description'], $found['wp_edit_widget']['description'], 'find_tools returns the unshortened description.' );
		$this->assertNotEmpty( $found['wp_edit_widget']['inputSchema']['examples'] ?? null, 'find_tools returns the #912 examples.' );

		$listed = array_column( $this->tools_list( 'core' ), null, 'name' )['wp_edit_widget'];
		$this->assertArrayNotHasKey( 'examples', $listed['inputSchema'] );
	}

	/**
	 * A refused call is where a worked example helps most (#1064): the
	 * invalid-arguments error carries the tool's first #912 example.
	 */
	public function test_invalid_arguments_error_carries_the_example(): void {
		$this->set_tier( true );
		$data  = $this->call( 'tools/call', array( 'name' => 'wp_edit_widget', 'arguments' => array( 'id' => 12 ) ) );
		$error = $data['result']['structuredContent']['error'] ?? array();

		$this->assertTrue( $data['result']['isError'] );
		$this->assertSame( 'invalid_arguments', $error['code'] ?? null );
		$this->assertIsArray( $error['example'] ?? null );
		$this->assertArrayHasKey( 'widget_id', $error['example'] );
		$this->assertStringContainsString( 'Example arguments: {', $data['result']['content'][0]['text'] );
		$this->assertStringContainsString( 'mcpwp_find_tools', $data['result']['content'][0]['text'] );
	}

	/**
	 * initialize tells a `core` client, briefly, that the rest of the
	 * catalog is one search away.
	 */
	public function test_initialize_tells_a_core_client_how_to_reach_every_tool(): void {
		$params = array(
			'protocolVersion' => self::PROTOCOL,
			'capabilities'    => new stdClass(),
			'clientInfo'      => array( 'name' => 'test', 'version' => '1' ),
		);
		$core   = $this->call( 'initialize', $params )['result']['instructions'];
		$this->assertStringContainsString( 'Your tool list is the core set', $core );
		$this->assertStringContainsString( 'mcpwp_find_tools(query)', $core );
		$this->assertStringContainsString( 'mcpwp_call(name, arguments)', $core );

		$full = $this->call( 'initialize', $params, 'full' )['result']['instructions'];
		$this->assertStringNotContainsString( 'Your tool list is the core set', $full );
		$this->assertStringContainsString( 'mcpwp_find_tools(query)', $full );
	}

	public function test_shorten_cuts_at_a_sentence_or_word_boundary(): void {
		$text = str_repeat( 'Word ', 10 ) . 'ends here. ' . str_repeat( 'More words follow in a second sentence ', 10 );
		$out  = Mcpwp_MCP_Tool_List_Diet::shorten( $text, 120 );
		$this->assertLessThanOrEqual( 120, mb_strlen( $out ) );
		$this->assertStringEndsWith( 'ends here.', $out );

		$long = str_repeat( 'abcdefghij ', 20 );
		$out  = Mcpwp_MCP_Tool_List_Diet::shorten( $long, 50 );
		$this->assertLessThanOrEqual( 50, mb_strlen( $out ) );
		$this->assertMatchesRegularExpression( '/^(abcdefghij )*abcdefghij\.\.\.$/', $out, 'A cut without a sentence end stops after a whole word.' );

		$this->assertSame( 'Short.', Mcpwp_MCP_Tool_List_Diet::shorten( 'Short.', 50 ) );

		$abbrev = 'Pass the targets as a list of things, e.g. ids or slugs, and the tool resolves each one before it writes anything.';
		$this->assertNotSame( 'Pass the targets as a list of things, e.g.', Mcpwp_MCP_Tool_List_Diet::shorten( $abbrev, 80 ), '"e.g." is not a sentence end.' );

		$this->assertSame( 'A - B...', Mcpwp_MCP_Tool_List_Diet::shorten( 'A — B…', 50 ), 'Typographic punctuation becomes ASCII (6 bytes on the wire -> 1-3).' );
	}

	/**
	 * The default wp_introspect answer is a summary under 2,000 tokens; the
	 * compact list it replaced is still one detail=full away.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_introspect_default_is_a_summary_within_budget(): void {
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-media.php';
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-design-references.php';
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';
		self::stub_site_info_functions();
		$this->set_tier( true );
		// Worst case: every optional plugin active, and a site brief set.
		set_transient(
			'mcpwp_capabilities_cache',
			array(
				'gutenberg'             => true,
				'elementor'             => true,
				'elementor_pro'         => true,
				'elementor_layout_mode' => 'container',
				'woocommerce'           => true,
				'yoast'                 => true,
				'cf7'                   => true,
			),
			3600
		);
		update_option( 'mcpwp_site_context', str_repeat( 'Brand rule. ', 400 ) );

		$controller = new Mcpwp_REST_Site_Info();
		$summary    = $controller->get_introspect( new WP_REST_Request( 'GET', '/mcpwp/v1/introspect' ) )->get_data();
		$tokens     = self::tokens( $summary );

		$this->assertSame( 'summary', $summary['detail'] ?? null );
		$this->assertTrue( $summary['site_context_configured'] );
		$this->assertArrayNotHasKey( 'site_context', $summary, 'The brief itself is detail=full / wp_get_site_context — it can be any length.' );
		$this->assertGreaterThan( 100, $summary['tools']['count'] );
		$this->assertStringContainsString( 'mcpwp_find_tools', $summary['tools']['find'] );
		$this->assertLessThanOrEqual( self::BUDGET_INTROSPECT, $tokens, "Default wp_introspect is $tokens tokens, over its " . self::BUDGET_INTROSPECT . "-token budget.\n" . wp_json_encode( $summary ) );

		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/introspect' );
		$request->set_param( 'detail', 'full' );
		$full = $controller->get_introspect( $request )->get_data();
		$this->assertSame( 'full', $full['detail'] ?? null );
		$this->assertIsArray( $full['tools'] );
		$this->assertArrayHasKey( 'workflows', $full );
		$this->assertArrayHasKey( 'site_context', $full );
	}

	/**
	 * get_introspect() reads site info through Mcpwp_Core, which calls
	 * functions the shared bootstrap does not stub — declared only inside a
	 * separate process (same approach as AdversarialReview1007Round1Test).
	 */
	private static function stub_site_info_functions(): void {
		if ( ! function_exists( 'wp_timezone_string' ) ) {
			function wp_timezone_string() {
				return 'UTC';
			}
		}
		if ( ! function_exists( 'get_locale' ) ) {
			function get_locale() {
				return 'en_US';
			}
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			function is_plugin_active( $plugin ) {
				return false;
			}
		}
		if ( ! function_exists( 'wp_get_theme' ) ) {
			function wp_get_theme() {
				return new class() {
					public function get( $header ) {
						return 'Stub Theme';
					}
				};
			}
		}
		if ( ! function_exists( 'wp_count_attachments' ) ) {
			function wp_count_attachments() {
				return (object) array( 'image/jpeg' => 0 );
			}
		}
		if ( ! function_exists( 'wp_count_terms' ) ) {
			function wp_count_terms( $taxonomy ) {
				return 0;
			}
		}
	}
}
