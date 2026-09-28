<?php
/**
 * Regression tests — #1041 (round-2 adversarial review of #1036).
 *
 * create_scoped_api_key() used to accept a custom-role key whose requested
 * tool_categories all sanitised away (e.g. ['bogus']), storing
 * `tool_categories: []`. resolve_key_tool_categories() treated an empty list
 * as UNRESTRICTED everywhere — the exact same contract admin/legacy keys
 * rely on — so such a key silently inherited every category. Live evidence
 * from the issue: such a key saw all 804 action-log entries.
 *
 * The fix has three parts, each covered below:
 *   (1) create_scoped_api_key() now refuses to create/store a custom-role key
 *       whose final category list is empty, naming the invalid categories.
 *   (2) resolve_key_tool_categories() now distinguishes "unrestricted"
 *       (array(), ONLY for role=admin or a legacy/roleless key) from
 *       "restricted to nothing" (the deny-all sentinel, for a custom role
 *       with nothing granted) — a custom key can still authenticate and
 *       reach the discovery allowlist, but every real category is denied,
 *       across every entry point: plain REST, /mcp tools/list + tools/call,
 *       /batch, resources/read, and the action log.
 *   (3) Mcpwp_Activator::migrate_empty_custom_category_keys() marks existing
 *       custom-role keys with an empty category list as deny-all in storage
 *       (tested separately in MigrateEmptyCustomCategoryKeysTest.php).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-action-log.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-pages.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-updates.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-pages.php';

/** Harness exposing the auth trait. All methods used here are already public/protected. */
class Mcpwp_1041_Auth_Harness {
	use Mcpwp_Api_Auth;

	public function resolve_categories( array $key_record ): array {
		return $this->resolve_key_tool_categories( $key_record );
	}

	public static function get_discovery_allowlist_tool_names_public(): array {
		return self::get_discovery_allowlist_tool_names();
	}
}

final class Regression1041EmptyCategoryKeyTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['mcpwp_test_filters']            = array();
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_posts']             = array();
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
	}

	protected function tearDown(): void {
		if ( class_exists( 'Mcpwp_REST_Batch' ) ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
	}

	private function harness(): Mcpwp_1041_Auth_Harness {
		return new Mcpwp_1041_Auth_Harness();
	}

	/**
	 * Hand-seed a custom-role key directly into the option store, bypassing
	 * create_scoped_api_key()'s own refusal (#1041's fix (1)) — simulating
	 * pre-fix data, or a record touched by hand/another integration.
	 *
	 * @return string Plaintext key.
	 */
	private function seed_custom_key_with_empty_categories( string $id = 'k-deny-all' ): string {
		$plain = 'mcpwp_' . str_repeat( 'a', 48 );
		update_option(
			'mcpwp_api_keys',
			array(
				array(
					'id'              => $id,
					'label'           => 'pre-fix custom key',
					'hash'            => wp_hash_password( $plain ),
					'scopes'          => array( 'read', 'write' ),
					'role'            => 'custom',
					'tool_categories' => array(),
					'created_at'      => '2026-01-01 00:00:00',
					'revoked_at'      => null,
				),
			)
		);
		return $plain;
	}

	// ── Fix (1): creation-time refusal ──────────────────────────────────────

	public function test_create_scoped_api_key_refuses_custom_role_with_no_categories(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'no cats', array( 'read', 'write' ), 'custom', array() );

		$this->assertIsArray( $created );
		$this->assertSame( 'empty_custom_categories', $created['error'] ?? null );
		$this->assertSame( array(), $created['invalid_categories'] );
		$this->assertArrayNotHasKey( 'key', $created, 'A refused creation must not mint a usable key.' );
	}

	public function test_create_scoped_api_key_refuses_custom_role_when_every_category_is_invalid(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'bogus cats', array( 'read', 'write' ), 'custom', array( 'bogus', 'also-bogus' ) );

		$this->assertSame( 'empty_custom_categories', $created['error'] ?? null );
		$this->assertSame( array( 'bogus', 'also-bogus' ), $created['invalid_categories'], 'Invalid slugs must be named so the caller can fix the request.' );
		$this->assertArrayNotHasKey( 'key', $created );
	}

	public function test_create_scoped_api_key_succeeds_for_custom_role_with_at_least_one_valid_category(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'one valid one bogus', array( 'read', 'write' ), 'custom', array( 'content', 'bogus' ) );

		$this->assertArrayNotHasKey( 'error', $created );
		$this->assertNotEmpty( $created['key'] ?? null );
		$this->assertSame( array( 'content' ), $created['tool_categories'] );
	}

	public function test_create_scoped_api_key_is_unaffected_for_non_custom_roles(): void {
		$auth = $this->harness();

		$admin_created = $auth->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );
		$this->assertArrayNotHasKey( 'error', $admin_created );

		$author_created = $auth->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' );
		$this->assertArrayNotHasKey( 'error', $author_created );
	}

	public function test_rest_create_api_key_returns_400_for_empty_custom_categories(): void {
		$controller = new Mcpwp_REST_Site_Updates();

		// can_manage_api_keys() reads the CONTROLLER's own current_api_key_record,
		// so authenticate an admin-scoped key on this instance first (same
		// technique as ApiKeyAdminRouteCapabilityTest).
		$admin_key = $controller->create_scoped_api_key( 'owner', array( 'admin' ), 'admin' );
		$auth_request = new WP_REST_Request( 'GET', '/mcpwp/v1/api-keys', array(), array( 'X-API-Key' => $admin_key['key'] ) );
		$this->assertTrue( $controller->verify_api_key( $auth_request ), 'premise: the admin key must authenticate' );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/api-keys',
			array(
				'label'           => 'bad key',
				'role'            => 'custom',
				'tool_categories' => array( 'bogus' ),
			)
		);

		$result = $controller->create_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'empty_custom_categories', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array( 'bogus' ), $result->get_error_data()['invalid_categories'] );
	}

	// ── Fix (2): resolve_key_tool_categories() semantics ────────────────────

	public function test_resolve_key_tool_categories_admin_is_unrestricted(): void {
		$auth = $this->harness();
		$this->assertSame( array(), $auth->resolve_categories( array( 'role' => 'admin' ) ) );
	}

	public function test_resolve_key_tool_categories_legacy_roleless_key_is_unrestricted(): void {
		$auth = $this->harness();
		$this->assertSame( array(), $auth->resolve_categories( array( 'id' => 'legacy' ) ) );
	}

	public function test_resolve_key_tool_categories_custom_with_empty_list_is_deny_all_not_unrestricted(): void {
		$auth     = $this->harness();
		$resolved = $auth->resolve_categories( array( 'role' => 'custom', 'tool_categories' => array() ) );

		$this->assertNotSame( array(), $resolved, 'Deny-all must never be represented as the unrestricted empty array (#1041).' );
		$this->assertTrue( Mcpwp_1041_Auth_Harness::is_deny_all_categories( $resolved ) );
		$this->assertFalse( in_array( 'content', $resolved, true ) );
		$this->assertFalse( in_array( 'admin', $resolved, true ) );
	}

	public function test_resolve_key_tool_categories_custom_with_real_categories_is_unaffected(): void {
		$auth     = $this->harness();
		$resolved = $auth->resolve_categories( array( 'role' => 'custom', 'tool_categories' => array( 'seo' ) ) );

		$this->assertSame( array( 'seo' ), $resolved );
		$this->assertFalse( Mcpwp_1041_Auth_Harness::is_deny_all_categories( $resolved ) );
	}

	public function test_resolve_key_tool_categories_unknown_role_is_deny_all(): void {
		// Unreachable through normal auth (normalize_api_key_record() coerces
		// an unknown role to 'admin'), but a raw record could still reach the
		// resolver directly — must fail closed, not open (#1041).
		$auth     = $this->harness();
		$resolved = $auth->resolve_categories( array( 'role' => 'made-up-role' ) );

		$this->assertTrue( Mcpwp_1041_Auth_Harness::is_deny_all_categories( $resolved ) );
	}

	public function test_key_allows_category_denies_every_real_category_for_deny_all(): void {
		$auth   = $this->harness();
		$record = array( 'role' => 'custom', 'tool_categories' => array() );

		foreach ( array_keys( Mcpwp_1041_Auth_Harness::get_all_tool_category_labels() ) as $category ) {
			$this->assertFalse( $auth->key_allows_category( $record, $category ), "deny-all key must not be allowed '$category'" );
		}
	}

	public function test_describe_allowed_categories_for_message_never_leaks_the_sentinel(): void {
		$deny_all = array( Mcpwp_1041_Auth_Harness::deny_all_category_marker() );
		$message  = Mcpwp_1041_Auth_Harness::describe_allowed_categories_for_message( $deny_all );

		$this->assertStringNotContainsString( '__mcpwp_deny_all__', $message );
		$this->assertSame( array(), Mcpwp_1041_Auth_Harness::present_tool_categories( $deny_all ) );
	}

	// ── Cross-surface: a hand-seeded (or pre-fix) deny-all key ──────────────

	public function test_deny_all_key_is_refused_on_a_direct_rest_route(): void {
		$plain = $this->seed_custom_key_with_empty_categories();

		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/pages', array(), array( 'X-API-Key' => $plain ) );
		$result     = $controller->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden_category', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_deny_all_key_still_authenticates_and_reaches_the_discovery_allowlist(): void {
		$plain      = $this->seed_custom_key_with_empty_categories();
		$controller = new Mcpwp_REST_Pages();

		foreach ( array( '/mcpwp/v1/site-info', '/mcpwp/v1/onboard', '/mcpwp/v1/introspect' ) as $route ) {
			$request = new WP_REST_Request( 'GET', $route, array(), array( 'X-API-Key' => $plain ) );
			$this->assertTrue( $controller->verify_api_key( $request ), "deny-all key must still reach discovery route $route" );
		}
	}

	/**
	 * The exact live evidence from the issue: a broken custom key used to see
	 * every action-log entry. /action-log is category 'admin' — a deny-all
	 * key must be refused, not silently granted the admin surface.
	 */
	public function test_deny_all_key_is_refused_on_the_action_log(): void {
		$plain      = $this->seed_custom_key_with_empty_categories();
		$controller = new Mcpwp_REST_Action_Log();
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array(), array( 'X-API-Key' => $plain ) );

		$result = $controller->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden_category', $result->get_error_code() );
	}

	public function test_deny_all_key_is_refused_inside_a_batch_envelope(): void {
		$plain = $this->seed_custom_key_with_empty_categories();

		$controller = new Mcpwp_REST_Pages();
		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/pages', array(), array( 'X-API-Key' => $plain ) );
		$result  = $controller->verify_api_key( $request );
		Mcpwp_REST_Batch::$is_batch_sub_request = false;

		$this->assertInstanceOf( WP_Error::class, $result, 'A /batch envelope must not launder a deny-all key past its per-key category restriction.' );
		$this->assertSame( 'forbidden_category', $result->get_error_code() );
	}

	public function test_deny_all_key_tools_list_only_advertises_discovery_tools(): void {
		$plain      = $this->seed_custom_key_with_empty_categories();
		$controller = new Mcpwp_REST_MCP();

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $plain ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
			)
		);
		$this->assertTrue( $controller->verify_api_key( $request ), 'premise: the deny-all key must still authenticate' );

		$data  = $controller->handle_mcp( $request )->get_data();
		$tools = array_column( $data['result']['tools'], 'name' );

		$discovery_names = Mcpwp_1041_Auth_Harness::get_discovery_allowlist_tool_names_public();
		foreach ( $tools as $name ) {
			$this->assertContains( $name, $discovery_names, "tools/list must not advertise '$name' to a deny-all key" );
		}
		$this->assertNotEmpty( $tools, 'the discovery tools themselves must still be listed' );
	}

	public function test_deny_all_key_tools_call_is_scope_denied_and_never_leaks_the_sentinel(): void {
		$plain      = $this->seed_custom_key_with_empty_categories();
		$controller = new Mcpwp_REST_MCP();

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $plain ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'wp_list_pages', 'arguments' => array() ),
			)
		);
		$this->assertTrue( $controller->verify_api_key( $request ) );

		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertTrue( $data['result']['isError'] ?? false, 'a deny-all key must never be able to call wp_list_pages' );
		$error_code = $data['result']['structuredContent']['error']['code'] ?? null;
		$this->assertSame( 'scope_denied', $error_code );
		$text = $data['result']['content'][0]['text'] ?? '';
		$this->assertStringNotContainsString( '__mcpwp_deny_all__', $text, 'the internal sentinel must never reach a client-visible message' );
	}

	public function test_deny_all_key_tools_call_still_reaches_a_discovery_tool(): void {
		$plain      = $this->seed_custom_key_with_empty_categories();
		$controller = new Mcpwp_REST_MCP();

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $plain ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'wp_site_info', 'arguments' => array() ),
			)
		);
		$this->assertTrue( $controller->verify_api_key( $request ) );

		$data = $controller->handle_mcp( $request )->get_data();
		$this->assertFalse( $data['result']['isError'] ?? true, 'wp_site_info is a discovery tool and must stay reachable' );
	}

	public function test_deny_all_key_resources_read_is_denied_and_never_leaks_the_sentinel(): void {
		$GLOBALS['_mcpwp_test_posts'][501] = new WP_Post(
			array( 'ID' => 501, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Pub' )
		);
		$plain      = $this->seed_custom_key_with_empty_categories();
		$controller = new Mcpwp_REST_MCP();

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $plain ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'resources/read',
				'params'  => array( 'uri' => 'mcpwp://page/501' ),
			)
		);
		$this->assertTrue( $controller->verify_api_key( $request ) );

		$data = $controller->handle_mcp( $request );
		// A JSON-RPC error is returned as a distinct top-level 'error' member
		// (not a tools/call-shaped isError result) for resources/read.
		$body = $data->get_data();
		$this->assertArrayHasKey( 'error', $body, 'a deny-all key must not be able to read an ordinary page resource' );
		$this->assertStringNotContainsString( '__mcpwp_deny_all__', wp_json_encode( $body ) );
	}
}
