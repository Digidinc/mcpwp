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

	public function normalize( array $record ): array {
		return $this->normalize_api_key_record( $record );
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

	// ── #1041 WARN-2 (round-2 adversarial review of PR #1043) ───────────────

	/**
	 * An unrecognised, non-empty role used to be silently coerced to 'admin'
	 * — POST /api-keys {"role":"custom-seo",...} came back 201 role:admin,
	 * discarding both the caller's intended role AND any tool_categories they
	 * meant to scope it to. It must be refused instead, naming the valid roles.
	 */
	public function test_create_scoped_api_key_refuses_an_unrecognised_role(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'typo role', array( 'read', 'write' ), 'custom-seo', array( 'seo' ) );

		$this->assertSame( 'unknown_role', $created['error'] ?? null );
		$this->assertArrayNotHasKey( 'key', $created, 'a refused creation must not mint a usable key' );
		$this->assertContains( 'custom', $created['valid_roles'] );
		$this->assertContains( 'admin', $created['valid_roles'] );
		$this->assertNotContains( 'custom-seo', $created['valid_roles'] );
	}

	public function test_create_scoped_api_key_defaults_an_empty_role_to_admin(): void {
		// Unspecified/empty is a caller convenience, not a garbled role —
		// must NOT be refused the same way a genuinely wrong role is.
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'no role given', array( 'read', 'write', 'admin' ), '' );

		$this->assertArrayNotHasKey( 'error', $created );
		$this->assertSame( 'admin', $created['role'] );
	}

	public function test_rest_create_api_key_refuses_an_unrecognised_role(): void {
		$controller   = new Mcpwp_REST_Site_Updates();
		$admin_key    = $controller->create_scoped_api_key( 'owner', array( 'admin' ), 'admin' );
		$auth_request = new WP_REST_Request( 'GET', '/mcpwp/v1/api-keys', array(), array( 'X-API-Key' => $admin_key['key'] ) );
		$this->assertTrue( $controller->verify_api_key( $auth_request ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/api-keys',
			array(
				'label'           => 'bad role',
				'role'            => 'custom-seo',
				'tool_categories' => array( 'seo' ),
			)
		);

		$result = $controller->create_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unknown_role', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertContains( 'admin', $result->get_error_data()['valid_roles'] );
	}

	// ── #1041 WARN-B (round-3 adversarial review of PR #1043) ───────────────
	//
	// POST /api-keys {"tool_categories":["seo"]} with no role used to default
	// to role:admin with the categories stored but silently ignored
	// (resolve_key_tool_categories() is unrestricted for role=admin
	// regardless of what tool_categories holds). Rule: tool_categories
	// supplied + role omitted -> role defaults to 'custom'; an EXPLICIT
	// non-custom role + tool_categories supplied -> refused outright.

	public function test_create_scoped_api_key_defaults_to_custom_when_categories_supplied_and_role_omitted(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'scoped by categories alone', array( 'read', 'write' ), '', array( 'seo' ) );

		$this->assertArrayNotHasKey( 'error', $created );
		$this->assertSame( 'custom', $created['role'] );
		$this->assertSame( array( 'seo' ), $created['tool_categories'] );
	}

	public function test_create_scoped_api_key_still_defaults_to_admin_when_no_categories_and_role_omitted(): void {
		// Unchanged behaviour: a bare create call with neither role nor
		// categories still mints an admin key.
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'bare create call', array( 'read', 'write', 'admin' ) );

		$this->assertArrayNotHasKey( 'error', $created );
		$this->assertSame( 'admin', $created['role'] );
	}

	public function test_create_scoped_api_key_refuses_categories_on_an_explicit_predefined_role(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'author with categories', array( 'read', 'write' ), 'author', array( 'seo' ) );

		$this->assertSame( 'categories_require_custom_role', $created['error'] ?? null );
		$this->assertArrayNotHasKey( 'key', $created );
	}

	public function test_create_scoped_api_key_refuses_categories_on_an_explicit_admin_role(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'admin with categories', array( 'read', 'write', 'admin' ), 'admin', array( 'seo' ) );

		$this->assertSame( 'categories_require_custom_role', $created['error'] ?? null );
	}

	public function test_create_scoped_api_key_all_invalid_categories_still_refuses_a_non_custom_role(): void {
		// "Supplied" means the caller passed something, even if every entry
		// is garbage — that is still a clear signal they meant to scope the
		// key, not a no-op that should fall through to plain 'author'.
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'author with bogus categories', array( 'read', 'write' ), 'author', array( 'bogus' ) );

		$this->assertSame( 'categories_require_custom_role', $created['error'] ?? null );
	}

	public function test_rest_create_api_key_defaults_to_custom_when_only_categories_are_posted(): void {
		$controller   = new Mcpwp_REST_Site_Updates();
		$admin_key    = $controller->create_scoped_api_key( 'owner', array( 'admin' ), 'admin' );
		$auth_request = new WP_REST_Request( 'GET', '/mcpwp/v1/api-keys', array(), array( 'X-API-Key' => $admin_key['key'] ) );
		$this->assertTrue( $controller->verify_api_key( $auth_request ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/api-keys',
			array(
				'label'           => 'categories only',
				'tool_categories' => array( 'seo' ),
			)
		);

		$result = $controller->create_api_key( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $result, 'sanity: not an error response' );
		$this->assertSame( 201, $result->get_status() );
		$data = $result->get_data();
		$this->assertSame( 'custom', $data['api_key']['role'] );
		$this->assertSame( array( 'seo' ), $data['api_key']['tool_categories'] );
	}

	public function test_rest_create_api_key_refuses_categories_paired_with_a_non_custom_role(): void {
		$controller   = new Mcpwp_REST_Site_Updates();
		$admin_key    = $controller->create_scoped_api_key( 'owner', array( 'admin' ), 'admin' );
		$auth_request = new WP_REST_Request( 'GET', '/mcpwp/v1/api-keys', array(), array( 'X-API-Key' => $admin_key['key'] ) );
		$this->assertTrue( $controller->verify_api_key( $auth_request ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/api-keys',
			array(
				'label'           => 'author with categories',
				'role'            => 'author',
				'tool_categories' => array( 'seo' ),
			)
		);

		$result = $controller->create_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'categories_require_custom_role', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// ── #1041 NIT-A (round-3 adversarial review of PR #1043) ────────────────
	//
	// Role is accepted case-insensitively and trimmed; "" or null is OMITTED
	// (WARN-B decides), not an unknown-role refusal.

	public function test_create_scoped_api_key_accepts_a_differently_cased_or_padded_role(): void {
		$auth = $this->harness();

		$created_1 = $auth->create_scoped_api_key( 'k1', array( 'read', 'write' ), 'Custom', array( 'seo' ) );
		$this->assertArrayNotHasKey( 'error', $created_1 );
		$this->assertSame( 'custom', $created_1['role'] );

		$created_2 = $auth->create_scoped_api_key( 'k2', array( 'read', 'write' ), ' custom ', array( 'seo' ) );
		$this->assertArrayNotHasKey( 'error', $created_2 );
		$this->assertSame( 'custom', $created_2['role'] );
	}

	public function test_create_scoped_api_key_treats_null_role_as_omitted(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'null role', array( 'read', 'write' ), null, array( 'seo' ) );

		$this->assertArrayNotHasKey( 'error', $created );
		$this->assertSame( 'custom', $created['role'], 'null role + supplied categories must default to custom, not refuse as unknown_role' );
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

	// ── #1041 WARN-A (round-3 adversarial review of PR #1043) ───────────────
	//
	// A real MCPWP legacy key predates role-scoping AND tool_categories —
	// the two were introduced together, so a genuine legacy key never has
	// tool_categories without a role. A roleless record that DOES carry a
	// non-empty tool_categories is a different writer's shape (the wp.org
	// "Mumega MCP" edition writes exactly {scopes, tool_categories} with no
	// role field to the same mcpwp_api_keys option) and must resolve as
	// 'custom' with those categories, not silently inherit every category.

	public function test_resolve_key_tool_categories_roleless_with_categories_is_custom_not_unrestricted(): void {
		$auth     = $this->harness();
		$resolved = $auth->resolve_categories( array( 'scopes' => array( 'read', 'write' ), 'tool_categories' => array( 'content' ) ) );

		$this->assertSame( array( 'content' ), $resolved );
		$this->assertNotSame( array(), $resolved, 'must not be treated as unrestricted just because role is absent' );
	}

	public function test_resolve_key_tool_categories_roleless_with_empty_categories_stays_legacy_unrestricted(): void {
		// The genuine legacy shape: no role AND no (or empty) tool_categories.
		$auth = $this->harness();

		$this->assertSame( array(), $auth->resolve_categories( array( 'scopes' => array( 'read', 'write' ) ) ) );
		$this->assertSame( array(), $auth->resolve_categories( array( 'scopes' => array( 'read', 'write' ), 'tool_categories' => array() ) ) );
	}

	public function test_normalize_api_key_record_roleless_with_categories_normalizes_to_custom(): void {
		$auth       = $this->harness();
		$normalized = $auth->normalize( array( 'id' => 'k', 'hash' => 'h', 'scopes' => array( 'read', 'write' ), 'tool_categories' => array( 'content' ) ) );

		$this->assertSame( 'custom', $normalized['role'] );
		$this->assertSame( array( 'content' ), $normalized['tool_categories'] );
	}

	public function test_normalize_api_key_record_genuine_legacy_shape_stays_admin(): void {
		$auth       = $this->harness();
		$normalized = $auth->normalize( array( 'id' => 'k', 'hash' => 'h', 'scopes' => array( 'read', 'write' ) ) );

		$this->assertSame( 'admin', $normalized['role'] );
	}

	/**
	 * Through a real door: hand-seed the exact wp.org "Mumega MCP" edition
	 * writer shape (no role field at all) directly into mcpwp_api_keys and
	 * verify it behaves as a real custom('content')-scoped key — allowed on
	 * /pages, denied on /action-log — never as an unrestricted admin key.
	 */
	public function test_roleless_with_categories_key_behaves_as_scoped_custom_through_a_real_door(): void {
		$plain = 'mcpwp_' . str_repeat( 'f', 48 );
		update_option(
			'mcpwp_api_keys',
			array(
				array(
					'id'              => 'k-wporg-edition',
					'label'           => 'wp.org edition key',
					'hash'            => wp_hash_password( $plain ),
					'scopes'          => array( 'read', 'write' ),
					'tool_categories' => array( 'content' ),
					'created_at'      => '2026-01-01 00:00:00',
					'revoked_at'      => null,
				),
			)
		);

		$pages_controller = new Mcpwp_REST_Pages();
		$pages_request     = new WP_REST_Request( 'GET', '/mcpwp/v1/pages', array(), array( 'X-API-Key' => $plain ) );
		$this->assertTrue( $pages_controller->verify_api_key( $pages_request ), 'content category must be allowed' );

		$log_controller = new Mcpwp_REST_Action_Log();
		$log_request    = new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array(), array( 'X-API-Key' => $plain ) );
		$log_result     = $log_controller->verify_api_key( $log_request );

		$this->assertInstanceOf( WP_Error::class, $log_result, 'admin category must be denied — this key is scoped, not unrestricted' );
		$this->assertSame( 'forbidden_category', $log_result->get_error_code() );
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
		// A raw, non-normalized record with a role outside get_role_definitions()
		// reaching the resolver directly (normalize_api_key_record() itself is
		// covered separately below, #1041 WARN-1) — must fail closed, not open.
		$auth     = $this->harness();
		$resolved = $auth->resolve_categories( array( 'role' => 'made-up-role' ) );

		$this->assertTrue( Mcpwp_1041_Auth_Harness::is_deny_all_categories( $resolved ) );
	}

	/**
	 * #1038 (WebMCP) x #1041: Mcpwp_WebMCP::principal_record() builds its
	 * synthetic key record directly (role 'custom', tool_categories
	 * PRINCIPAL_CATEGORIES) — it never goes through create_scoped_api_key()
	 * or normalize_api_key_record(). Pin that this fix's resolver still reads
	 * it correctly: a real, non-empty category list, neither the unrestricted
	 * array() NOR the deny-all sentinel.
	 */
	public function test_resolve_key_tool_categories_webmcp_principal_is_neither_unrestricted_nor_deny_all(): void {
		$auth     = $this->harness();
		$record   = Mcpwp_WebMCP::principal_record( 7 );
		$resolved = $auth->resolve_categories( $record );

		$this->assertSame( Mcpwp_WebMCP::PRINCIPAL_CATEGORIES, $resolved );
		$this->assertNotSame( array(), $resolved, 'the WebMCP principal must not be treated as unrestricted' );
		$this->assertFalse( Mcpwp_1041_Auth_Harness::is_deny_all_categories( $resolved ), 'the WebMCP principal must not be treated as deny-all' );

		// And through a real door: /pages is 'content', which the principal
		// holds — must be allowed; /action-log is 'admin', which it does not
		// — must be denied, same as any other correctly-scoped custom key.
		$this->assertTrue( $auth->key_allows_category( $record, 'content' ) );
		$this->assertFalse( $auth->key_allows_category( $record, 'admin' ) );
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

	// ── #1041 WARN-1 (round-2 adversarial review of PR #1043) ───────────────
	//
	// normalize_api_key_record() used to coerce ANY unrecognised 'role' value
	// — present but empty, numeric, null, or a plain typo — to 'admin' BEFORE
	// resolve_key_tool_categories() ever saw it, making the "unknown role ->
	// deny-all" branch dead code. Live: a hand-seeded record with role
	// 'viewer', '', null or 5 (each carrying tool_categories:['content'], so
	// the ONLY thing wrong with it was the role) got 200 on /action-log and
	// the full 172-tool catalog on tools/list. These test the real path:
	// normalize -> resolve -> a real door — not resolve() called on an
	// already-normalized literal, which would not have caught this.

	/**
	 * @dataProvider malformedRoleProvider
	 */
	public function test_normalize_then_resolve_denies_a_malformed_role_through_a_real_door( $malformed_role ): void {
		$plain = 'mcpwp_' . str_repeat( 'b', 48 );
		update_option(
			'mcpwp_api_keys',
			array(
				array(
					'id'              => 'k-malformed-role',
					'label'           => 'malformed role',
					'hash'            => wp_hash_password( $plain ),
					'scopes'          => array( 'read', 'write' ),
					'role'            => $malformed_role,
					'tool_categories' => array( 'content' ),
					'created_at'      => '2026-01-01 00:00:00',
					'revoked_at'      => null,
				),
			)
		);

		$controller = new Mcpwp_REST_Action_Log();
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/action-log', array(), array( 'X-API-Key' => $plain ) );
		$result     = $controller->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result, 'role ' . var_export( $malformed_role, true ) . ' must not reach /action-log' );
		$this->assertSame( 'forbidden_category', $result->get_error_code() );
		// The denial message embeds "role: %s" — must never leak the internal
		// unknown_role_marker() sentinel (found live during round-2 review:
		// the message read "role: __mcpwp_unknown_role__").
		$this->assertStringNotContainsString( '__mcpwp_unknown_role__', $result->get_error_message() );
	}

	/**
	 * @dataProvider malformedRoleProvider
	 */
	public function test_normalize_then_resolve_denies_a_malformed_role_on_mcp_tools_list( $malformed_role ): void {
		$plain = 'mcpwp_' . str_repeat( 'c', 48 );
		update_option(
			'mcpwp_api_keys',
			array(
				array(
					'id'              => 'k-malformed-role-2',
					'label'           => 'malformed role',
					'hash'            => wp_hash_password( $plain ),
					'scopes'          => array( 'read', 'write' ),
					'role'            => $malformed_role,
					'tool_categories' => array( 'content' ),
					'created_at'      => '2026-01-01 00:00:00',
					'revoked_at'      => null,
				),
			)
		);

		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $plain ),
			array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' )
		);
		$this->assertTrue( $controller->verify_api_key( $request ) );

		$data  = $controller->handle_mcp( $request )->get_data();
		$tools = array_column( $data['result']['tools'], 'name' );

		// #1064: plus the two meta tools, allowed to every key BY NAME
		// (get_key_exempt_tool_names()) — they only surface and dispatch
		// what the key can already call.
		$discovery_names = array_merge( Mcpwp_1041_Auth_Harness::get_discovery_allowlist_tool_names_public(), Mcpwp_REST_MCP::META_TOOL_NAMES );
		$this->assertLessThan( 50, count( $tools ), 'a malformed-role key must not get the full tool catalog' );
		foreach ( $tools as $name ) {
			$this->assertContains( $name, $discovery_names );
		}
	}

	/**
	 * The MCP `initialize` response's `role`/`tool_categories` fields are
	 * client-facing, structured data — the same "never the raw marker"
	 * requirement as any denial message, but here it is a JSON field a
	 * program reads, not prose (#1041 WARN-1 round-2 adversarial review).
	 */
	public function test_initialize_never_reports_the_raw_unknown_role_marker(): void {
		$plain = 'mcpwp_' . str_repeat( 'e', 48 );
		update_option(
			'mcpwp_api_keys',
			array(
				array(
					'id'              => 'k-malformed-role-3',
					'label'           => 'malformed role',
					'hash'            => wp_hash_password( $plain ),
					'scopes'          => array( 'read', 'write' ),
					'role'            => 'viewer',
					'tool_categories' => array( 'content' ),
					'created_at'      => '2026-01-01 00:00:00',
					'revoked_at'      => null,
				),
			)
		);

		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $plain ),
			array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-03-26' ) )
		);
		$this->assertTrue( $controller->verify_api_key( $request ) );

		$data = $controller->handle_mcp( $request )->get_data();

		$this->assertSame( 'unknown', $data['result']['serverInfo']['role'] );
		$this->assertSame( array(), $data['result']['serverInfo']['tool_categories'] );
		$this->assertStringNotContainsString( '__mcpwp_unknown_role__', wp_json_encode( $data ) );
	}

	public function malformedRoleProvider(): array {
		return array(
			'viewer (typo/unknown role name)' => array( 'viewer' ),
			'empty string'                    => array( '' ),
			'null'                            => array( null ),
			'numeric'                         => array( 5 ),
		);
	}

	public function test_normalize_api_key_record_treats_absent_role_as_legacy_admin(): void {
		$auth       = $this->harness();
		$normalized = $auth->normalize( array( 'id' => 'legacy', 'hash' => 'h' ) );

		$this->assertSame( 'admin', $normalized['role'] );
		$this->assertSame( array(), $auth->resolve_categories( $normalized ) );
	}

	/**
	 * @dataProvider malformedRoleProvider
	 */
	public function test_normalize_api_key_record_treats_present_but_invalid_role_as_deny_all( $malformed_role ): void {
		$auth       = $this->harness();
		$normalized = $auth->normalize( array( 'id' => 'k', 'hash' => 'h', 'role' => $malformed_role, 'tool_categories' => array( 'content' ) ) );

		$this->assertNotSame( 'admin', $normalized['role'] );
		$this->assertTrue( Mcpwp_1041_Auth_Harness::is_deny_all_categories( $auth->resolve_categories( $normalized ) ) );
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

		// #1064: plus the two meta tools, allowed to every key BY NAME
		// (find returns nothing for a deny-all key; call re-runs every gate).
		$discovery_names = array_merge( Mcpwp_1041_Auth_Harness::get_discovery_allowlist_tool_names_public(), Mcpwp_REST_MCP::META_TOOL_NAMES );
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

	// ── #1041 NIT-1 (round-2 adversarial review of PR #1043) ────────────────
	//
	// list_scoped_api_keys() (GET /api-keys, wp_list_api_keys) used to return
	// the raw internal sentinel in 'tool_categories'. It must present the
	// resolved verdict instead: [] plus an explicit 'deny_all' flag.

	public function test_list_scoped_api_keys_presents_deny_all_not_the_raw_marker(): void {
		$this->seed_custom_key_with_empty_categories( 'k-list-deny-all' );

		$auth = $this->harness();
		$keys = $auth->list_scoped_api_keys();

		$this->assertCount( 1, $keys );
		$this->assertSame( array(), $keys[0]['tool_categories'] );
		$this->assertTrue( $keys[0]['deny_all'] );
		$this->assertStringNotContainsString( '__mcpwp_deny_all__', wp_json_encode( $keys ) );
	}

	public function test_list_scoped_api_keys_presents_unknown_role_not_the_raw_marker(): void {
		$plain = 'mcpwp_' . str_repeat( 'd', 48 );
		update_option(
			'mcpwp_api_keys',
			array(
				array(
					'id'              => 'k-unknown-role',
					'label'           => 'malformed role',
					'hash'            => wp_hash_password( $plain ),
					'scopes'          => array( 'read', 'write' ),
					'role'            => 'viewer',
					'tool_categories' => array( 'content' ),
					'created_at'      => '2026-01-01 00:00:00',
					'revoked_at'      => null,
				),
			)
		);

		$auth = $this->harness();
		$keys = $auth->list_scoped_api_keys();

		$this->assertSame( 'unknown', $keys[0]['role'] );
		$this->assertTrue( $keys[0]['deny_all'] );
		$this->assertStringNotContainsString( '__mcpwp_unknown_role__', wp_json_encode( $keys ) );
	}

	public function test_list_scoped_api_keys_does_not_flag_a_real_custom_key_as_deny_all(): void {
		$auth = $this->harness();
		$auth->create_scoped_api_key( 'real key', array( 'read', 'write' ), 'custom', array( 'seo' ) );

		$keys = $auth->list_scoped_api_keys();

		$this->assertSame( array( 'seo' ), $keys[0]['tool_categories'] );
		$this->assertFalse( $keys[0]['deny_all'] );
	}
}
