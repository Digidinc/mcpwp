<?php
/**
 * #1210 — admin scope requires write scope.
 *
 * Creating a key with admin but without write must refuse (invalid_scopes).
 * Stored admin-without-write records must not keep admin at read time. The
 * Setup form documents the rule and offers a Read-only preset (JS and JS-free).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-key-scope-health.php';
require_once dirname( __DIR__ ) . '/includes/admin/traits/trait-mcpwp-admin-setup.php';

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

/**
 * Admin Setup create-key POST surface (#1210), without the full page render.
 */
class Mcpwp_Admin_Requires_Write_1210_Setup_Harness {
	use Mcpwp_Api_Auth;
	use Mcpwp_Admin_Setup_Trait;
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
		$this->assertStringContainsString( 'together', strtolower( (string) ( $created['message'] ?? '' ) ) );
		$this->assertStringNotContainsString( 'omit scopes', strtolower( (string) ( $created['message'] ?? '' ) ) );
		$this->assertStringNotContainsString( 'role ceiling', strtolower( (string) ( $created['message'] ?? '' ) ) );
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
		$this->assertStringContainsString( 'function syncAdminWithWrite()', $source );
		$this->assertStringContainsString( 'if (!writeCb.checked) {', $source );
		$this->assertStringContainsString( 'adminCb.checked = false;', $source );
		$this->assertStringContainsString( 'adminCb.disabled = true;', $source );
		$this->assertStringContainsString( 'adminCb.disabled = false;', $source );
		$this->assertStringContainsString( "writeCb.addEventListener('change', syncAdminWithWrite);", $source );
	}

	public function test_wp_create_api_key_scopes_description_says_admin_requires_write(): void {
		$tool = null;
		foreach ( ( new Mcpwp_MCP_Free_Tools() )->get_tools() as $candidate ) {
			if ( 'wp_create_api_key' === $candidate['name'] ) {
				$tool = $candidate;
				break;
			}
		}
		$this->assertNotNull( $tool );
		$desc = $tool['inputSchema']['properties']['scopes']['description'] ?? '';
		$this->assertStringContainsString( 'Admin requires write', $desc );
		$this->assertLessThanOrEqual(
			Mcpwp_MCP_Tool_List_Diet::PROPERTY_DESCRIPTION_MAX_CHARS,
			mb_strlen( $desc )
		);
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

	// ── Admin POST handler + notice rendering (#1210 P2) ─────────────────────

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_admin_readonly_button_forces_read_even_when_post_carries_other_scopes(): void {
		$this->install_admin_post_stubs();

		$_POST = array(
			'mcpwp_create_readonly_scoped_key' => '1',
			'mcpwp_scoped_keys_nonce'          => 'test-nonce',
			'mcpwp_scoped_key_label'           => 'Forced Read',
			'mcpwp_scoped_key_role'            => 'admin',
			'mcpwp_scoped_key_scopes'          => array( 'read', 'write', 'admin' ),
		);

		$created = ( new Mcpwp_Admin_Requires_Write_1210_Setup_Harness() )->process_create_scoped_key_post();

		$this->assertIsArray( $created );
		$this->assertArrayNotHasKey( 'error', $created );
		$this->assertSame( array( 'read' ), $created['scopes'] );
		$this->assertSame( 'mcpwp_scoped_key_created', $GLOBALS['mcpwp_test_settings_errors'][0]['code'] ?? null );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_admin_post_surfaces_invalid_scopes_when_write_cleared_admin_checked(): void {
		$this->install_admin_post_stubs();

		$_POST = array(
			'mcpwp_create_scoped_key' => '1',
			'mcpwp_scoped_keys_nonce' => 'test-nonce',
			'mcpwp_scoped_key_label'  => 'Bad Combo',
			'mcpwp_scoped_key_role'   => 'custom',
			'mcpwp_scoped_key_scopes' => array( 'read', 'admin' ),
			'mcpwp_scoped_key_categories' => array( 'content' ),
		);

		$created = ( new Mcpwp_Admin_Requires_Write_1210_Setup_Harness() )->process_create_scoped_key_post();

		$this->assertNull( $created );
		$this->assertSame( 'mcpwp_scoped_key_error', $GLOBALS['mcpwp_test_settings_errors'][0]['code'] ?? null );
		$this->assertSame( 'error', $GLOBALS['mcpwp_test_settings_errors'][0]['type'] ?? null );
		$this->assertStringContainsString( 'write', strtolower( (string) ( $GLOBALS['mcpwp_test_settings_errors'][0]['message'] ?? '' ) ) );
		$this->assertSame( array(), get_option( 'mcpwp_api_keys', array() ) );
	}

	public function test_render_notice_hidden_from_non_manage_options_users(): void {
		update_option(
			'mcpwp_api_keys',
			array(
				array(
					'id'         => 'bad',
					'label'      => 'Legacy Admin',
					'hash'       => 'h',
					'role'       => 'admin',
					'scopes'     => array( 'read', 'admin' ),
					'revoked_at' => null,
				),
			)
		);

		$GLOBALS['mcpwp_test_current_user'] = 0;
		ob_start();
		Mcpwp_Key_Scope_Health::render_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_render_notice_escapes_hostile_label_and_skips_revoked(): void {
		update_option(
			'mcpwp_api_keys',
			array(
				array(
					'id'         => 'xss',
					'label'      => 'Evil <script>alert(1)</script> & "quoted"',
					'hash'       => 'h1',
					'role'       => 'admin',
					'scopes'     => array( 'read', 'admin' ),
					'revoked_at' => null,
				),
				array(
					'id'         => 'revoked-bad',
					'label'      => 'Revoked Bad',
					'hash'       => 'h2',
					'role'       => 'admin',
					'scopes'     => array( 'read', 'admin' ),
					'revoked_at' => '2026-01-01 00:00:00',
				),
			)
		);

		$GLOBALS['mcpwp_test_current_user'] = 1;
		ob_start();
		Mcpwp_Key_Scope_Health::render_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'Revoked Bad', $html );
		$this->assertStringContainsString( '&amp;', $html );
		$this->assertStringContainsString( '&quot;quoted&quot;', $html );
	}

	/**
	 * Stubs for Setup create-key POST tests (separate process only).
	 *
	 * @return void
	 */
	private function install_admin_post_stubs(): void {
		$GLOBALS['mcpwp_test_settings_errors'] = array();
		$GLOBALS['mcpwp_test_options']         = array();
		$GLOBALS['mcpwp_test_transients']      = array();
		$GLOBALS['mcpwp_test_current_user']    = 1;

		if ( ! function_exists( 'check_admin_referer' ) ) {
			function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
				return true;
			}
		}
		if ( ! function_exists( 'add_settings_error' ) ) {
			function add_settings_error( $setting, $code, $message, $type = 'error' ) {
				$GLOBALS['mcpwp_test_settings_errors'][] = array(
					'setting' => $setting,
					'code'    => $code,
					'message' => $message,
					'type'    => $type,
				);
			}
		}
		if ( ! function_exists( 'wp_die' ) ) {
			function wp_die( $message = '', $title = '', $args = array() ) {
				throw new RuntimeException( is_string( $message ) ? $message : 'wp_die' );
			}
		}
	}
}
