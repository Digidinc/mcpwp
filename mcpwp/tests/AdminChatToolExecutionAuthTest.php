<?php

use PHPUnit\Framework\TestCase;

/**
 * #607 invariant 2 — is_trusted_admin_ajax_request() must actually match the
 * real admin-Chat tool-execution request, not stale action/nonce strings.
 *
 * Before this fix, is_trusted_admin_ajax_request() checked $_POST['action']
 * against array('mcpwp_admin_chat', 'mcpwp_chat_tool_call') and the nonce
 * against action 'mcpwp_admin_chat'. Neither string is registered or minted
 * anywhere in the plugin:
 *
 *   - includes/class-mcpwp-loader.php registers
 *     add_action('wp_ajax_mcpwp_chat_execute_tool', ...) — so a real request
 *     for this feature carries $_POST['action'] === 'mcpwp_chat_execute_tool'.
 *   - includes/admin/class-mcpwp-admin.php mints the nonce with
 *     wp_create_nonce('mcpwp_admin_nonce'), and every
 *     check_ajax_referer(...) call in trait-mcpwp-admin-chat.php verifies
 *     against that same 'mcpwp_admin_nonce' action.
 *
 * So the gate could never pass for a genuine request: an authenticated
 * site admin clicking "run" on a tool suggested by the Chat tab always hit
 * verify_api_key()'s missing-API-key rejection, because
 * ajax_chat_execute_tool() internally rest_do_request()s the /mcp route
 * with no API key and relied entirely on this bypass. These tests exercise
 * verify_api_key() (what the /mcp route's permission_callback actually
 * calls) end-to-end against the REAL production strings — not a stand-in —
 * so a future edit that drifts the allowlist or the nonce action away from
 * what class-mcpwp-loader.php / class-mcpwp-admin.php actually use fails
 * loudly here.
 *
 * @package MCPWP
 */

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-api.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-loader.php';

final class AdminChatToolExecutionAuthTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_is_admin']                 = false;
		$GLOBALS['mcpwp_test_doing_ajax']                = false;
		$GLOBALS['mcpwp_test_ajax_referer_valid']        = false;
		$GLOBALS['mcpwp_test_last_ajax_referer_action']  = null;
		$GLOBALS['mcpwp_test_current_user']              = 0;
		$GLOBALS['mcpwp_test_user_caps']                 = array();
		$_POST                                           = array();

		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	private function controller() {
		return new class extends Mcpwp_REST_API {
			public function register_routes() {}
		};
	}

	private function grantAdmin(): void {
		$GLOBALS['mcpwp_test_is_admin']           = true;
		$GLOBALS['mcpwp_test_current_user']        = 2;
		$GLOBALS['mcpwp_test_user_caps'][2]        = array( 'activate_plugins' => true );
		$GLOBALS['mcpwp_test_ajax_referer_valid']  = true;
	}

	/**
	 * The literal action string class-mcpwp-loader.php registers the
	 * internal-tool-execution AJAX handler under. If this ever changes, the
	 * allowlist in is_trusted_admin_ajax_request() must change with it —
	 * this constant is the single source of truth both this test and that
	 * allowlist are meant to agree on.
	 */
	private const REAL_TOOL_EXEC_ACTION = 'mcpwp_chat_execute_tool';

	/**
	 * The literal nonce action wp_create_nonce() mints for the admin JS
	 * bundle (see includes/admin/class-mcpwp-admin.php) and every
	 * check_ajax_referer() call in trait-mcpwp-admin-chat.php verifies
	 * against.
	 */
	private const REAL_NONCE_ACTION = 'mcpwp_admin_nonce';

	public function test_the_real_registered_ajax_action_is_mcpwp_chat_execute_tool(): void {
		// Guards the fixture constants above against the production wiring
		// drifting without this test noticing. has_action() is not stubbed
		// in this suite's bootstrap, so assert directly against source.
		$loader_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mcpwp-loader.php' );
		$this->assertStringContainsString(
			"'wp_ajax_" . self::REAL_TOOL_EXEC_ACTION . "'",
			$loader_source,
			'Fixture drifted: class-mcpwp-loader.php no longer registers the action this test exercises.'
		);

		$admin_source = file_get_contents( dirname( __DIR__ ) . '/includes/admin/class-mcpwp-admin.php' );
		$this->assertStringContainsString(
			"wp_create_nonce( '" . self::REAL_NONCE_ACTION . "' )",
			$admin_source,
			'Fixture drifted: class-mcpwp-admin.php no longer mints the nonce action this test exercises.'
		);
	}

	public function test_bypass_succeeds_for_the_real_action_and_nonce(): void {
		$this->grantAdmin();
		$_POST['action'] = self::REAL_TOOL_EXEC_ACTION;

		$auth    = $this->controller();
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' );
		$result  = $auth->verify_api_key( $request );

		$this->assertSame(
			true,
			$result,
			'An authenticated admin issuing the real mcpwp_chat_execute_tool AJAX action with a valid nonce must be able to reach /mcp with no API key.'
		);
		$this->assertSame(
			self::REAL_NONCE_ACTION,
			$GLOBALS['mcpwp_test_last_ajax_referer_action'],
			'is_trusted_admin_ajax_request() must verify the nonce against the action it is actually minted for.'
		);
	}

	public function test_bypass_still_rejects_the_stale_pre_fix_action_names(): void {
		$this->grantAdmin();

		foreach ( array( 'mcpwp_admin_chat', 'mcpwp_chat_tool_call' ) as $stale_action ) {
			$_POST['action'] = $stale_action;

			$auth    = $this->controller();
			$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' );
			$result  = $auth->verify_api_key( $request );

			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				"'{$stale_action}' was never a real registered AJAX action; it must not be honoured even though it used to appear in the allowlist."
			);
		}
	}

	public function test_bypass_rejects_an_unrelated_admin_ajax_action(): void {
		$this->grantAdmin();
		// A real, currently-registered MCPWP admin AJAX action — but not the
		// one that dispatches into /mcp, so it must not gain a bypass either.
		$_POST['action'] = 'mcpwp_toggle_tool_category';

		$auth    = $this->controller();
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' );
		$result  = $auth->verify_api_key( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
