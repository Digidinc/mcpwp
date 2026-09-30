<?php
/**
 * #1064 adversarial review, round 2.
 *
 * W1  Round 1 granted the `meta` CATEGORY to every restricted key, so an
 *     addon tool registered in `meta` became callable by every key —
 *     deny-all, unknown-role, seo-only, author — directly and through
 *     mcpwp_call (#1041 broken). Now the two meta tools are allowed BY NAME
 *     (key_role_permits_tool()), no key is granted `meta`, and the slug is
 *     reserved: an addon claiming it is remapped to `custom`.
 * W2  The instructions promised a restricted key "every other tool" and
 *     named tools it could not call.
 * N1  parentheses_balanced() is order-aware.
 * N2  No per-key "Tool Search & Dispatch" category; messages, initialize and
 *     list_scoped_api_keys never list `meta`.
 * N3  "Did you mean" draws only from tools the caller can see.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ToolWeightBudgetReview2Test extends TestCase {

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
		$GLOBALS['b1064_acme_calls'] = 0;
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_fs']      = null;
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_filters'] = array();
		Mcpwp_Integration::clear_cache();
		$this->reset_license();
		Mcpwp_REST_MCP::reset_verdict_memo();
	}

	private function reset_license(): void {
		$ref = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	/** The reviewer's repro: an mu-plugin tool in category `meta`. */
	private function register_acme_export_orders(): void {
		add_filter(
			'mcpwp_register_tools',
			static function ( $tools ) {
				$tools[] = array(
					'name'        => 'acme_export_orders',
					'description' => 'Export every order with customer emails.',
					'category'    => 'meta',
					'callback'    => static function () {
						$GLOBALS['b1064_acme_calls']++;
						return array( 'orders' => array( array( 'email' => 'buyer@example.com' ) ) );
					},
				);
				return $tools;
			}
		);
	}

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

	/**
	 * Seed a key record directly (the API refuses to create the malformed
	 * shapes #1041 guards against).
	 */
	private function seed_key( string $label, array $fields ): string {
		$plain = 'mcpwp_' . bin2hex( random_bytes( 24 ) );
		$keys  = get_option( 'mcpwp_api_keys', array() );
		$keys  = is_array( $keys ) ? $keys : array();
		$keys[] = array_merge(
			array(
				'id'         => 'k' . substr( md5( $label ), 0, 12 ),
				'label'      => $label,
				'hash'       => wp_hash_password( $plain ),
				'scopes'     => array( 'read', 'write' ),
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => null,
			),
			$fields
		);
		update_option( 'mcpwp_api_keys', $keys );
		return $plain;
	}

	/**
	 * @return array<string,string> label => plain key
	 */
	private function restricted_keys( Mcpwp_REST_MCP $controller ): array {
		return array(
			'deny-all'     => $this->seed_key( 'deny-all', array( 'role' => 'custom', 'tool_categories' => array() ) ),
			'unknown-role' => $this->seed_key( 'unknown-role', array( 'role' => 'viewer', 'tool_categories' => array( 'content' ) ) ),
			'seo-only'     => $controller->create_scoped_api_key( 'seo-only', array( 'read', 'write' ), 'custom', array( 'seo' ) )['key'],
			'author'       => $controller->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' )['key'],
			// A post-#907 custom key that stored `meta` explicitly.
			'stored-meta'  => $this->seed_key( 'stored-meta', array( 'role' => 'custom', 'tool_categories' => array( 'seo', 'meta' ) ) ),
		);
	}

	// ── W1 ──────────────────────────────────────────────────────────────

	public function test_an_addon_tool_in_meta_is_refused_to_every_restricted_key(): void {
		$this->register_acme_export_orders();
		$controller = new Mcpwp_REST_MCP();

		foreach ( $this->restricted_keys( $controller ) as $label => $key ) {
			foreach ( array( 'core', 'full' ) as $profile ) {
				$names = array_column( $this->mcp( $controller, $key, 'tools/list', array(), $profile )['result']['tools'], 'name' );
				$this->assertNotContains( 'acme_export_orders', $names, "$label ($profile): the addon tool must not be listed." );
				$this->assertContains( 'mcpwp_find_tools', $names, "$label ($profile): find stays visible by name." );
				$this->assertContains( 'mcpwp_call', $names, "$label ($profile): call stays visible by name." );
			}

			$direct = $this->mcp( $controller, $key, 'tools/call', array( 'name' => 'acme_export_orders', 'arguments' => array() ) );
			$this->assertTrue( $direct['result']['isError'] ?? false, "$label: direct call must be refused." );
			$this->assertSame( 'scope_denied', $direct['result']['structuredContent']['error']['code'] ?? null, $label );

			$wrapped = $this->mcp( $controller, $key, 'tools/call', array( 'name' => 'mcpwp_call', 'arguments' => array( 'name' => 'acme_export_orders', 'arguments' => array() ) ) );
			$this->assertTrue( $wrapped['result']['isError'] ?? false, "$label: mcpwp_call must be refused." );
			$this->assertStringNotContainsString( 'buyer@example.com', wp_json_encode( $wrapped ), $label );

			$found = $this->mcp( $controller, $key, 'tools/call', array( 'name' => 'mcpwp_find_tools', 'arguments' => array( 'query' => 'export orders acme' ) ) );
			$this->assertStringNotContainsString( 'acme_export_orders', $found['result']['content'][0]['text'], "$label: find_tools must not surface it." );
		}

		$this->assertSame( 0, $GLOBALS['b1064_acme_calls'], 'The addon callback never ran for a restricted key.' );
	}

	public function test_the_addon_tool_is_remapped_to_custom_and_still_works_for_an_unrestricted_caller(): void {
		$this->register_acme_export_orders();
		$controller = new Mcpwp_REST_MCP();

		$tools = array_column( $this->mcp( $controller, null, 'tools/list', array(), 'full' )['result']['tools'], null, 'name' );
		$this->assertSame( 'custom', $tools['acme_export_orders']['annotations']['category'], '`meta` is reserved; the claim is remapped.' );
		$this->assertSame( 'meta', $tools['mcpwp_call']['annotations']['category'] );

		$called = $this->mcp( $controller, null, 'tools/call', array( 'name' => 'acme_export_orders', 'arguments' => array() ) );
		$this->assertFalse( $called['result']['isError'] ?? true );
		$this->assertSame( 1, $GLOBALS['b1064_acme_calls'] );
	}

	public function test_a_reserved_category_claim_is_logged_once_not_every_request(): void {
		$log = tempnam( sys_get_temp_dir(), 'b1064' );
		$old = ini_set( 'error_log', $log );
		Mcpwp_Integration::remap_reserved_category( 'meta', 'zz_once_tool', 'test' );
		$ref = new ReflectionProperty( Mcpwp_Integration::class, 'reserved_category_logged' );
		$ref->setAccessible( true );
		$ref->setValue( null, array() ); // A new request.
		Mcpwp_Integration::remap_reserved_category( 'meta', 'zz_once_tool', 'test' );
		ini_set( 'error_log', $old );
		$this->assertSame( 1, substr_count( (string) file_get_contents( $log ), 'zz_once_tool' ) );
		unlink( $log );
	}

	public function test_an_integration_claiming_meta_is_remapped_too(): void {
		$this->assertSame(
			array( 'x_tool' => 'custom', 'y_tool' => 'seo' ),
			Mcpwp_Integration::remap_reserved_categories( array( 'x_tool' => 'meta', 'y_tool' => 'seo' ), 'test' )
		);
	}

	public function test_no_key_is_granted_meta_and_the_exempt_names_are_the_meta_tools(): void {
		$controller = new Mcpwp_REST_MCP();
		$this->assertSame( Mcpwp_REST_MCP::META_TOOL_NAMES, Mcpwp_REST_MCP::get_key_exempt_tool_names() );
		$this->assertSame( array( 'seo' ), $controller->resolve_key_tool_categories( array( 'role' => 'custom', 'tool_categories' => array( 'seo', 'meta' ) ) ) );
		$this->assertSame( array( 'content', 'media', 'taxonomy' ), $controller->resolve_key_tool_categories( array( 'role' => 'author' ) ) );
		$this->assertTrue( Mcpwp_REST_MCP::is_deny_all_categories( $controller->resolve_key_tool_categories( array( 'role' => 'custom', 'tool_categories' => array( 'meta' ) ) ) ), 'A key that stored only `meta` is deny-all.' );
		$this->assertTrue( Mcpwp_REST_MCP::is_deny_all_categories( $controller->resolve_key_tool_categories( array( 'tool_categories' => array( 'meta' ) ) ) ), 'A roleless record with only `meta` is deny-all, never unrestricted.' );
		foreach ( Mcpwp_REST_MCP::get_role_definitions() as $role => $definition ) {
			$this->assertNotContains( 'meta', $definition['categories'], "$role must not list meta." );
		}
	}

	public function test_initialize_list_and_messages_never_list_meta(): void {
		$controller = new Mcpwp_REST_MCP();
		$keys       = $this->restricted_keys( $controller );

		$init = $this->mcp( $controller, $keys['stored-meta'], 'initialize', array( 'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 't', 'version' => '1' ) ) );
		$this->assertSame( array( 'seo' ), $init['result']['serverInfo']['tool_categories'] );

		$author = $this->mcp( $controller, $keys['author'], 'initialize', array( 'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 't', 'version' => '1' ) ) );
		$this->assertSame( array( 'content', 'media', 'taxonomy' ), $author['result']['serverInfo']['tool_categories'] );

		$refused = $this->mcp( $controller, $keys['stored-meta'], 'tools/call', array( 'name' => 'wp_list_pages', 'arguments' => array() ) );
		$this->assertStringContainsString( 'Allowed categories: seo.', $refused['result']['structuredContent']['error']['message'] );

		foreach ( $controller->list_scoped_api_keys() as $listed ) {
			$this->assertNotContains( 'meta', $listed['tool_categories'], "{$listed['label']}: list_scoped_api_keys must agree with the messages." );
		}
	}

	public function test_the_key_setup_ui_offers_no_meta_category(): void {
		$this->assertArrayNotHasKey( 'meta', Mcpwp_REST_MCP::get_all_tool_category_labels() );
		$created = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'only meta', array( 'read' ), 'custom', array( 'meta' ) );
		$this->assertSame( 'empty_custom_categories', $created['error'] ?? null, 'meta alone is not a grantable category.' );
	}

	// ── W2 ──────────────────────────────────────────────────────────────

	public function test_a_restricted_key_is_told_only_about_tools_it_can_call(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'seo-only', array( 'read', 'write' ), 'custom', array( 'seo' ) )['key'];

		$callable = array_column( $this->mcp( $controller, $key, 'tools/list', array(), 'full' )['result']['tools'], 'name' );
		$text     = $this->mcp( $controller, $key, 'initialize', array( 'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 't', 'version' => '1' ) ) )['result']['instructions'];

		preg_match_all( '/`((?:wp|wc|mcpwp)_[a-z0-9_]+)/', $text, $named );
		$this->assertNotEmpty( $named[1], 'Test premise: the instructions still name some tools.' );
		$this->assertSame( array(), array_values( array_diff( array_unique( $named[1] ), $callable ) ), 'Named but not callable by this key.' );
		$this->assertStringContainsString( 'your key may call', $text );
		$this->assertStringNotContainsString( 'If a tool named below is not in your list', $text );
		$this->assertStringNotContainsString( 'Every other tool on this site', $text );

		$admin = $this->mcp( new Mcpwp_REST_MCP(), null, 'initialize', array( 'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 't', 'version' => '1' ) ) )['result']['instructions'];
		$this->assertStringContainsString( 'Every other tool on this site', $admin, 'An unrestricted caller keeps the full guidance.' );
	}

	// ── N1 ──────────────────────────────────────────────────────────────

	public function test_parentheses_balance_is_order_aware(): void {
		$method = new ReflectionMethod( Mcpwp_MCP_Tool_List_Diet::class, 'parentheses_balanced' );
		$method->setAccessible( true );
		$this->assertTrue( $method->invoke( null, 'a (b) c' ) );
		$this->assertTrue( $method->invoke( null, 'no parens' ) );
		$this->assertFalse( $method->invoke( null, 'a ) b ( c' ), 'Same counts, wrong order.' );
		$this->assertFalse( $method->invoke( null, 'a (b (c)' ) );
		$this->assertFalse( $method->invoke( null, 'a) b' ) );

		// A stray ")" can never be balanced; shorten() still cuts at a word.
		$out = Mcpwp_MCP_Tool_List_Diet::shorten( 'a) first step then another step and yet another step keeps going on', 30 );
		$this->assertMatchesRegularExpression( '/ [a-z]+\.\.\.$/', $out );
	}

	// ── N3 ──────────────────────────────────────────────────────────────

	public function test_did_you_mean_names_only_tools_the_caller_can_see(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'seo-only', array( 'read', 'write' ), 'custom', array( 'seo' ) )['key'];

		$direct = $this->mcp( $controller, $key, 'tools/call', array( 'name' => 'wp_create_pagee', 'arguments' => array() ) );
		$this->assertStringNotContainsString( 'wp_create_page"', wp_json_encode( $direct ), 'A hidden tool must not be suggested.' );

		$wrapped = $this->mcp( $controller, $key, 'tools/call', array( 'name' => 'mcpwp_call', 'arguments' => array( 'name' => 'wp_create_pagee', 'arguments' => array() ) ) );
		$this->assertStringNotContainsString( 'wp_create_page\"', wp_json_encode( $wrapped ) );
		$this->assertStringNotContainsString( 'wp_create_page"', wp_json_encode( $wrapped ) );

		$own = $this->mcp( $controller, $key, 'tools/call', array( 'name' => 'wp_set_seoo', 'arguments' => array() ) );
		$this->assertStringContainsString( 'Did you mean \"wp_set_seo\"', wp_json_encode( $own ), 'A visible tool is still suggested.' );

		$admin = $this->mcp( new Mcpwp_REST_MCP(), null, 'tools/call', array( 'name' => 'wp_create_pagee', 'arguments' => array() ) );
		$this->assertStringContainsString( 'Did you mean \"wp_create_page\"', wp_json_encode( $admin ) );

		// Unchanged by design: a KNOWN tool outside the key's categories is
		// scope_denied (it exists; the key may not call it).
		$known = $this->mcp( $controller, $key, 'tools/call', array( 'name' => 'wp_create_page', 'arguments' => array( 'title' => 'x' ) ) );
		$this->assertSame( 'scope_denied', $known['result']['structuredContent']['error']['code'] ?? null );
	}
}
