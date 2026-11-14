<?php
/**
 * Regression tests — REST/MCP authorization scope matrix.
 *
 * Phase 2 authorization hardening: every admin-only surface must resolve to 'admin' scope at the
 * auth layer, GET discovery routes must stay 'read', and the boundary-aware
 * prefix match must stop '/content' from over-matching '/content-graph'.
 *
 * Test approach: same harness pattern as StagingScopeTest — call the auth
 * trait's protected scope resolvers directly through public wrappers.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/**
 * Harness exposing the auth trait's protected scope resolvers.
 *
 * Distinct class name from Mcpwp_Staging_Scope_Auth_Harness because both test
 * files run in the same PHPUnit process.
 */
class Mcpwp_Scope_Matrix_Auth_Harness {
	use Mcpwp_Api_Auth;

	/** Resolve required scope for a method + route. */
	public function scope( string $method, string $route ): string {
		return $this->get_required_scope_for_request( new WP_REST_Request( $method, $route ) );
	}

	/** Resolve required scope for an MCP tool name. */
	public function tool_scope( string $tool ): string {
		return $this->get_required_scope_for_tool_name( $tool );
	}
}

final class AdminScopeMatrixTest extends TestCase {

	private Mcpwp_Scope_Matrix_Auth_Harness $h;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$this->h = new Mcpwp_Scope_Matrix_Auth_Harness();
	}

	// ── Users: blanket admin ──────────────────────────────

	public function test_users_are_blanket_admin(): void {
		$this->assertSame( 'admin', $this->h->scope( 'GET', '/mcpwp/v1/users' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/users' ) );
		$this->assertSame( 'admin', $this->h->scope( 'GET', '/mcpwp/v1/users/5' ) );
		$this->assertSame( 'admin', $this->h->scope( 'PUT', '/mcpwp/v1/users/5' ) );
		$this->assertSame( 'admin', $this->h->scope( 'DELETE', '/mcpwp/v1/users/5' ) );
		$this->assertSame( 'admin', $this->h->scope( 'GET', '/mcpwp/v1/users/roles' ) );
		$this->assertSame( 'admin', $this->h->scope( 'GET', '/mcpwp/v1/users/5/capabilities' ) );
	}

	// ── Approvals: transitions admin, list/get read ──────────

	public function test_approvals_transitions_admin_reads_stay_read(): void {
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/approvals' ) );
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/approvals/apr_x' ) );

		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/approvals/apr_x/approve' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/approvals/apr_x/reject' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/approvals/apr_x/apply' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/approvals/apr_x/rollback' ) );
	}

	// ── Integrations: credential ops admin, discovery read ───

	public function test_integrations_split(): void {
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/integrations/status' ) );
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/integrations/stock-photos' ) );
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/integrations/providers' ) );

		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/integrations/configure' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/integrations/remove' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/integrations/test' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/integrations/providers/openai/key' ) );
		$this->assertSame( 'admin', $this->h->scope( 'DELETE', '/mcpwp/v1/integrations/providers/openai/key' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/integrations/providers/openai/test' ) );

		// Content generation consumes an already-configured key — stays write.
		$this->assertSame( 'write', $this->h->scope( 'POST', '/mcpwp/v1/integrations/generate-image' ) );
		$this->assertSame( 'write', $this->h->scope( 'POST', '/mcpwp/v1/integrations/text-to-speech' ) );
		$this->assertSame( 'write', $this->h->scope( 'POST', '/mcpwp/v1/integrations/stock-photos/download' ) );
	}

	// ── content-graph over-match regression ───────────────────

	public function test_content_graph_not_over_matched_by_content(): void {
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/content-graph' ) );
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/content-graph/suggestions' ) );
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/content-graph/validate-links' ) );
		$this->assertSame( 'write', $this->h->scope( 'POST', '/mcpwp/v1/content-graph/apply-link' ) );

		// /content itself stays blanket admin.
		$this->assertSame( 'admin', $this->h->scope( 'GET', '/mcpwp/v1/content' ) );
		$this->assertSame( 'admin', $this->h->scope( 'DELETE', '/mcpwp/v1/content/product/5' ) );
	}

	// ── Custom CSS + theme: admin-on-write ────────────────

	public function test_custom_css_and_theme_admin_on_write(): void {
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/custom-css' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/custom-css' ) );
		$this->assertSame( 'admin', $this->h->scope( 'DELETE', '/mcpwp/v1/custom-css' ) );
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/custom-css/length' ) );

		// /theme (singular): both mutating sub-routes gated, GETs stay read.
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/theme/mods' ) );
		$this->assertSame( 'admin', $this->h->scope( 'PUT', '/mcpwp/v1/theme/mods' ) );
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/theme/custom-css' ) );
		$this->assertSame( 'admin', $this->h->scope( 'PUT', '/mcpwp/v1/theme/custom-css' ) );
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/theme/global-styles' ) );
	}

	// ── Themes (plural, pro): admin-on-write ──────────────────

	public function test_themes_plural_admin_on_write(): void {
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/themes/detect' ) );
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/themes/settings' ) );
		$this->assertSame( 'admin', $this->h->scope( 'PUT', '/mcpwp/v1/themes/settings' ) );
		$this->assertSame( 'admin', $this->h->scope( 'PUT', '/mcpwp/v1/themes/astra/colors' ) );
		$this->assertSame( 'admin', $this->h->scope( 'PUT', '/mcpwp/v1/themes/astra/typography' ) );
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/themes/astra/header' ) );
	}

	// ── Action-log: rollback admin, log reads stay read ───────

	public function test_action_log_admin_on_write(): void {
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/action-log' ) );
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/action-log/export' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/action-log/abc123/rollback' ) );
	}

	// ── Site-blueprints: deploy, create and delete admin; reads stay read ──
	// #1100 phase 1 (was: create/delete at write scope): a stored blueprint
	// is site-wide configuration, manage_options in wp-admin.

	public function test_site_blueprint_deploy_is_admin(): void {
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/site-blueprints' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/site-blueprints' ) );
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/site-blueprints/extract' ) );
		$this->assertSame( 'admin', $this->h->scope( 'DELETE', '/mcpwp/v1/site-blueprints/bp_1' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/site-blueprints/bp_1/deploy' ) );
	}

	// ── Behavior-preserving refactor locks ───────────────────────────────

	public function test_rate_limit_admin_on_write_preserved(): void {
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/rate-limit' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/rate-limit/reset' ) );
	}

	public function test_option_singular_and_plural_stay_admin(): void {
		$this->assertSame( 'admin', $this->h->scope( 'GET', '/mcpwp/v1/option' ) );
		$this->assertSame( 'admin', $this->h->scope( 'GET', '/mcpwp/v1/options' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/option' ) );
	}

	public function test_existing_admin_routes_still_admin(): void {
		$this->assertSame( 'admin', $this->h->scope( 'GET', '/mcpwp/v1/settings' ) );
		$this->assertSame( 'admin', $this->h->scope( 'GET', '/mcpwp/v1/menus' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/webhooks' ) );
		$this->assertSame( 'admin', $this->h->scope( 'GET', '/mcpwp/v1/api-keys' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/staging/push' ) );
		$this->assertSame( 'admin', $this->h->scope( 'GET', '/mcpwp/v1/elementor/custom-code' ) );
		$this->assertSame( 'admin', $this->h->scope( 'POST', '/mcpwp/v1/update' ) );
		$this->assertSame( 'admin', $this->h->scope( 'PUT', '/mcpwp/v1/plugin-settings' ) );
	}

	public function test_ordinary_content_routes_default(): void {
		// Regular content editing stays at read/write, unaffected by the matrix.
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/posts' ) );
		$this->assertSame( 'write', $this->h->scope( 'POST', '/mcpwp/v1/posts' ) );
		$this->assertSame( 'read', $this->h->scope( 'GET', '/mcpwp/v1/pages/12' ) );
		$this->assertSame( 'write', $this->h->scope( 'PUT', '/mcpwp/v1/pages/12' ) );
	}

	// ── MCP tool-name mirror ─────────────────────────────

	public function test_admin_tools_mirror_rest(): void {
		foreach ( array( 'wp_approve_request', 'wp_reject_request', 'wp_apply_approval', 'wp_rollback_approval' ) as $t ) {
			$this->assertSame( 'admin', $this->h->tool_scope( $t ), $t );
		}
		foreach ( array( 'wp_configure_integration', 'wp_remove_integration', 'wp_test_integration' ) as $t ) {
			$this->assertSame( 'admin', $this->h->tool_scope( $t ), $t );
		}
		$this->assertSame( 'admin', $this->h->tool_scope( 'wp_set_custom_css' ) );
		$this->assertSame( 'admin', $this->h->tool_scope( 'wp_delete_custom_css' ) );
		$this->assertSame( 'admin', $this->h->tool_scope( 'wp_deploy_site_blueprint' ) );
		$this->assertSame( 'admin', $this->h->tool_scope( 'wp_get_option' ) );
		$this->assertSame( 'admin', $this->h->tool_scope( 'wp_update_option' ) );
		$this->assertSame( 'admin', $this->h->tool_scope( 'wp_get_plugin_settings' ) );
	}

	public function test_read_ish_tools_stay_non_admin(): void {
		$this->assertSame( 'read', $this->h->tool_scope( 'wp_integrations_status' ) );
		$this->assertSame( 'read', $this->h->tool_scope( 'wp_list_approvals' ) );
		$this->assertSame( 'read', $this->h->tool_scope( 'wp_get_approval' ) );
		$this->assertSame( 'read', $this->h->tool_scope( 'wp_get_custom_css' ) );
		$this->assertSame( 'read', $this->h->tool_scope( 'wp_get_css_length' ) );
	}
}
