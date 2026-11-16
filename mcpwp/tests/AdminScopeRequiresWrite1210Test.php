<?php
/**
 * #1210 — admin scope requires write scope.
 *
 * Creating or updating a key with admin but without write must refuse
 * (invalid_scopes). Stored admin-without-write records must not keep admin
 * at read time. The Setup form documents the rule and offers a Read-only
 * preset (JS and JS-free).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-key-scope-health.php';

/** Harness for scope create / normalize / key_has_scope. */
class Mcpwp_Admin_Requires_Write_1210_Harness {
	use Mcpwp_Api_Auth;

	/** @param array $scopes */
	public function scopes( $scopes ): array {
		return $this->sanitize_scopes( $scopes );
	}

	/** @param array $record */
	public function normalize( $record ): array {
		return $this->normalize_api_key_record( $record );
	}

	/** @param array $record */
	public function has_scope( $record, string $required ): bool {
		return $this->key_has_scope( $record, $required );
	}
}

final class AdminScopeRequiresWrite1210Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters']      = array();
	}

	private function harness(): Mcpwp_Admin_Requires_Write_1210_Harness {
		return new Mcpwp_Admin_Requires_Write_1210_Harness();
	}

	// ── Create refuses admin-without-write ───────────────────────────────────

	public function test_create_refuses_admin_without_write(): void {
		$created = $this->harness()->create_scoped_api_key( 'bad', array( 'read', 'admin' ), 'admin' );

		$this->assertSame( 'invalid_scopes', $created['error'] ?? null );
		$this->assertArrayNotHasKey( 'key', $created );
		$this->assertStringContainsString( 'admin', strtolower( (string) ( $created['message'] ?? '' ) ) );
		$this->assertStringContainsString( 'write', strtolower( (string) ( $created['message'] ?? '' ) ) );
	}

	public function test_create_refuses_bare_admin_without_write(): void {
		$created = $this->harness()->create_scoped_api_key( 'bare-admin', array( 'admin' ), 'admin' );

		$this->assertSame( 'invalid_scopes', $created['error'] ?? null );
		$this->assertArrayNotHasKey( 'key', $created );
	}

	public function test_create_accepts_admin_with_write(): void {
		$created = $this->harness()->create_scoped_api_key( 'ok', array( 'read', 'write', 'admin' ), 'admin' );

		$this->assertArrayNotHasKey( 'error', $created );
		$this->assertEqualsCanonicalizing( array( 'read', 'write', 'admin' ), $created['scopes'] );
	}

	public function test_create_custom_role_with_admin_checked_write_cleared_does_not_mint_write(): void {
		// The Setup form always posts hidden "read"; clearing Write while leaving
		// Admin ops checked used to expand to write via sanitize_scopes.
		$created = $this->harness()->create_scoped_api_key(
			'custom-readonly-attempt',
			array( 'read', 'admin' ),
			'custom',
			array( 'content' )
		);

		$this->assertSame( 'invalid_scopes', $created['error'] ?? null );
		$this->assertArrayNotHasKey( 'key', $created );
	}

	public function test_create_empty_scopes_still_defaults_to_role_ceiling(): void {
		$created = $this->harness()->create_scoped_api_key( 'ceiling', array(), 'admin' );

		$this->assertArrayNotHasKey( 'error', $created );
		$this->assertEqualsCanonicalizing( array( 'read', 'write', 'admin' ), $created['scopes'] );
	}

	public function test_create_read_only_explicit_is_unchanged(): void {
		$created = $this->harness()->create_scoped_api_key( 'reader', array( 'read' ), 'admin' );

		$this->assertArrayNotHasKey( 'error', $created );
		$this->assertSame( array( 'read' ), $created['scopes'] );
	}

	// ── REST create surfaces 400 invalid_scopes ──────────────────────────────

	public function test_rest_create_returns_400_invalid_scopes_for_admin_without_write(): void {
		$controller = new Mcpwp_REST_Site_Updates();
		$owner      = $controller->create_scoped_api_key( 'owner', array( 'read', 'write', 'admin' ), 'admin' );
		$auth       = new WP_REST_Request( 'GET', '/mcpwp/v1/api-keys', array(), array( 'X-API-Key' => $owner['key'] ) );
		$this->assertTrue( $controller->verify_api_key( $auth ) );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/api-keys',
			array(
				'label'  => 'bad',
				'role'   => 'admin',
				'scopes' => array( 'read', 'admin' ),
			)
		);

		$result = $controller->create_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_scopes', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// ── Legacy stored records: drop admin, never keep effective admin ────────

	public function test_sanitize_scopes_drops_admin_when_write_absent(): void {
		$auth = $this->harness();

		$this->assertSame( array( 'read' ), $auth->scopes( array( 'read', 'admin' ) ) );
		$this->assertSame( array( 'read' ), $auth->scopes( array( 'admin' ) ) );
		$this->assertEqualsCanonicalizing(
			array( 'read', 'write', 'admin' ),
			$auth->scopes( array( 'read', 'write', 'admin' ) )
		);
		$this->assertEqualsCanonicalizing(
			array( 'read', 'write', 'admin' ),
			$auth->scopes( array( 'write', 'admin' ) )
		);
	}

	public function test_sanitize_scopes_does_not_silently_add_write_for_admin(): void {
		$scopes = $this->harness()->scopes( array( 'read', 'admin' ) );

		$this->assertNotContains( 'write', $scopes );
		$this->assertNotContains( 'admin', $scopes );
	}

	public function test_normalize_legacy_admin_without_write_drops_admin(): void {
		$normalized = $this->harness()->normalize(
			array(
				'id'     => 'legacy',
				'label'  => 'Broken Admin Key',
				'hash'   => 'h',
				'role'   => 'admin',
				'scopes' => array( 'read', 'admin' ),
			)
		);

		$this->assertSame( array( 'read' ), $normalized['scopes'] );
		$this->assertFalse( $this->harness()->has_scope( $normalized, 'admin' ) );
		$this->assertFalse( $this->harness()->has_scope( $normalized, 'write' ) );
	}

	public function test_normalize_well_formed_admin_key_unchanged(): void {
		$normalized = $this->harness()->normalize(
			array(
				'id'     => 'ok',
				'hash'   => 'h',
				'role'   => 'admin',
				'scopes' => array( 'read', 'write', 'admin' ),
			)
		);

		$this->assertEqualsCanonicalizing( array( 'read', 'write', 'admin' ), $normalized['scopes'] );
		$this->assertTrue( $this->harness()->has_scope( $normalized, 'admin' ) );
	}

	public function test_existing_valid_write_key_unaffected(): void {
		$created = $this->harness()->create_scoped_api_key( 'writer', array( 'read', 'write' ), 'editor' );

		$this->assertArrayNotHasKey( 'error', $created );
		$this->assertEqualsCanonicalizing( array( 'read', 'write' ), $created['scopes'] );
	}

	public function test_legacy_admin_without_write_labels_are_listed_for_notice(): void {
		update_option(
			'mcpwp_api_keys',
			array(
				array(
					'id'         => 'bad1',
					'label'      => 'Reporting Bot',
					'hash'       => 'h1',
					'role'       => 'admin',
					'scopes'     => array( 'read', 'admin' ),
					'revoked_at' => null,
				),
				array(
					'id'         => 'ok1',
					'label'      => 'Full Admin',
					'hash'       => 'h2',
					'role'       => 'admin',
					'scopes'     => array( 'read', 'write', 'admin' ),
					'revoked_at' => null,
				),
				array(
					'id'         => 'bad2',
					'label'      => 'Old Inspect',
					'hash'       => 'h3',
					'role'       => 'admin',
					'scopes'     => array( 'admin' ),
					'revoked_at' => null,
				),
				array(
					'id'         => 'revoked-bad',
					'label'      => 'Revoked Bad',
					'hash'       => 'h4',
					'role'       => 'admin',
					'scopes'     => array( 'read', 'admin' ),
					'revoked_at' => '2026-01-01 00:00:00',
				),
			)
		);

		$labels = Mcpwp_Key_Scope_Health::labels_of_admin_without_write_keys();

		$this->assertEqualsCanonicalizing( array( 'Reporting Bot', 'Old Inspect' ), $labels );
		$this->assertNotContains( 'Full Admin', $labels );
		$this->assertNotContains( 'Revoked Bad', $labels );
	}

	// ── Setup UI: helper, Read-only preset, JS-free fallback ─────────────────

	public function test_setup_form_documents_admin_requires_write_and_readonly_preset(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/partials/mcpwp-setup-display.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'Admin ops requires Write', $source );
		$this->assertStringContainsString( 'mcpwp-readonly-key-preset', $source );
		$this->assertStringContainsString( 'Read-only', $source );
		// JS-free: a submit that forces read-only scopes server-side.
		$this->assertStringContainsString( 'mcpwp_create_readonly_scoped_key', $source );
	}

	public function test_setup_js_clears_and_disables_admin_when_write_unchecked(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/admin/js/mcpwp-admin.js' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'mcpwp-readonly-key-preset', $source );
		$this->assertMatchesRegularExpression( '/write.*(admin|Admin)|admin.*write/i', $source );
		$this->assertStringContainsString( 'disabled', $source );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_setup_partial_renders_escaped_readonly_preset(): void {
		require_once dirname( __DIR__ ) . '/tests/fixtures/setup-display-stubs-1098.php';
		$GLOBALS['mcpwp_test_options']['date_format'] = 'Y-m-d';
		$GLOBALS['mcpwp_test_options']['time_format'] = 'H:i';
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = array();

		ob_start();
		try {
			mcpwp_1098_render_setup_partial( array(), null );
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertStringContainsString( 'mcpwp-readonly-key-preset', $html );
		$this->assertStringContainsString( esc_html( 'Read-only' ), $html );
		$this->assertStringContainsString( 'Admin ops requires Write', $html );
		$this->assertStringContainsString( 'mcpwp_create_readonly_scoped_key', $html );
	}
}
