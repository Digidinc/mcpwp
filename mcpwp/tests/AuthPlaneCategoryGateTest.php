<?php
/**
 * Regression tests — the disabled-tool-category toggle must hold on EVERY
 * authentication path, not just the plain X-API-Key one.
 *
 * Before the fix:
 *   - authenticate_oauth_access_token() returned true without ever calling
 *     check_disabled_category_for_route(), so an OAuth bearer principal reached
 *     a disabled category by hitting the REST route directly.
 *   - verify_api_key_auth_only() (the batch / MCP sub-request fast path) did the
 *     same, so wrapping a blocked route in a /batch envelope laundered it.
 *
 * The plain API-key path and the broker path already gated; these tests lock all
 * of them so a future refactor cannot silently drop one.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// Needed for the public static flag that routes auth through
// verify_api_key_auth_only() — the batch / MCP sub-request path.
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

/** Harness exposing the auth trait. All methods used here are already public. */
class Mcpwp_Auth_Category_Gate_Harness {
	use Mcpwp_Api_Auth;

	/** Public proxy to the protected OAuth settings reader. */
	public function oauth_settings(): array {
		return $this->get_oauth_settings();
	}

	/** Public proxy to the protected route→category classifier. */
	public function category_for_route( string $route ): string {
		return $this->get_category_for_route( $route );
	}
}

final class AuthPlaneCategoryGateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_is_admin']     = false;
		$GLOBALS['mcpwp_test_filters']      = array();

		update_option(
			'mcpwp_settings',
			array(
				'oauth_enabled'   => true,
				'oauth_token_ttl' => 3600,
			)
		);
	}

	protected function tearDown(): void {
		// PHPUnit does not reset statics between tests; a mid-test failure must not
		// leak the batch context into the rest of the suite.
		if ( class_exists( 'Mcpwp_REST_Batch' ) ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
	}

	private function harness(): Mcpwp_Auth_Category_Gate_Harness {
		return new Mcpwp_Auth_Category_Gate_Harness();
	}

	private function request_with_key( string $key, string $method, string $route ): WP_REST_Request {
		return new WP_REST_Request( $method, $route, array(), array( 'X-API-Key' => $key ) );
	}

	private function assert_forbidden_category( $result, string $message ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $message );
		$this->assertSame( 'forbidden_category', $result->get_error_code(), $message );
		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status'] );
	}

	// ── Defect 1: OAuth bearer path ───────────────────────────────────────

	public function test_oauth_token_is_blocked_on_disabled_category_route(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'content' ) );

		$auth  = $this->harness();
		$token = $auth->issue_oauth_access_token( array( 'read', 'write', 'admin' ), 3600, array( 'user_id' => 7 ) )['access_token'];

		$result = $auth->verify_api_key( $this->request_with_key( $token, 'GET', '/mcpwp/v1/pages' ) );

		$this->assert_forbidden_category( $result, 'An OAuth principal must not reach a disabled category' );
	}

	public function test_oauth_token_allowed_on_route_of_a_different_category(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'media' ) );

		$auth  = $this->harness();
		$token = $auth->issue_oauth_access_token( array( 'read', 'write', 'admin' ), 3600, array( 'user_id' => 7 ) )['access_token'];

		$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $token, 'GET', '/mcpwp/v1/pages' ) ) );
	}

	public function test_oauth_token_allowed_when_no_category_is_disabled(): void {
		$auth  = $this->harness();
		$token = $auth->issue_oauth_access_token( array( 'read', 'write', 'admin' ), 3600, array( 'user_id' => 7 ) )['access_token'];

		$this->assertTrue( $auth->verify_api_key( $this->request_with_key( $token, 'GET', '/mcpwp/v1/pages' ) ) );
	}

	/**
	 * The gate must not fire before authentication: an unknown token on a disabled
	 * route must still be 401, never a 403 that reveals which categories are off.
	 */
	public function test_invalid_oauth_token_on_disabled_route_still_returns_401(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'content' ) );

		$result = $this->harness()->verify_api_key(
			$this->request_with_key( 'mcpwp_at_deadbeefdeadbeefdeadbeefdeadbeef', 'GET', '/mcpwp/v1/pages' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_api_key', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	// ── Defect 2: batch / MCP sub-request fast path ───────────────────────

	public function test_batch_sub_request_is_blocked_on_disabled_category_route(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'batch gate key', array( 'admin' ) );

		update_option( 'mcpwp_disabled_tool_categories', array( 'media' ) );

		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$result                                 = $auth->verify_api_key(
			$this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/media' )
		);
		Mcpwp_REST_Batch::$is_batch_sub_request = false;

		$this->assert_forbidden_category( $result, 'A batch envelope must not launder a disabled category' );
	}

	public function test_batch_sub_request_allowed_on_route_of_a_different_category(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'batch gate key', array( 'admin' ) );

		update_option( 'mcpwp_disabled_tool_categories', array( 'seo' ) );

		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		$result                                 = $auth->verify_api_key(
			$this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/media' )
		);
		Mcpwp_REST_Batch::$is_batch_sub_request = false;

		$this->assertTrue( $result );

		// The gate must be added AFTER the record is persisted, so downstream
		// identity checks (self-approval) still see the real requester.
		$record = $auth->get_current_api_key_record();
		$this->assertIsArray( $record, 'auth-only path must still populate the key record' );
		$this->assertSame( $created['id'], $record['id'] );
	}

	// ── Regression anchor: the two already-gated paths stay gated ─────────

	public function test_plain_api_key_path_is_still_blocked_on_disabled_category(): void {
		$auth    = $this->harness();
		$created = $auth->create_scoped_api_key( 'plain key', array( 'admin' ) );

		update_option( 'mcpwp_disabled_tool_categories', array( 'media' ) );

		$result = $auth->verify_api_key( $this->request_with_key( $created['key'], 'GET', '/mcpwp/v1/media' ) );

		$this->assert_forbidden_category( $result, 'The X-API-Key path must remain gated' );
	}

	// ── The new OAuth ceiling setting is read safely ──────────────────────

	public function test_oauth_settings_defaults_client_credentials_ceiling_to_write(): void {
		$this->assertSame( 'write', $this->harness()->oauth_settings()['oauth_client_credentials_max_scope'] );
	}

	public function test_oauth_settings_rejects_unknown_ceiling_value(): void {
		update_option(
			'mcpwp_settings',
			array(
				'oauth_enabled'                      => true,
				'oauth_client_credentials_max_scope' => 'superadmin',
			)
		);

		$this->assertSame( 'write', $this->harness()->oauth_settings()['oauth_client_credentials_max_scope'] );
	}

	public function test_oauth_settings_honours_an_explicit_admin_ceiling(): void {
		update_option(
			'mcpwp_settings',
			array(
				'oauth_enabled'                      => true,
				'oauth_client_credentials_max_scope' => 'admin',
			)
		);

		$this->assertSame( 'admin', $this->harness()->oauth_settings()['oauth_client_credentials_max_scope'] );
	}

	// ── AI-generation routes live under /integrations/* but are the ──
	// 'ai' tool-category, NOT integration management. The REST gate must classify
	// them 'ai' so it agrees with the MCP tool taxonomy; integration MANAGEMENT
	// (configure/test/status/remove) stays 'admin'.

	public function test_ai_generation_routes_classify_as_ai_not_admin(): void {
		$auth = $this->harness();

		$this->assertSame( 'ai', $auth->category_for_route( '/mcpwp/v1/integrations/generate-image' ) );
		$this->assertSame( 'ai', $auth->category_for_route( '/mcpwp/v1/integrations/text-to-speech' ) );
		$this->assertSame( 'ai', $auth->category_for_route( '/mcpwp/v1/integrations/stock-photos' ) );
	}

	public function test_integration_management_routes_stay_admin(): void {
		$auth = $this->harness();

		$this->assertSame( 'admin', $auth->category_for_route( '/mcpwp/v1/integrations/configure' ) );
		$this->assertSame( 'admin', $auth->category_for_route( '/mcpwp/v1/integrations/test' ) );
	}

	// ── The free /events endpoints classify as 'webhooks' at the REST ──
	// layer (one prefix cannot serve two taxonomies). Classification is deliberately
	// unchanged; the Pro 'events' category remains a first-class MCP-tool toggle.

	public function test_events_routes_classify_as_webhooks(): void {
		$auth = $this->harness();

		$this->assertSame( 'webhooks', $auth->category_for_route( '/mcpwp/v1/events' ) );
		$this->assertSame( 'webhooks', $auth->category_for_route( '/mcpwp/v1/events/schema' ) );
	}

	// ── Granular plugin/admin families that ARE toggleable in WP Admin > Tools ──
	// but whose REST routes previously classified as '' (ungated). Each REST prefix
	// must resolve to the SAME category as its dispatching MCP tool, so disabling the
	// category in the admin UI actually blocks the raw route too.

	public function test_granular_family_routes_classify_to_their_tool_category(): void {
		$auth = $this->harness();

		$this->assertSame( 'woocommerce', $auth->category_for_route( '/mcpwp/v1/woocommerce/orders/123' ) );
		$this->assertSame( 'learnpress', $auth->category_for_route( '/mcpwp/v1/learnpress/courses' ) );
		$this->assertSame( 'multisite', $auth->category_for_route( '/mcpwp/v1/network/switch' ) );
		$this->assertSame( 'staging', $auth->category_for_route( '/mcpwp/v1/staging/push' ) );
		$this->assertSame( 'elementor-theme', $auth->category_for_route( '/mcpwp/v1/theme-builder/templates' ) );
		$this->assertSame( 'forms', $auth->category_for_route( '/mcpwp/v1/forms' ) );
		$this->assertSame( 'seo', $auth->category_for_route( '/mcpwp/v1/keyword-research' ) );
		$this->assertSame( 'content', $auth->category_for_route( '/mcpwp/v1/search' ) );
		$this->assertSame( 'gutenberg', $auth->category_for_route( '/mcpwp/v1/block-types' ) );
		$this->assertSame( 'gutenberg', $auth->category_for_route( '/mcpwp/v1/block-patterns' ) );
		$this->assertSame( 'site', $auth->category_for_route( '/mcpwp/v1/figma/file' ) );
		$this->assertSame( 'site', $auth->category_for_route( '/mcpwp/v1/templates/page' ) );
	}

	/**
	 * The template pattern must claim ONLY the numeric-id route (/pages/5/template =>
	 * 'site'). A non-numeric segment such as /pages/by-slug/template is a content read
	 * and must fall through to /pages => 'content', not be mis-gated as 'site'.
	 */
	public function test_page_template_pattern_only_matches_numeric_ids(): void {
		$auth = $this->harness();

		$this->assertSame( 'site', $auth->category_for_route( '/mcpwp/v1/pages/5/template' ) );
		$this->assertSame( 'content', $auth->category_for_route( '/mcpwp/v1/pages/by-slug/template' ) );
	}
}
