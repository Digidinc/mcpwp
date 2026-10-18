<?php
/**
 * PR-2: OAuth on by default, scope defaults, consent-screen scope choice,
 * the one-time admin notice and the Setup → Advanced switch.
 *
 * Decisions covered: D8 (never admin), D9 (no scope = read write, clamped;
 * write can be unticked; a tampered POST never widens), D10 (absent = on,
 * stored value respected; notice + switch), D11/D14 (no 'legacy' conflict).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-clients.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-settings.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-oauth-local-admin.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-chatgpt-connector.php';

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return 0 !== (int) $GLOBALS['mcpwp_test_current_user'];
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return (object) array( 'ID' => (int) $GLOBALS['mcpwp_test_current_user'], 'user_login' => 'testadmin' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( (string) $url, FILTER_SANITIZE_URL );
	}
}

final class OAuthDefaultOnTest extends TestCase {

	const REDIRECT = 'https://client.example.com/callback';
	const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_user_meta']    = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_user_meta']    = array();
	}

	private function controller(): Mcpwp_REST_OAuth {
		return new Mcpwp_REST_OAuth();
	}

	private function legacy_client_settings( array $extra = array() ): void {
		update_option(
			'mcpwp_settings',
			array_merge(
				array(
					'oauth_client_id'     => 'test_client',
					'oauth_redirect_uris' => array( self::REDIRECT ),
				),
				$extra
			)
		);
	}

	private function login( int $user_id, array $caps ): void {
		$GLOBALS['mcpwp_test_current_user']          = $user_id;
		$GLOBALS['mcpwp_test_user_caps'][ $user_id ] = $caps;
	}

	private function params( string $scope ): array {
		return array(
			'response_type'         => 'code',
			'client_id'             => 'test_client',
			'redirect_uri'          => self::REDIRECT,
			'code_challenge'        => $this->controller()->compute_pkce_challenge( self::VERIFIER ),
			'code_challenge_method' => 'S256',
			'scope'                 => $scope,
			'state'                 => 'st',
		);
	}

	/** Render consent and return [html, hidden fields]. */
	private function consent( string $scope ): array {
		$html = (string) $this->controller()->handle_authorize_get(
			new WP_REST_Request( 'GET', '/mcpwp/v1/oauth/authorize', $this->params( $scope ) )
		)->get_data();

		preg_match_all( '/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $m, PREG_SET_ORDER );
		$fields = array();
		foreach ( $m as $row ) {
			$fields[ $row[1] ] = html_entity_decode( $row[2], ENT_QUOTES, 'UTF-8' );
		}
		return array( $html, $fields );
	}

	private function post( array $fields ) {
		return $this->controller()->handle_authorize_post( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/authorize', $fields ) );
	}

	private function code_scope( $response ): string {
		$this->assertSame( 302, $response->get_status() );
		parse_str( (string) wp_parse_url( $response->get_headers()['Location'], PHP_URL_QUERY ), $query );
		$this->assertArrayHasKey( 'code', $query );
		return get_transient( 'mcpwp_oauth_code_' . $query['code'] )['scope'];
	}

	private function codes_minted(): int {
		return count( array_filter( array_keys( $GLOBALS['mcpwp_test_transients'] ), function ( $k ) {
			return 0 === strpos( $k, 'mcpwp_oauth_code_' );
		} ) );
	}

	// ── D10: default on ────────────────────────────────────────────────────

	public function test_absent_setting_means_on_everywhere(): void {
		$this->legacy_client_settings(); // no oauth_enabled key at all

		$this->assertTrue( Mcpwp_OAuth_Well_Known::is_legacy_oauth_enabled() );
		$this->assertTrue( Mcpwp_OAuth_Well_Known::oauth_enabled_by_default() );
		$this->assertTrue( Mcpwp_OAuth_Well_Known::should_serve_protected_resource_metadata() );

		$token = $this->controller()->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'bogus' ) ) );
		$this->assertSame( 400, $token->get_status(), 'Token endpoint is live (not the 501 "disabled" answer).' );

		$auth  = new Mcpwp_Api_Auth_Test_Harness();
		$issued = $auth->issue_oauth_access_token( array( 'read' ), 3600, array( 'user_id' => 7 ) );
		$this->assertTrue( $auth->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $issued['access_token'] ) ) ) );

		$www = Mcpwp_OAuth_Well_Known::add_www_authenticate_header( new WP_REST_Response( array(), 401 ), null, new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' ) );
		$this->assertArrayHasKey( 'WWW-Authenticate', $www->get_headers() );
	}

	public function test_no_settings_option_at_all_means_on(): void {
		$this->assertTrue( Mcpwp_OAuth_Well_Known::is_legacy_oauth_enabled() );
		$this->assertTrue( Mcpwp_OAuth_Well_Known::resolve_oauth_enabled( 'corrupt-non-array' ) );
	}

	public function test_stored_off_is_respected_everywhere(): void {
		$this->legacy_client_settings( array( 'oauth_enabled' => false ) );

		$this->assertFalse( Mcpwp_OAuth_Well_Known::is_legacy_oauth_enabled() );
		$this->assertFalse( Mcpwp_OAuth_Well_Known::oauth_enabled_by_default() );
		$this->assertFalse( Mcpwp_OAuth_Well_Known::should_serve_protected_resource_metadata() );
		$this->assertSame( 501, $this->controller()->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'authorization_code' ) ) )->get_status() );
		$register = new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/register' );
		$this->assertSame( 501, $this->controller()->handle_register( $register )->get_status() );

		$auth  = new Mcpwp_Api_Auth_Test_Harness();
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		set_transient( 'mcpwp_oauth_token_' . md5( $token ), array( 'scopes' => array( 'read' ) ), 600 );
		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $token ) ) ) );
	}

	public function test_stored_on_is_on_but_not_by_default(): void {
		$this->legacy_client_settings( array( 'oauth_enabled' => true ) );
		$this->assertTrue( Mcpwp_OAuth_Well_Known::is_legacy_oauth_enabled() );
		$this->assertFalse( Mcpwp_OAuth_Well_Known::oauth_enabled_by_default() );
	}

	public function test_a_settings_write_without_the_box_keeps_absent_absent(): void {
		$settings = new Mcpwp_Settings();
		$out      = $settings->sanitize_settings( array( 'log_retention_days' => 12 ) );
		$this->assertArrayNotHasKey( 'oauth_enabled', $out, 'A write that does not carry the box must not freeze the default into a choice.' );

		$this->assertTrue( $settings->get_settings()['oauth_enabled'], 'The form shows the box checked when nothing is stored.' );

		$form = $settings->sanitize_settings( array( '__present' => array( 'oauth_enabled' ) ) );
		$this->assertFalse( $form['oauth_enabled'], 'An unticked box on the form is an explicit off.' );
	}

	// ── D9 / D8: scope defaults ────────────────────────────────────────────

	public function test_no_scope_means_read_write_for_an_editor(): void {
		$this->login( 7, array( 'edit_posts' => true ) );
		$this->assertSame( array( 'read', 'write' ), $this->controller()->clamp_scope_to_user_capability( '' ) );
		$this->assertSame( array( 'read', 'write' ), $this->controller()->clamp_scope_to_user_capability( '   ' ) );
	}

	public function test_no_scope_still_clamps_a_subscriber_to_read(): void {
		$this->login( 9, array() );
		$this->assertSame( array( 'read' ), $this->controller()->clamp_scope_to_user_capability( '' ) );
	}

	public function test_no_scope_for_an_administrator_is_write_never_admin(): void {
		$this->login( 1, array( 'edit_posts' => true, 'manage_options' => true ) );
		$this->assertSame( array( 'read', 'write' ), $this->controller()->clamp_scope_to_user_capability( '' ) );
		$this->assertSame( array( 'read', 'write' ), $this->controller()->clamp_scope_to_user_capability( 'read write admin' ) );
		$this->assertSame( array( 'read', 'write' ), $this->controller()->clamp_scope_array_to_user_id( array( 'read', 'write', 'admin' ), 1 ) );
	}

	public function test_admin_full_round_trip_mints_write_not_admin(): void {
		$this->legacy_client_settings();
		$this->login( 1, array( 'edit_posts' => true, 'manage_options' => true ) );

		list( , $fields )      = $this->consent( 'read write admin' );
		$fields['action']      = 'approve';
		$fields['scope_write'] = '1';
		$scope                 = $this->code_scope( $this->post( $fields ) );
		$this->assertSame( 'read write', $scope );
	}

	public function test_scope_unrecognised_only_stays_read(): void {
		$this->login( 7, array( 'edit_posts' => true ) );
		$this->assertSame( array( 'read' ), $this->controller()->clamp_scope_to_user_capability( 'openid profile' ) );
	}

	// ── D9 addition: consent-screen scope choice ──────────────────────────

	public function test_consent_shows_write_as_a_ticked_box_only_when_grantable(): void {
		$this->legacy_client_settings();
		$this->login( 7, array( 'edit_posts' => true ) );
		list( $html ) = $this->consent( '' );
		$this->assertStringContainsString( 'name="scope_write" value="1" checked', $html );
		$this->assertStringContainsString( '<input type="hidden" name="scope" value="read write">', $html, 'D9: no scope requested = read write.' );

		$this->login( 9, array() );
		list( $sub_html ) = $this->consent( '' );
		$this->assertStringNotContainsString( 'scope_write', $sub_html, 'A subscriber is not offered write.' );
	}

	public function test_ticked_write_mints_read_write(): void {
		$this->legacy_client_settings();
		$this->login( 7, array( 'edit_posts' => true ) );
		list( , $fields )      = $this->consent( '' );
		$fields['action']      = 'approve';
		$fields['scope_write'] = '1';

		$this->assertSame( 'read write', $this->code_scope( $this->post( $fields ) ) );
	}

	public function test_unticked_write_mints_read_only(): void {
		$this->legacy_client_settings();
		$this->login( 7, array( 'edit_posts' => true ) );
		list( , $fields ) = $this->consent( 'read write' );
		$fields['action'] = 'approve';
		// An unticked checkbox is simply absent from the POST.

		$this->assertSame( 'read', $this->code_scope( $this->post( $fields ) ) );
	}

	public function test_write_tick_cannot_add_write_to_a_read_request(): void {
		$this->legacy_client_settings();
		$this->login( 7, array( 'edit_posts' => true ) );
		list( , $fields )      = $this->consent( 'read' );
		$fields['action']      = 'approve';
		$fields['scope_write'] = '1'; // forged: the screen never offered it

		$this->assertSame( 'read', $this->code_scope( $this->post( $fields ) ) );
	}

	public function test_tampered_scope_field_is_refused_and_mints_nothing(): void {
		$this->legacy_client_settings();
		$this->login( 7, array( 'edit_posts' => true ) );
		list( , $fields )      = $this->consent( 'read' );
		$fields['action']      = 'approve';
		$fields['scope']       = 'read write admin'; // edited hidden field
		$fields['scope_write'] = '1';

		$response = $this->post( $fields );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_request', $response->get_data()['error'] );
		$this->assertArrayNotHasKey( 'Location', $response->get_headers() );
		$this->assertSame( 0, $this->codes_minted() );
	}

	public function test_every_bound_field_is_tamper_evident(): void {
		$this->legacy_client_settings( array( 'oauth_redirect_uris' => array( self::REDIRECT, 'https://client.example.com/other' ) ) );
		$this->login( 7, array( 'edit_posts' => true ) );
		list( , $fields ) = $this->consent( 'read write' );

		$tampers = array(
			'redirect_uri'   => 'https://client.example.com/other', // also on the allow-list
			'code_challenge' => $this->controller()->compute_pkce_challenge( 'attacker-verifier-attacker-verifier-0000' ),
			'state'          => 'other',
			'resource'       => 'https://example.com',
		);
		foreach ( $tampers as $field => $value ) {
			$post             = $fields;
			$post[ $field ]   = $value;
			$post['action']   = 'approve';
			$response         = $this->post( $post );
			$this->assertSame( 400, $response->get_status(), $field );
		}

		$missing = $fields;
		unset( $missing['_mcpwp_oauth_binding'] );
		$missing['action'] = 'approve';
		$this->assertSame( 400, $this->post( $missing )->get_status(), 'No binding, no code.' );

		// Another user cannot replay this user's form.
		$this->login( 8, array( 'edit_posts' => true ) );
		$other           = $fields;
		$other['action'] = 'approve';
		$this->assertSame( 400, $this->post( $other )->get_status() );

		$this->assertSame( 0, $this->codes_minted() );
	}

	// ── D10 addition: notice + switch ──────────────────────────────────────

	private function admin(): void {
		$this->login( 1, array( 'activate_plugins' => true, 'manage_options' => true, 'edit_posts' => true ) );
	}

	private function render_notice(): string {
		ob_start();
		Mcpwp_OAuth_Local_Admin::render_notice();
		return (string) ob_get_clean();
	}

	public function test_notice_shows_only_when_the_default_applied(): void {
		$this->admin();

		$this->legacy_client_settings();
		$this->assertTrue( Mcpwp_OAuth_Local_Admin::should_show_notice(), 'absent' );
		$this->assertStringContainsString( 'This update turned on sign-in for AI apps: AI apps can now connect by signing in with WordPress; turn off under Setup → Advanced.', $this->render_notice() );
		$this->assertStringContainsString( Mcpwp_OAuth_Local_Admin::DISMISS_ACTION, $this->render_notice() );

		$this->legacy_client_settings( array( 'oauth_enabled' => true ) );
		$this->assertFalse( Mcpwp_OAuth_Local_Admin::should_show_notice(), 'stored on' );
		$this->assertSame( '', $this->render_notice() );

		$this->legacy_client_settings( array( 'oauth_enabled' => false ) );
		$this->assertFalse( Mcpwp_OAuth_Local_Admin::should_show_notice(), 'stored off' );
	}

	public function test_notice_needs_activate_plugins(): void {
		$this->legacy_client_settings();
		$this->login( 7, array( 'edit_posts' => true, 'manage_options' => true ) );
		$this->assertFalse( Mcpwp_OAuth_Local_Admin::should_show_notice() );
		$this->assertSame( '', $this->render_notice() );
	}

	public function test_dismissal_needs_a_nonce_and_persists(): void {
		$this->legacy_client_settings();
		$this->admin();

		$this->assertFalse( Mcpwp_OAuth_Local_Admin::process_dismiss( array( Mcpwp_OAuth_Local_Admin::NONCE_FIELD => 'forged' ) ) );
		$this->assertTrue( Mcpwp_OAuth_Local_Admin::should_show_notice(), 'A bad nonce dismisses nothing.' );

		$this->assertTrue( Mcpwp_OAuth_Local_Admin::process_dismiss( array( Mcpwp_OAuth_Local_Admin::NONCE_FIELD => 'test-nonce' ) ) );
		$this->assertFalse( Mcpwp_OAuth_Local_Admin::should_show_notice() );
		$this->assertNotEmpty( get_user_meta( 1, Mcpwp_OAuth_Local_Admin::DISMISSED_META, true ), 'Stored, so it survives the next page view.' );

		// Per user: another administrator still sees it once.
		$this->login( 2, array( 'activate_plugins' => true ) );
		$this->assertTrue( Mcpwp_OAuth_Local_Admin::should_show_notice() );
	}

	public function test_dismissal_refused_without_capability(): void {
		$this->legacy_client_settings();
		$this->login( 7, array( 'edit_posts' => true ) );
		$this->assertFalse( Mcpwp_OAuth_Local_Admin::process_dismiss( array( Mcpwp_OAuth_Local_Admin::NONCE_FIELD => 'test-nonce' ) ) );
		$this->assertSame( '', get_user_meta( 7, Mcpwp_OAuth_Local_Admin::DISMISSED_META, true ) );
	}

	public function test_switch_off_stores_an_explicit_false_and_on_an_explicit_true(): void {
		$this->legacy_client_settings();
		$this->admin();

		$this->assertFalse( Mcpwp_OAuth_Local_Admin::process_toggle( array( Mcpwp_OAuth_Local_Admin::NONCE_FIELD => 'test-nonce', 'mcpwp_local_oauth' => '0' ) ) );
		$stored = get_option( 'mcpwp_settings' );
		$this->assertArrayHasKey( 'oauth_enabled', $stored );
		$this->assertFalse( $stored['oauth_enabled'] );
		$this->assertFalse( Mcpwp_OAuth_Well_Known::is_legacy_oauth_enabled() );
		$this->assertFalse( Mcpwp_OAuth_Local_Admin::should_show_notice(), 'An explicit choice ends the notice.' );
		$this->assertSame( array( 'https://client.example.com/callback' ), $stored['oauth_redirect_uris'], 'Other stored settings are kept.' );

		$this->assertTrue( Mcpwp_OAuth_Local_Admin::process_toggle( array( Mcpwp_OAuth_Local_Admin::NONCE_FIELD => 'test-nonce', 'mcpwp_local_oauth' => '1' ) ) );
		$this->assertTrue( get_option( 'mcpwp_settings' )['oauth_enabled'] );
	}

	public function test_switch_refused_without_nonce_or_capability(): void {
		$this->legacy_client_settings();
		$this->admin();
		$this->assertNull( Mcpwp_OAuth_Local_Admin::process_toggle( array( 'mcpwp_local_oauth' => '0' ) ) );

		$this->login( 7, array( 'edit_posts' => true, 'manage_options' => true ) );
		$this->assertNull( Mcpwp_OAuth_Local_Admin::process_toggle( array( Mcpwp_OAuth_Local_Admin::NONCE_FIELD => 'test-nonce', 'mcpwp_local_oauth' => '0' ) ) );

		$this->assertArrayNotHasKey( 'oauth_enabled', get_option( 'mcpwp_settings' ) );
	}

	// ── Round 2: D10 migration ─────────────────────────────────────────────

	public function test_migration_turns_a_stored_false_into_absent_once(): void {
		$this->legacy_client_settings( array( 'oauth_enabled' => false ) );

		$this->assertTrue( Mcpwp_OAuth_Local_Admin::migrate_default_on() );

		$stored = get_option( 'mcpwp_settings' );
		$this->assertArrayNotHasKey( 'oauth_enabled', $stored );
		$this->assertSame( 'test_client', $stored['oauth_client_id'], 'Other settings are kept.' );
		$this->assertTrue( Mcpwp_OAuth_Well_Known::is_legacy_oauth_enabled() );
		$this->assertNotEmpty( get_option( Mcpwp_OAuth_Local_Admin::MIGRATED_FLAG ) );

		$this->admin();
		$this->assertTrue( Mcpwp_OAuth_Local_Admin::should_show_notice(), 'A migrated site is told, once.' );
	}

	public function test_migration_leaves_a_stored_true_untouched(): void {
		$this->legacy_client_settings( array( 'oauth_enabled' => true ) );
		Mcpwp_OAuth_Local_Admin::migrate_default_on();
		$this->assertTrue( get_option( 'mcpwp_settings' )['oauth_enabled'] );
	}

	public function test_migration_leaves_absent_untouched(): void {
		$this->legacy_client_settings();
		$before = get_option( 'mcpwp_settings' );
		Mcpwp_OAuth_Local_Admin::migrate_default_on();
		$this->assertSame( $before, get_option( 'mcpwp_settings' ) );
		$this->assertNotEmpty( get_option( Mcpwp_OAuth_Local_Admin::MIGRATED_FLAG ) );
	}

	public function test_migration_flag_prevents_a_second_run(): void {
		update_option( Mcpwp_OAuth_Local_Admin::MIGRATED_FLAG, 1 );
		$this->legacy_client_settings( array( 'oauth_enabled' => false ) );

		$this->assertFalse( Mcpwp_OAuth_Local_Admin::migrate_default_on() );
		$this->assertFalse( get_option( 'mcpwp_settings' )['oauth_enabled'] );
	}

	public function test_a_site_switched_off_after_the_migration_stays_off(): void {
		$this->legacy_client_settings( array( 'oauth_enabled' => false ) );
		Mcpwp_OAuth_Local_Admin::migrate_default_on();

		$this->admin();
		Mcpwp_OAuth_Local_Admin::process_toggle( array( Mcpwp_OAuth_Local_Admin::NONCE_FIELD => 'test-nonce', 'mcpwp_local_oauth' => '0' ) );
		Mcpwp_OAuth_Local_Admin::migrate_default_on(); // next page load

		$this->assertFalse( get_option( 'mcpwp_settings' )['oauth_enabled'] );
		$this->assertFalse( Mcpwp_OAuth_Well_Known::is_legacy_oauth_enabled() );
	}

	public function test_migration_is_wired_to_plugin_load_before_the_sanitizer(): void {
		$bootstrap = (string) file_get_contents( dirname( __DIR__ ) . '/mcpwp.php' );
		$this->assertStringContainsString( 'Mcpwp_OAuth_Local_Admin::migrate_default_on()', $bootstrap );
		$this->assertStringNotContainsString( "add_action( 'admin_init', array( 'Mcpwp_OAuth_Local_Admin', 'migrate_default_on' )", $bootstrap );
	}

	// ── Round 2: address-changed line ──────────────────────────────────────

	private function seed_client( bool $authorized ): void {
		$id = 'mcpwpc_' . str_repeat( 'a', 32 );
		update_option(
			Mcpwp_OAuth_Clients::OPTION,
			array(
				$id => array(
					'client_id'     => $id,
					'client_name'   => 'ChatGPT',
					'redirect_uris' => array( 'https://chatgpt.com/cb' ),
					'created_at'    => time(),
					'last_used_at'  => $authorized ? time() : 0,
					'authorized_at' => $authorized ? time() : 0,
				),
			),
			false
		);
	}

	private function render_address_notice(): string {
		ob_start();
		Mcpwp_OAuth_Local_Admin::render_address_notice();
		return (string) ob_get_clean();
	}

	public function test_address_line_shows_when_the_mcp_url_changed_and_a_client_was_authorized(): void {
		$this->admin();
		$this->seed_client( true );
		update_option( Mcpwp_OAuth_Local_Admin::LAST_RESOURCE_OPTION, 'https://old.example.com/wp-json/mcpwp/v1/mcp', false );

		$this->assertTrue( Mcpwp_OAuth_Local_Admin::address_changed() );
		$this->assertStringContainsString( 'This site&#039;s address changed, so AI apps connected with WordPress sign-in must reconnect.', $this->render_address_notice() );
	}

	public function test_address_line_hidden_when_unchanged(): void {
		$this->admin();
		$this->seed_client( true );
		update_option( Mcpwp_OAuth_Local_Admin::LAST_RESOURCE_OPTION, Mcpwp_OAuth_Well_Known::get_canonical_resource(), false );
		$this->assertFalse( Mcpwp_OAuth_Local_Admin::address_changed() );
	}

	public function test_address_line_hidden_when_no_client_was_ever_authorized(): void {
		$this->admin();
		$this->seed_client( false );
		update_option( Mcpwp_OAuth_Local_Admin::LAST_RESOURCE_OPTION, 'https://old.example.com/wp-json/mcpwp/v1/mcp', false );
		$this->assertFalse( Mcpwp_OAuth_Local_Admin::address_changed() );
		$this->assertSame( '', $this->render_address_notice() );
	}

	public function test_address_line_hidden_from_non_admins(): void {
		$this->login( 7, array( 'edit_posts' => true ) );
		$this->seed_client( true );
		update_option( Mcpwp_OAuth_Local_Admin::LAST_RESOURCE_OPTION, 'https://old.example.com/wp-json/mcpwp/v1/mcp', false );
		$this->assertSame( '', $this->render_address_notice() );
	}

	public function test_address_line_dismiss_needs_nonce_and_records_the_current_url(): void {
		$this->admin();
		$this->seed_client( true );
		update_option( Mcpwp_OAuth_Local_Admin::LAST_RESOURCE_OPTION, 'https://old.example.com/wp-json/mcpwp/v1/mcp', false );

		$this->assertFalse( Mcpwp_OAuth_Local_Admin::process_address_dismiss( array( Mcpwp_OAuth_Local_Admin::NONCE_FIELD => 'forged' ) ) );
		$this->assertTrue( Mcpwp_OAuth_Local_Admin::address_changed() );

		$this->assertTrue( Mcpwp_OAuth_Local_Admin::process_address_dismiss( array( Mcpwp_OAuth_Local_Admin::NONCE_FIELD => 'test-nonce' ) ) );
		$this->assertSame( Mcpwp_OAuth_Well_Known::get_canonical_resource(), get_option( Mcpwp_OAuth_Local_Admin::LAST_RESOURCE_OPTION ) );
		$this->assertFalse( Mcpwp_OAuth_Local_Admin::address_changed() );
		$this->assertSame( '', $this->render_address_notice() );
	}

	public function test_first_approval_records_the_resource_and_later_ones_do_not_move_it(): void {
		$this->legacy_client_settings();
		$this->login( 7, array( 'edit_posts' => true ) );
		list( , $fields )      = $this->consent( 'read write' );
		$fields['action']      = 'approve';
		$fields['scope_write'] = '1';
		$this->code_scope( $this->post( $fields ) );
		$this->assertSame( Mcpwp_OAuth_Well_Known::get_canonical_resource(), get_option( Mcpwp_OAuth_Local_Admin::LAST_RESOURCE_OPTION ) );

		update_option( Mcpwp_OAuth_Local_Admin::LAST_RESOURCE_OPTION, 'https://old.example.com/wp-json/mcpwp/v1/mcp', false );
		list( , $again )      = $this->consent( 'read write' );
		$again['action']      = 'approve';
		$again['scope_write'] = '1';
		$this->code_scope( $this->post( $again ) );
		$this->assertSame( 'https://old.example.com/wp-json/mcpwp/v1/mcp', get_option( Mcpwp_OAuth_Local_Admin::LAST_RESOURCE_OPTION ), 'A reconnection must not hide the change from other apps.' );
	}

	// ── D11 / D14 ──────────────────────────────────────────────────────────

	public function test_default_on_is_not_a_chatgpt_conflict(): void {
		$this->assertSame( '', Mcpwp_ChatGPT_Connector::oauth_discovery_conflict() );
	}
}
