<?php
/**
 * #1064 adversarial review, round 1.
 *
 * B1  `core` by default stranded keys without the `meta` category: they got
 *     only the core tools inside their own categories and no way to reach
 *     the rest, while every hint told them to use mcpwp_find_tools. `meta`
 *     is now always granted per key (resolve_key_tool_categories()); a
 *     site that disables `meta` gets the `full` profile; find/call guidance
 *     appears only when both meta tools are visible to the caller.
 * W1  wp_introspect / GET /introspect listed, counted and looked up tools
 *     from the whole catalog, whatever the caller could see.
 * N1-N5 NBSP mapping, sentence ends and grapheme-safe cuts, empty subschemas
 *     as `{}`, profile value normalisation.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-settings.php';

final class ToolWeightBudgetReviewTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$GLOBALS['mcpwp_test_fs']                = new Mcpwp_Test_Fs_Stub( true, true, false, 'pro' );
		$this->reset_license();
		Mcpwp_REST_MCP::reset_verdict_memo();
		Mcpwp_Integration::clear_cache();
		// An SEO plugin is active, so the seo-category core tools exist.
		set_transient(
			'mcpwp_capabilities_cache',
			array(
				'gutenberg'             => true,
				'elementor'             => true,
				'elementor_layout_mode' => 'container',
				'yoast'                 => true,
			),
			3600
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_fs']      = null;
		$GLOBALS['mcpwp_test_options'] = array();
		$this->reset_license();
		Mcpwp_REST_MCP::reset_verdict_memo();
	}

	private function reset_license(): void {
		$ref = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	/**
	 * One JSON-RPC message to /mcp as the given key (null = no key context).
	 *
	 * @return array Decoded response.
	 */
	private function mcp( Mcpwp_REST_MCP $controller, ?string $key, string $method, array $params = array(), ?string $profile = null ): array {
		$headers = array( 'MCP-Protocol-Version' => '2025-11-25' );
		if ( null !== $key ) {
			$headers['X-API-Key'] = $key;
		}
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			$headers,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => $method,
				'params'  => $params,
			)
		);
		if ( null !== $profile ) {
			$request->set_query_params( array( 'profile' => $profile ) );
		}
		if ( null !== $key ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
			$this->assertTrue( true === $controller->verify_api_key( $request ), 'Test premise: the key authenticates.' );
		}
		return json_decode( wp_json_encode( $controller->handle_mcp( $request )->get_data() ), true );
	}

	private function names( array $response ): array {
		return array_column( $response['result']['tools'], 'name' );
	}

	/** A pre-#907 custom key: `seo` only, never granted `meta`. */
	private function seo_key( Mcpwp_REST_MCP $controller ): string {
		return $controller->create_scoped_api_key( 'seo only', array( 'read', 'write' ), 'custom', array( 'seo' ) )['key'];
	}

	// ── B1 ──────────────────────────────────────────────────────────────

	public function test_a_custom_seo_key_without_meta_gets_find_and_call_on_default_core(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $this->seo_key( $controller );

		$names = $this->names( $this->mcp( $controller, $key, 'tools/list' ) );

		$this->assertContains( 'mcpwp_find_tools', $names );
		$this->assertContains( 'mcpwp_call', $names );
		$this->assertContains( 'wp_set_seo', $names, 'Its own core tools stay listed.' );
		$this->assertNotContains( 'wp_create_page', $names, 'Nothing outside its categories.' );
		$this->assertNotContains( 'wp_analyze_seo', $names, 'Still the core profile.' );

		$found = $this->mcp( $controller, $key, 'tools/call', array( 'name' => 'mcpwp_find_tools', 'arguments' => array( 'query' => 'seo', 'limit' => 20 ) ) );
		$this->assertFalse( $found['result']['isError'] );
		$matches = array_column( json_decode( $found['result']['content'][0]['text'], true )['matches'], 'category', 'name' );
		$this->assertArrayHasKey( 'wp_analyze_seo', $matches, 'find_tools reaches the key\'s non-core SEO tools.' );
		$this->assertSame( array( 'seo' ), array_values( array_unique( $matches ) ), 'find_tools shows only the key\'s own categories.' );

		$called = $this->mcp( $controller, $key, 'tools/call', array( 'name' => 'mcpwp_call', 'arguments' => array( 'name' => 'wp_create_page', 'arguments' => array( 'title' => 'x' ) ) ) );
		$this->assertTrue( $called['result']['isError'], 'mcpwp_call still refuses a tool outside the key\'s categories.' );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'] );
	}

	public function test_a_deny_all_key_gets_discovery_and_find_but_finds_nothing(): void {
		$controller = new Mcpwp_REST_MCP();
		// A custom record whose categories are empty (#1041's hand-seeded /
		// stale shape) — the API refuses to create one, so seed it.
		$key  = $controller->create_scoped_api_key( 'nothing', array( 'read' ), 'custom', array( 'seo' ) )['key'];
		$keys = get_option( 'mcpwp_api_keys', array() );
		foreach ( $keys as $i => $record ) {
			if ( is_array( $record ) && ( $record['label'] ?? '' ) === 'nothing' ) {
				$keys[ $i ]['tool_categories'] = array();
			}
		}
		update_option( 'mcpwp_api_keys', $keys );

		$names = $this->names( $this->mcp( $controller, $key, 'tools/list' ) );
		$this->assertContains( 'mcpwp_find_tools', $names );
		$this->assertContains( 'wp_onboard', $names );
		$this->assertNotContains( 'wp_list_pages', $names );
		$this->assertNotContains( 'wp_set_seo', $names, 'Test premise: the seeded record really is deny-all.' );

		$found = $this->mcp( $controller, $key, 'tools/call', array( 'name' => 'mcpwp_find_tools', 'arguments' => array( 'query' => 'page post seo' ) ) );
		$names_found = array_column( json_decode( $found['result']['content'][0]['text'], true )['matches'], 'name' );
		$this->assertSame( array(), array_diff( $names_found, array( 'wp_site_info', 'wp_onboard', 'wp_introspect', 'wp_get_site_context' ) ), 'Only discovery tools can match for a deny-all key.' );
		$this->assertTrue( Mcpwp_REST_MCP::is_deny_all_categories( $controller->resolve_key_tool_categories( array( 'role' => 'custom', 'tool_categories' => array() ) ) ), 'meta rides along; the key is still deny-all.' );
	}

	public function test_meta_disabled_site_wide_means_the_full_profile_and_no_find_guidance(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'meta' ) );
		$controller = new Mcpwp_REST_MCP();

		$names = $this->names( $this->mcp( $controller, null, 'tools/list' ) );
		$this->assertNotContains( 'mcpwp_find_tools', $names );
		$this->assertContains( 'wp_analyze_seo', $names, 'Without find/call the default must be full.' );
		$this->assertContains( 'wp_delete_all_drafts', $this->names( $this->mcp( $controller, null, 'tools/list', array(), 'core' ) ), 'Even ?profile=core cannot strand the caller.' );

		$init = $this->mcp( $controller, null, 'initialize', array( 'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 't', 'version' => '1' ) ) );
		$this->assertStringNotContainsString( 'mcpwp_find_tools', $init['result']['instructions'] );
		$this->assertStringNotContainsString( 'Finding Tools', $init['result']['instructions'] );

		$bad = $this->mcp( $controller, null, 'tools/call', array( 'name' => 'wp_edit_widget', 'arguments' => array( 'id' => 1 ) ) );
		$this->assertSame( 'invalid_arguments', $bad['result']['structuredContent']['error']['code'] );
		$this->assertStringNotContainsString( 'mcpwp_find_tools', wp_json_encode( $bad['result'] ) );
		$this->assertArrayHasKey( 'example', $bad['result']['structuredContent']['error'], 'The example does not depend on meta.' );

		$unknown = $this->mcp( $controller, null, 'tools/call', array( 'name' => 'wp_no_such_tool', 'arguments' => array() ) );
		$this->assertStringNotContainsString( 'mcpwp_find_tools', wp_json_encode( $unknown ) );
	}

	public function test_with_meta_visible_the_guidance_is_there(): void {
		$controller = new Mcpwp_REST_MCP();
		$bad        = $this->mcp( $controller, null, 'tools/call', array( 'name' => 'wp_edit_widget', 'arguments' => array( 'id' => 1 ) ) );
		$this->assertStringContainsString( 'mcpwp_find_tools', $bad['result']['content'][0]['text'] );
		$unknown = $this->mcp( $controller, null, 'tools/call', array( 'name' => 'wp_no_such_tool', 'arguments' => array() ) );
		$this->assertStringContainsString( 'mcpwp_find_tools', wp_json_encode( $unknown ) );
	}

	// ── W1 ──────────────────────────────────────────────────────────────

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_introspect_is_filtered_to_what_the_caller_can_see(): void {
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-media.php';
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-design-references.php';
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';
		self::stub_site_info_functions();

		$mcp      = new Mcpwp_REST_MCP();
		$key      = $this->seo_key( $mcp );
		$expected = $this->names( $this->mcp( $mcp, $key, 'tools/list', array(), 'full' ) );
		sort( $expected );

		$site    = new Mcpwp_REST_Site_Info();
		$request = function ( array $params ) use ( $site, $key ) {
			$request = new WP_REST_Request( 'GET', '/mcpwp/v1/introspect', $params, array( 'X-API-Key' => $key ) );
			Mcpwp_REST_MCP::reset_verdict_memo();
			$this->assertTrue( true === $site->verify_api_key( $request ) );
			return $site->get_introspect( $request );
		};

		$summary = $request( array() )->get_data();
		$this->assertSame( count( $expected ), $summary['tools']['count'], 'The summary counts only the caller\'s tools.' );
		$this->assertSame( array( 'seo', 'site', 'meta' ), array_values( array_intersect( array( 'seo', 'site', 'meta' ), array_keys( $summary['tools']['by_category'] ) ) ) );
		$this->assertArrayNotHasKey( 'content', $summary['tools']['by_category'] );
		$this->assertStringContainsString( 'mcpwp_find_tools', $summary['tools']['find'] );

		$full  = $request( array( 'detail' => 'full' ) )->get_data();
		$names = array_column( $full['tools'], 'name' );
		sort( $names );
		$this->assertSame( $expected, $names, 'detail=full lists exactly the caller\'s tools/list.' );

		$hidden = $request( array( 'tool' => 'wp_create_api_key' ) );
		$absent = $request( array( 'tool' => 'wp_no_such_tool' ) );
		$this->assertInstanceOf( WP_Error::class, $hidden, 'A hidden tool is not found.' );
		$this->assertSame( $absent->get_error_code(), $hidden->get_error_code() );
		$this->assertSame( 404, $hidden->get_error_data()['status'] ?? null );

		$own = $request( array( 'tool' => 'wp_set_seo' ) )->get_data();
		$this->assertSame( 'wp_set_seo', $own['tool']['name'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_introspect_summary_drops_find_guidance_when_meta_is_disabled(): void {
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-media.php';
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-design-references.php';
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';
		self::stub_site_info_functions();
		update_option( 'mcpwp_disabled_tool_categories', array( 'meta' ) );

		$summary = ( new Mcpwp_REST_Site_Info() )->get_introspect( new WP_REST_Request( 'GET', '/mcpwp/v1/introspect' ) )->get_data();
		$this->assertStringNotContainsString( 'mcpwp_find_tools', wp_json_encode( $summary ) );
	}

	// ── N1-N3 ────────────────────────────────────────────────────────────

	public function test_a_real_no_break_space_becomes_a_space(): void {
		$this->assertSame( 'a b', Mcpwp_MCP_Tool_List_Diet::ascii_punctuation( "a\xC2\xA0b" ) );
		$this->assertSame( 'a b', Mcpwp_MCP_Tool_List_Diet::ascii_punctuation( 'a b' ), 'A plain space is untouched.' );
	}

	public function test_shorten_knows_other_scripts_sentence_ends(): void {
		$cjk = str_repeat( '这是一个句子', 4 ) . '。' . str_repeat( '更多的文字', 10 );
		$this->assertStringEndsWith( '。', Mcpwp_MCP_Tool_List_Diet::shorten( $cjk, 40 ) );

		$arabic = str_repeat( 'كلمة ', 8 ) . 'سؤال؟ ' . str_repeat( 'نص إضافي ', 10 );
		$this->assertStringEndsWith( '؟', Mcpwp_MCP_Tool_List_Diet::shorten( $arabic, 70 ) );

		$urdu = str_repeat( 'لفظ ', 10 ) . 'جملہ۔ ' . str_repeat( 'مزید متن ', 10 );
		$this->assertStringEndsWith( '۔', Mcpwp_MCP_Tool_List_Diet::shorten( $urdu, 70 ) );
	}

	public function test_shorten_never_splits_a_grapheme_cluster(): void {
		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
		$text   = str_repeat( $family, 40 );
		$out    = Mcpwp_MCP_Tool_List_Diet::shorten( $text, 20 );
		$this->assertLessThanOrEqual( 20, mb_strlen( $out ) );
		$this->assertSame( 0, ( mb_strlen( $out ) - 3 ) % 5, 'Whole five-codepoint ZWJ sequences only.' );
		$this->assertStringNotContainsString( "\u{200D}...", $out );

		$accented = str_repeat( "e\u{0301}", 40 );
		$out      = Mcpwp_MCP_Tool_List_Diet::shorten( $accented, 10 );
		$this->assertSame( 0, ( mb_strlen( $out ) - 3 ) % 2, 'A combining accent stays with its letter.' );
	}

	public function test_shorten_does_not_end_inside_parentheses_or_on_an_abbreviation(): void {
		$paren = 'This tool updates the page. It also (when asked, and only for drafts) rewrites the slug and the excerpt of every child page.';
		$out   = Mcpwp_MCP_Tool_List_Diet::shorten( $paren, 60 );
		$this->assertSame( substr_count( $out, '(' ), substr_count( $out, ')' ), "Unbalanced: $out" );

		$abbrev = 'Pass the page targets as a list of things, e.g. ids or slugs, and more words follow here to run past the cap.';
		$out    = Mcpwp_MCP_Tool_List_Diet::shorten( $abbrev, 50 );
		$this->assertStringEndsNotWith( 'e.g....', $out );
		$this->assertStringEndsNotWith( 'e.g.', $out );
	}

	public function test_an_emptied_subschema_is_a_json_object(): void {
		$schema = Mcpwp_MCP_Tool_List_Diet::slim_schema(
			array(
				'type'       => 'object',
				'properties' => array(
					'anything' => array( 'description' => '' ),
					'list'     => array(
						'type'  => 'array',
						'items' => array( 'description' => '', 'examples' => array( 1 ) ),
					),
				),
			),
			true
		);
		$this->assertSame( '{"type":"object","properties":{"anything":{},"list":{"type":"array","items":{}}}}', wp_json_encode( $schema ) );

		$empty = Mcpwp_MCP_Tool_List_Diet::slim_schema( array( 'type' => 'object', 'properties' => array() ), true );
		$this->assertSame( '{"type":"object","properties":{}}', wp_json_encode( $empty ) );
	}

	// ── N5 ──────────────────────────────────────────────────────────────

	public function test_profile_values_are_trimmed_lowercased_and_exact(): void {
		$this->assertSame( 'full', Mcpwp_REST_MCP::normalize_tool_profile( ' FULL ' ) );
		$this->assertSame( 'core', Mcpwp_REST_MCP::normalize_tool_profile( 'Core' ) );
		$this->assertSame( '', Mcpwp_REST_MCP::normalize_tool_profile( 'co re' ) );
		$this->assertSame( '', Mcpwp_REST_MCP::normalize_tool_profile( 'c.o.r.e' ) );
		$this->assertSame( '', Mcpwp_REST_MCP::normalize_tool_profile( array( 'core' ) ) );

		$settings = new Mcpwp_Settings();
		$this->assertSame( 'full', $settings->sanitize_settings( array( 'mcp_tool_profile' => ' Full' ) )['mcp_tool_profile'] );
		$this->assertArrayNotHasKey( 'mcp_tool_profile', $settings->sanitize_settings( array( 'mcp_tool_profile' => 'co re' ) ) );

		update_option( 'mcpwp_settings', array( 'mcp_tool_profile' => ' FULL ' ) );
		$this->assertContains( 'wp_delete_all_drafts', $this->names( $this->mcp( new Mcpwp_REST_MCP(), null, 'tools/list' ) ), 'A stored " FULL " reads as full.' );
	}

	public function test_the_mcp_settings_path_normalises_and_refuses_before_storing(): void {
		$controller = new Mcpwp_REST_Site_Settings();

		$ok = new WP_REST_Request( 'PUT', '/mcpwp/v1/plugin-settings' );
		$ok->set_body_params( array( 'mcp_tool_profile' => ' FULL ' ) );
		$this->assertNotInstanceOf( WP_Error::class, $controller->update_plugin_settings( $ok ) );
		$this->assertSame( 'full', get_option( 'mcpwp_settings' )['mcp_tool_profile'] );

		$bad = new WP_REST_Request( 'PUT', '/mcpwp/v1/plugin-settings' );
		$bad->set_body_params( array( 'mcp_tool_profile' => 'co re' ) );
		$response = $controller->update_plugin_settings( $bad );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'full', get_option( 'mcpwp_settings' )['mcp_tool_profile'], 'A refused value is never stored.' );
	}

	/**
	 * Functions Mcpwp_Core needs that the shared bootstrap does not stub —
	 * declared only inside a separate process.
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
