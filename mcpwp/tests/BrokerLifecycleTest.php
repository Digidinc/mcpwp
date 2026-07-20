<?php

use PHPUnit\Framework\TestCase;

/**
 * Plan 2 / Task 5 — full broker enrollment lifecycle.
 *
 * Covers the browser-facing legs the admin drives (Connect / Disconnect via
 * admin-post.php) plus the new CSRF `state` relay and the confirm→store
 * state machine that finishes the mumcp.com sign-in broker handshake:
 *
 *   1. Initiation:   admin_post_mcpwp_broker_connect
 *                    → mints `state`, stores mcpwp_broker_state_<state> = user id,
 *                      redirects to https://mumcp.com/enroll/start?site_url=..&state=..
 *   2. Callback:     GET/POST /broker/enroll-callback (Task 2, CSRF-hardened here)
 *                    → verifies + consumes `state`, records nonce as PENDING.
 *   3. Confirm:      POST /broker/enroll-confirm (Task 2, state machine changed here)
 *                    → PENDING -> CONFIRMED (no longer deleted here).
 *   4. Store:        POST /broker/enroll-store (NEW)
 *                    → CONFIRMED nonce only, single-use, encrypts + persists
 *                      mcpwp_broker_enrollment.
 *   5. Disconnect:   admin_post_mcpwp_broker_disconnect
 *                    → local kill-switch (delete_option), best-effort remote notify.
 *
 * @package MCPWP
 */

// ── Missing WordPress stubs for this test file ────────────────────────────
// These admin-post.php-style handlers are untested elsewhere in this codebase
// (see class-mcpwp-integrations-admin.php's Figma OAuth handlers, which are
// not unit tested at all) precisely because they call wp_die()/wp_redirect()
// followed by a raw exit — invoking them directly would kill the PHPUnit
// process. Mcpwp_Broker_Admin routes its termination through an overridable
// terminate() method (see below) specifically so these CAN be tested; the
// stubs here give that method something safe to throw instead of exiting.

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$out   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $out;
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	/**
	 * Stub: valid iff $GLOBALS['mcpwp_test_valid_admin_referer'][$action] is truthy.
	 * Calls wp_die() on failure, exactly like WordPress core.
	 */
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		$valid = ! empty( $GLOBALS['mcpwp_test_valid_admin_referer'][ $action ] );
		if ( ! $valid ) {
			wp_die( 'Invalid nonce for action: ' . $action );
		}
		return true;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Stub: throws a catchable signal instead of exiting, so a
	 * permission/nonce-rejection branch can be asserted on in a test without
	 * terminating the PHPUnit process.
	 */
	function wp_die( $message = '', $title = '', $args = array() ) {
		throw new Mcpwp_Test_Wp_Die_Signal( is_string( $message ) ? $message : 'wp_die called' );
	}
}

if ( ! function_exists( 'wp_redirect' ) ) {
	function wp_redirect( $location, $status = 302 ) {
		$GLOBALS['_mcpwp_test_redirected_to'] = $location;
		return true;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302 ) {
		$GLOBALS['_mcpwp_test_redirected_to'] = $location;
		return true;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

/** Thrown by the wp_die() stub above — a controlled stand-in for wp_die()'s real exit. */
class Mcpwp_Test_Wp_Die_Signal extends \RuntimeException {}

/** Thrown by Mcpwp_Broker_Admin_Test_Double::terminate() — stand-in for a real exit. */
class Mcpwp_Test_Termination_Signal extends \RuntimeException {}

/**
 * $wpdb spy — captures every insert() call so a test can assert on exactly
 * what Mcpwp_Logging::log_activity() persisted (bootstrap.php's own
 * Mcpwp_Test_WPDB double doesn't retain what was inserted).
 */
class Mcpwp_Test_WPDB_Log_Spy {
	public $prefix     = 'wp_';
	public $insert_id  = 0;
	public $last_error = '';

	/** @var array<int, array> */
	public $captured = array();

	public function insert( $table, $data, $format = null ) {
		$this->captured[] = $data;
		return 1;
	}

	public function prepare( $query, ...$args ) {
		return $query;
	}
	public function get_results( $query = '', $output = 'ARRAY_A' ) {
		return array();
	}
	public function get_row( $query = '', $output = 'OBJECT', $y = 0 ) {
		return null;
	}
	public function get_var( $query = '', $x = 0, $y = 0 ) {
		return null;
	}
	public function get_col( $query = '', $x = 0 ) {
		return array();
	}
	public function query( $query ) {
		return 0;
	}
	public function update( $table, $data, $where, $f = null, $wf = null ) {
		return 1;
	}
	public function delete( $table, $where, $where_format = null ) {
		return 1;
	}
	public function replace( $table, $data, $format = null ) {
		return 1;
	}
	public function esc_like( $text ) {
		return (string) $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-broker-auth.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-broker.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-broker-admin.php';

/**
 * Test double: overrides terminate() so handle_connect()/handle_disconnect()
 * can be exercised directly without a raw exit killing the test process.
 * Production Mcpwp_Broker_Admin::terminate() still calls a real exit.
 */
class Mcpwp_Broker_Admin_Test_Double extends Mcpwp_Broker_Admin {
	protected function terminate() {
		throw new Mcpwp_Test_Termination_Signal();
	}
}

final class BrokerLifecycleTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']              = array();
		$GLOBALS['mcpwp_test_transients']            = array();
		$GLOBALS['mcpwp_test_current_user']          = 0;
		$GLOBALS['mcpwp_test_user_caps']             = array();
		$GLOBALS['mcpwp_test_valid_admin_referer']   = array();
		$GLOBALS['_mcpwp_test_redirected_to']        = null;
		$GLOBALS['_mcpwp_test_registered_routes']    = array();

		$ref      = new ReflectionClass( Mcpwp_Encryption::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	private function admin(): Mcpwp_Broker_Admin_Test_Double {
		return new Mcpwp_Broker_Admin_Test_Double();
	}

	private function broker(): Mcpwp_REST_Broker {
		return new Mcpwp_REST_Broker();
	}

	private function allowAdminReferer( $action ) {
		$GLOBALS['mcpwp_test_valid_admin_referer'][ $action ] = true;
	}

	// ── 1. Initiation — admin_post_mcpwp_broker_connect ─────────────────────

	public function test_connect_as_admin_with_valid_nonce_stores_state_and_redirects(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$this->allowAdminReferer( Mcpwp_Broker_Admin::CONNECT_ACTION );

		$admin = $this->admin();

		try {
			$admin->handle_connect();
			$this->fail( 'Expected terminate() to be called after a successful redirect.' );
		} catch ( Mcpwp_Test_Termination_Signal $e ) {
			// expected — mirrors the real exit() after wp_redirect().
		}

		$location = $GLOBALS['_mcpwp_test_redirected_to'];
		$this->assertNotNull( $location, 'A successful connect must redirect the browser.' );
		$this->assertStringStartsWith( 'https://mumcp.com/enroll/start?', $location );
		$this->assertStringContainsString( 'site_url=', $location );
		$this->assertStringContainsString( 'state=', $location );

		// Extract the state token from the redirect URL and confirm the transient.
		$query = wp_parse_url( $location, PHP_URL_QUERY );
		parse_str( (string) $query, $params );
		$this->assertArrayHasKey( 'state', $params );
		$state = $params['state'];
		$this->assertGreaterThanOrEqual( 8, strlen( $state ) );

		$this->assertSame(
			1,
			get_transient( 'mcpwp_broker_state_' . $state ),
			'The minted state must be stored as a transient keyed to the current user id.'
		);
	}

	public function test_connect_non_admin_is_rejected_and_stores_no_state(): void {
		$GLOBALS['mcpwp_test_current_user'] = 0; // Not logged in / not an admin.

		$admin = $this->admin();

		$this->expectException( Mcpwp_Test_Wp_Die_Signal::class );
		try {
			$admin->handle_connect();
		} finally {
			$this->assertNull( $GLOBALS['_mcpwp_test_redirected_to'], 'A rejected connect must never redirect.' );
			$this->assertEmpty( $GLOBALS['mcpwp_test_transients'], 'A rejected connect must never store a state transient.' );
		}
	}

	public function test_connect_with_bad_wp_nonce_is_rejected_and_stores_no_state(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1; // Admin, but nonce not allow-listed.

		$admin = $this->admin();

		$this->expectException( Mcpwp_Test_Wp_Die_Signal::class );
		try {
			$admin->handle_connect();
		} finally {
			$this->assertNull( $GLOBALS['_mcpwp_test_redirected_to'] );
			$this->assertEmpty( $GLOBALS['mcpwp_test_transients'] );
		}
	}

	// ── 4. Store — POST /broker/enroll-store ────────────────────────────────

	private function confirmedEnrollment( $nonce ) {
		set_transient( Mcpwp_REST_Broker::ENROLL_TRANSIENT_PREFIX . $nonce, array( 'state' => 'confirmed' ), 300 );
	}

	private function pendingEnrollment( $nonce ) {
		set_transient( Mcpwp_REST_Broker::ENROLL_TRANSIENT_PREFIX . $nonce, array( 'state' => 'pending' ), 300 );
	}

	private function storeRequest( array $params ) {
		return new WP_REST_Request( 'POST', '/mcpwp/v1/broker/enroll-store', $params );
	}

	public function test_store_route_is_registered_and_publicly_callable(): void {
		$ctrl = $this->broker();
		$ctrl->register_routes();

		$routes = array();
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $route ) {
			$routes[ $route['route'] ] = $route['args'];
		}

		$this->assertArrayHasKey( '/broker/enroll-store', $routes );
		$entry = isset( $routes['/broker/enroll-store'][0] ) ? $routes['/broker/enroll-store'][0] : $routes['/broker/enroll-store'];
		$this->assertSame( '__return_true', $entry['permission_callback'], 'enroll-store is S2S — the confirmed nonce IS the authentication.' );
	}

	public function test_store_with_confirmed_nonce_encrypts_and_persists_enrollment(): void {
		$this->confirmedEnrollment( 'storeNonce1' );

		$ctrl     = $this->broker();
		$request  = $this->storeRequest(
			array(
				'nonce'                   => 'storeNonce1',
				'site_id'                 => 'site-abc',
				'audience'                => 'https://example.test',
				'issuer'                  => 'https://mumcp.com',
				'introspection_endpoint'  => 'https://mumcp.com/oauth/introspect',
				'site_credential'         => 'plaintext-super-secret',
			)
		);
		$response = $ctrl->handle_enroll_store( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['stored'] );

		$enrollment = get_option( 'mcpwp_broker_enrollment' );
		$this->assertIsArray( $enrollment );
		$this->assertSame( 'site-abc', $enrollment['site_id'] );
		$this->assertSame( 'https://example.test', $enrollment['audience'] );
		$this->assertSame( 'https://mumcp.com', $enrollment['issuer'] );
		$this->assertSame( 'https://mumcp.com/oauth/introspect', $enrollment['introspection_endpoint'] );

		$this->assertNotSame( 'plaintext-super-secret', $enrollment['site_credential'], 'The credential must never be stored in plaintext.' );
		$this->assertStringNotContainsString( 'plaintext-super-secret', $enrollment['site_credential'] );

		$decrypted = Mcpwp_Encryption::get_instance()->decrypt( $enrollment['site_credential'] );
		$this->assertSame( 'plaintext-super-secret', $decrypted, 'The encrypted credential must round-trip.' );

		// Single-use: the enroll transient must be consumed.
		$this->assertFalse( get_transient( Mcpwp_REST_Broker::ENROLL_TRANSIENT_PREFIX . 'storeNonce1' ) );
	}

	public function test_store_with_pending_not_confirmed_nonce_fails_and_writes_nothing(): void {
		$this->pendingEnrollment( 'storeNonce2' );

		$ctrl     = $this->broker();
		$request  = $this->storeRequest(
			array(
				'nonce'           => 'storeNonce2',
				'site_id'         => 'site-abc',
				'audience'        => 'https://example.test',
				'issuer'          => 'https://mumcp.com',
				'site_credential' => 'plaintext-super-secret',
			)
		);
		$response = $ctrl->handle_enroll_store( $request );

		$this->assertFalse( $response->get_data()['stored'] );
		$this->assertFalse( get_option( 'mcpwp_broker_enrollment', false ) );
	}

	public function test_store_with_unknown_nonce_fails(): void {
		$ctrl     = $this->broker();
		$request  = $this->storeRequest(
			array(
				'nonce'           => 'never-issued-store-nonce',
				'site_id'         => 'site-abc',
				'audience'        => 'https://example.test',
				'site_credential' => 'plaintext-super-secret',
			)
		);
		$response = $ctrl->handle_enroll_store( $request );

		$this->assertFalse( $response->get_data()['stored'] );
		$this->assertFalse( get_option( 'mcpwp_broker_enrollment', false ) );
	}

	public function test_store_twice_replay_second_call_fails(): void {
		$this->confirmedEnrollment( 'storeNonce3' );

		$ctrl    = $this->broker();
		$params  = array(
			'nonce'                  => 'storeNonce3',
			'site_id'                => 'site-abc',
			'audience'               => 'https://example.test',
			'issuer'                 => 'https://mumcp.com',
			'introspection_endpoint' => 'https://mumcp.com/oauth/introspect',
			'site_credential'        => 'plaintext-super-secret',
		);

		$first  = $ctrl->handle_enroll_store( $this->storeRequest( $params ) );
		$second = $ctrl->handle_enroll_store( $this->storeRequest( $params ) );

		$this->assertTrue( $first->get_data()['stored'] );
		$this->assertFalse( $second->get_data()['stored'], 'A replayed store call must not be able to re-deliver the credential.' );
	}

	public function test_store_missing_required_fields_fails_and_writes_nothing(): void {
		$this->confirmedEnrollment( 'storeNonce4' );

		$ctrl     = $this->broker();
		$response = $ctrl->handle_enroll_store(
			$this->storeRequest(
				array(
					'nonce'    => 'storeNonce4',
					'audience' => 'https://example.test',
					// site_id and site_credential deliberately missing.
				)
			)
		);

		$this->assertFalse( $response->get_data()['stored'] );
		$this->assertFalse( get_option( 'mcpwp_broker_enrollment', false ) );
	}

	// ── Host-lock — issuer / introspection_endpoint (adversarial review) ────

	public function test_store_with_poisoned_introspection_endpoint_fails_and_writes_nothing(): void {
		$this->confirmedEnrollment( 'hostLockNonce1' );

		$ctrl     = $this->broker();
		$response = $ctrl->handle_enroll_store(
			$this->storeRequest(
				array(
					'nonce'                  => 'hostLockNonce1',
					'site_id'                => 'site-abc',
					'audience'               => 'https://example.test',
					'issuer'                 => 'https://mumcp.com',
					'introspection_endpoint' => 'https://evil.example/x',
					'site_credential'        => 'plaintext-super-secret',
				)
			)
		);

		$this->assertFalse( $response->get_data()['stored'], 'A non-mumcp.com introspection_endpoint must be rejected — it would exfiltrate the site credential.' );
		$this->assertFalse( get_option( 'mcpwp_broker_enrollment', false ) );
	}

	public function test_store_with_poisoned_issuer_fails_and_writes_nothing(): void {
		$this->confirmedEnrollment( 'hostLockNonce2' );

		$ctrl     = $this->broker();
		$response = $ctrl->handle_enroll_store(
			$this->storeRequest(
				array(
					'nonce'                  => 'hostLockNonce2',
					'site_id'                => 'site-abc',
					'audience'               => 'https://example.test',
					'issuer'                 => 'https://evil.com',
					'introspection_endpoint' => 'https://mumcp.com/oauth/introspect',
					'site_credential'        => 'plaintext-super-secret',
				)
			)
		);

		$this->assertFalse( $response->get_data()['stored'], 'A non-mumcp.com issuer must be rejected.' );
		$this->assertFalse( get_option( 'mcpwp_broker_enrollment', false ) );
	}

	public function test_store_with_missing_introspection_endpoint_fails_and_writes_nothing(): void {
		$this->confirmedEnrollment( 'hostLockNonce3' );

		$ctrl     = $this->broker();
		$response = $ctrl->handle_enroll_store(
			$this->storeRequest(
				array(
					'nonce'           => 'hostLockNonce3',
					'site_id'         => 'site-abc',
					'audience'        => 'https://example.test',
					'issuer'          => 'https://mumcp.com',
					'site_credential' => 'plaintext-super-secret',
					// introspection_endpoint deliberately omitted.
				)
			)
		);

		$this->assertFalse( $response->get_data()['stored'], 'A missing introspection_endpoint must be rejected, not silently defaulted.' );
		$this->assertFalse( get_option( 'mcpwp_broker_enrollment', false ) );
	}

	public function test_store_with_valid_mumcp_issuer_and_endpoint_succeeds(): void {
		$this->confirmedEnrollment( 'hostLockNonce4' );

		$ctrl     = $this->broker();
		$response = $ctrl->handle_enroll_store(
			$this->storeRequest(
				array(
					'nonce'                  => 'hostLockNonce4',
					'site_id'                => 'site-abc',
					'audience'               => 'https://example.test',
					'issuer'                 => 'https://mumcp.com',
					'introspection_endpoint' => 'https://mumcp.com/oauth/introspect',
					'site_credential'        => 'plaintext-super-secret',
				)
			)
		);

		$this->assertTrue( $response->get_data()['stored'], 'A legitimate https://mumcp.com issuer + introspection_endpoint must still be accepted.' );
		$this->assertIsArray( get_option( 'mcpwp_broker_enrollment', false ) );
	}

	/**
	 * @dataProvider spoofedMumcpHostProvider
	 */
	public function test_store_with_lookalike_mumcp_host_in_introspection_endpoint_fails( string $spoofed_endpoint ): void {
		$this->confirmedEnrollment( 'hostLockNonce_' . md5( $spoofed_endpoint ) );

		$ctrl     = $this->broker();
		$response = $ctrl->handle_enroll_store(
			$this->storeRequest(
				array(
					'nonce'                  => 'hostLockNonce_' . md5( $spoofed_endpoint ),
					'site_id'                => 'site-abc',
					'audience'               => 'https://example.test',
					'issuer'                 => 'https://mumcp.com',
					'introspection_endpoint' => $spoofed_endpoint,
					'site_credential'        => 'plaintext-super-secret',
				)
			)
		);

		$this->assertFalse( $response->get_data()['stored'], "Lookalike host '$spoofed_endpoint' must be rejected, not treated as mumcp.com." );
		$this->assertFalse( get_option( 'mcpwp_broker_enrollment', false ) );
	}

	public static function spoofedMumcpHostProvider(): array {
		return array(
			'subdomain-suffix lookalike' => array( 'https://mumcp.com.evil.com/oauth/introspect' ),
			'userinfo lookalike'         => array( 'https://mumcp.com@evil.com/oauth/introspect' ),
		);
	}

	public function test_store_never_logs_plaintext_site_credential(): void {
		update_option( 'mcpwp_settings', array( 'enable_logging' => true ) );
		$this->confirmedEnrollment( 'logSafeNonce1' );

		$spy           = new Mcpwp_Test_WPDB_Log_Spy();
		$previous_wpdb = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $spy;

		try {
			$ctrl     = $this->broker();
			$response = $ctrl->handle_enroll_store(
				$this->storeRequest(
					array(
						'nonce'                  => 'logSafeNonce1',
						'site_id'                => 'site-abc',
						'audience'               => 'https://example.test',
						'issuer'                 => 'https://mumcp.com',
						'introspection_endpoint' => 'https://mumcp.com/oauth/introspect',
						'site_credential'        => 'super-secret-plaintext-value',
					)
				)
			);
			$this->assertTrue( $response->get_data()['stored'] );
		} finally {
			$GLOBALS['wpdb'] = $previous_wpdb;
		}

		$this->assertNotEmpty( $spy->captured, 'Expected log_activity() to write an activity-log row when logging is enabled.' );
		foreach ( $spy->captured as $row ) {
			$haystack = wp_json_encode( $row );
			$this->assertStringNotContainsString(
				'super-secret-plaintext-value',
				$haystack,
				'The plaintext site_credential must never reach the activity log, regardless of the site\'s log_redaction_keys configuration.'
			);
		}
	}

	// ── 5. Disconnect — admin_post_mcpwp_broker_disconnect ──────────────────

	public function test_disconnect_as_admin_deletes_enrollment_and_kills_switch(): void {
		update_option(
			'mcpwp_broker_enrollment',
			array(
				'issuer'                 => 'https://mumcp.com',
				'site_id'                => 'site-abc',
				'audience'               => 'https://example.test',
				'introspection_endpoint' => 'https://mumcp.com/oauth/introspect',
				'site_credential'        => Mcpwp_Encryption::get_instance()->encrypt( 'cred' ),
			)
		);

		$auth = new Mcpwp_Broker_Auth();
		$this->assertTrue( $auth->is_enrolled() );

		$GLOBALS['mcpwp_test_current_user'] = 1;
		$this->allowAdminReferer( Mcpwp_Broker_Admin::DISCONNECT_ACTION );

		$admin = $this->admin();

		try {
			$admin->handle_disconnect();
			$this->fail( 'Expected terminate() after a successful disconnect redirect.' );
		} catch ( Mcpwp_Test_Termination_Signal $e ) {
			// expected.
		}

		$this->assertFalse( get_option( 'mcpwp_broker_enrollment', false ), 'Disconnect must delete the local enrollment option (instant kill-switch).' );
		$this->assertFalse( ( new Mcpwp_Broker_Auth() )->is_enrolled() );
		$this->assertNotNull( $GLOBALS['_mcpwp_test_redirected_to'], 'Disconnect must redirect back into wp-admin.' );
	}

	public function test_disconnect_non_admin_is_rejected_and_enrollment_survives(): void {
		update_option(
			'mcpwp_broker_enrollment',
			array(
				'site_id'         => 'site-abc',
				'audience'        => 'https://example.test',
				'site_credential' => Mcpwp_Encryption::get_instance()->encrypt( 'cred' ),
			)
		);

		$GLOBALS['mcpwp_test_current_user'] = 0;

		$admin = $this->admin();

		$this->expectException( Mcpwp_Test_Wp_Die_Signal::class );
		try {
			$admin->handle_disconnect();
		} finally {
			$this->assertNotFalse( get_option( 'mcpwp_broker_enrollment', false ), 'A rejected disconnect must not touch the enrollment.' );
		}
	}

	// ── Full happy path — connect → callback(state) → confirm → store ──────

	public function test_full_enrollment_happy_path_ends_enrolled_and_usable(): void {
		// 1. Initiation.
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$this->allowAdminReferer( Mcpwp_Broker_Admin::CONNECT_ACTION );

		$admin = $this->admin();
		try {
			$admin->handle_connect();
		} catch ( Mcpwp_Test_Termination_Signal $e ) {
			// expected.
		}

		$location = $GLOBALS['_mcpwp_test_redirected_to'];
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $params );
		$state = $params['state'];

		// 2. Callback — the broker 302s the admin's browser back with the state.
		$broker  = $this->broker();
		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/broker/enroll-callback',
			array(
				'nonce'  => 'happyPathNonce1',
				'return' => 'https://mumcp.com/enroll/complete',
				'state'  => $state,
			)
		);
		$this->assertTrue( $broker->check_admin_permission( $request ) );
		$callback_response = $broker->handle_enroll_callback( $request );
		$this->assertSame( 302, $callback_response->get_status() );

		// The CSRF state transient must now be consumed.
		$this->assertFalse( get_transient( 'mcpwp_broker_state_' . $state ) );

		// 3. Confirm — the broker calls this server-to-server, no WP auth.
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$confirm_response = $broker->handle_enroll_confirm(
			new WP_REST_Request( 'POST', '/mcpwp/v1/broker/enroll-confirm', array( 'nonce' => 'happyPathNonce1' ) )
		);
		$this->assertTrue( $confirm_response->get_data()['confirmed'] );

		// 4. Store — the broker pushes the credential S2S.
		$store_response = $broker->handle_enroll_store(
			new WP_REST_Request(
				'POST',
				'/mcpwp/v1/broker/enroll-store',
				array(
					'nonce'                  => 'happyPathNonce1',
					'site_id'                => 'site-happy',
					'audience'               => 'https://example.com',
					'issuer'                 => 'https://mumcp.com',
					'introspection_endpoint' => 'https://mumcp.com/oauth/introspect',
					'site_credential'        => 'happy-path-secret',
				)
			)
		);
		$this->assertTrue( $store_response->get_data()['stored'] );

		// 5. The site is now enrolled and the stored credential is usable.
		$auth = new Mcpwp_Broker_Auth();
		$this->assertTrue( $auth->is_enrolled() );

		$captured = new ArrayObject();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $captured ) {
				$captured[] = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'active'   => true,
							'scope'    => array( 'write' ),
							'sub'      => 'user-1',
							'site_id'  => 'site-happy',
							'aud'      => 'https://example.com',
							'exp'      => time() + 60,
						)
					),
				);
			},
			10,
			3
		);

		$result = $auth->validate_token( 'a-token' );
		$this->assertIsArray( $result, 'The freshly stored (decrypted) credential must be usable for introspection.' );
		$this->assertSame(
			'Basic ' . base64_encode( 'site-happy:happy-path-secret' ),
			$captured[0]['headers']['Authorization']
		);
	}
}
