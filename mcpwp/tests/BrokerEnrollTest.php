<?php

use PHPUnit\Framework\TestCase;

/**
 * Broker enrollment endpoints — unit tests.
 *
 * Plan 2 / Task 2: `/broker/enroll-callback` + `/broker/enroll-confirm`.
 *
 * These two REST routes are the plugin's half of the mumcp.com broker's
 * site-control proof:
 *
 *   1. Broker 302s the admin's browser to
 *      `<site>/wp-json/mcpwp/v1/broker/enroll-callback?nonce=<n>&return=https://mumcp.com/enroll/complete`.
 *   2. The callback is admin-gated (permission_callback requires
 *      current_user_can('manage_options')) — this is what proves an ADMIN's
 *      authenticated browser session initiated the enrollment, not just
 *      anyone who can guess the nonce. It records the nonce as PENDING
 *      (single-use, 5-minute TTL) and 302-redirects the browser to the
 *      broker-controlled `return` URL.
 *   3. The broker then POSTs `enroll-confirm {nonce}` server-to-server, with
 *      NO WordPress auth (permission_callback is `__return_true` — the site
 *      has no broker credential yet at this point, so there is nothing to
 *      authenticate with). Security here comes entirely from the single-use
 *      pending-nonce match, not from WP auth.
 *
 * @package MCPWP
 */

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-broker.php';

final class BrokerEnrollTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']       = array();
		$GLOBALS['mcpwp_test_transients']    = array();
		$GLOBALS['mcpwp_test_current_user']  = 0;
		$GLOBALS['mcpwp_test_user_caps']     = array();
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
	}

	private function controller() {
		return new Mcpwp_REST_Broker();
	}

	private function make_request( $method, $params = array() ) {
		return new WP_REST_Request( $method, '/mcpwp/v1/broker/enroll-callback', $params );
	}

	// ── Route registration — wiring sanity ─────────────────────────────────

	public function test_routes_registered_with_expected_permission_shape(): void {
		$ctrl = $this->controller();
		$ctrl->register_routes();

		$routes = array();
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $route ) {
			$routes[ $route['route'] ] = $route['args'];
		}

		$this->assertArrayHasKey( '/broker/enroll-callback', $routes );
		$this->assertArrayHasKey( '/broker/enroll-confirm', $routes );

		// enroll-confirm must be publicly callable (no WP auth) — the broker
		// calls it server-to-server before the site has any broker credential.
		$confirm_args = $routes['/broker/enroll-confirm'];
		$confirm_entry = isset( $confirm_args[0] ) ? $confirm_args[0] : $confirm_args;
		$this->assertSame( '__return_true', $confirm_entry['permission_callback'] );

		// enroll-callback must NOT be publicly callable — it is gated on
		// current_user_can('manage_options') via check_admin_permission().
		$callback_args = $routes['/broker/enroll-callback'];
		$callback_entry = isset( $callback_args[0] ) ? $callback_args[0] : $callback_args;
		$this->assertNotSame( '__return_true', $callback_entry['permission_callback'] );
	}

	// ── GET /broker/enroll-callback — admin gate ────────────────────────────

	public function test_callback_without_admin_is_denied_and_writes_no_pending_transient(): void {
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$ctrl    = $this->controller();
		$request = $this->make_request( 'GET', array(
			'nonce'  => 'abc123XYZ789',
			'return' => 'https://mumcp.com/enroll/complete',
		) );

		$permission = $ctrl->check_admin_permission( $request );

		$this->assertNotTrue( $permission, 'A non-admin (or logged-out) caller must not pass the callback permission gate.' );
		$this->assertInstanceOf( WP_Error::class, $permission );
		$data = $permission->get_error_data();
		$this->assertContains( $data['status'], array( 401, 403 ) );

		// Since permission failed, WordPress never invokes the handler — the
		// pending transient must not exist.
		$this->assertFalse( get_transient( 'mcpwp_broker_pending_abc123XYZ789' ) );
	}

	public function test_callback_with_non_admin_caps_is_denied(): void {
		$GLOBALS['mcpwp_test_current_user']        = 5;
		$GLOBALS['mcpwp_test_user_caps'][5]        = array( 'manage_options' => false );

		$ctrl    = $this->controller();
		$request = $this->make_request( 'GET', array(
			'nonce'  => 'abc123XYZ789',
			'return' => 'https://mumcp.com/enroll/complete',
		) );

		$permission = $ctrl->check_admin_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $permission );
		$this->assertFalse( get_transient( 'mcpwp_broker_pending_abc123XYZ789' ) );
	}

	// ── GET /broker/enroll-callback — admin, valid return ───────────────────

	public function test_callback_with_admin_and_valid_return_sets_pending_and_redirects(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;

		$ctrl    = $this->controller();
		$request = $this->make_request( 'GET', array(
			'nonce'  => 'nonceValueOne1',
			'return' => 'https://mumcp.com/enroll/complete',
		) );

		$this->assertTrue( $ctrl->check_admin_permission( $request ) );

		$response = $ctrl->handle_enroll_callback( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 302, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertSame( 'https://mumcp.com/enroll/complete', $headers['Location'] );

		$this->assertNotFalse(
			get_transient( 'mcpwp_broker_pending_nonceValueOne1' ),
			'A valid admin-initiated callback must record the nonce as pending.'
		);
	}

	public function test_callback_missing_nonce_is_rejected(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;

		$ctrl     = $this->controller();
		$request  = $this->make_request( 'GET', array(
			'return' => 'https://mumcp.com/enroll/complete',
		) );
		$response = $ctrl->handle_enroll_callback( $request );

		$this->assertTrue( is_wp_error( $response ) );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_callback_missing_return_is_rejected(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;

		$ctrl     = $this->controller();
		$request  = $this->make_request( 'GET', array(
			'nonce' => 'nonceValueTwo2',
		) );
		$response = $ctrl->handle_enroll_callback( $request );

		$this->assertTrue( is_wp_error( $response ) );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertFalse( get_transient( 'mcpwp_broker_pending_nonceValueTwo2' ) );
	}

	// ── GET /broker/enroll-callback — open-redirect guard ───────────────────

	public function test_callback_rejects_non_mumcp_return_url(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;

		$ctrl    = $this->controller();
		$request = $this->make_request( 'GET', array(
			'nonce'  => 'evilNonceValue3',
			'return' => 'https://evil.com/phish',
		) );

		$response = $ctrl->handle_enroll_callback( $request );

		$this->assertTrue( is_wp_error( $response ), 'A non-mumcp.com return URL must be rejected, not redirected to.' );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertNotInstanceOf( WP_REST_Response::class, $response );
	}

	public function test_callback_rejects_http_scheme_return_url(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;

		$ctrl    = $this->controller();
		$request = $this->make_request( 'GET', array(
			'nonce'  => 'httpNonceValue4',
			'return' => 'http://mumcp.com/enroll/complete',
		) );

		$response = $ctrl->handle_enroll_callback( $request );

		$this->assertTrue( is_wp_error( $response ), 'A non-https return URL must be rejected even when the host matches.' );
	}

	public function test_callback_rejects_lookalike_host(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;

		$ctrl    = $this->controller();
		$request = $this->make_request( 'GET', array(
			'nonce'  => 'lookalikeNonce5',
			'return' => 'https://mumcp.com.evil.com/phish',
		) );

		$response = $ctrl->handle_enroll_callback( $request );

		$this->assertTrue( is_wp_error( $response ), 'A host that merely contains mumcp.com as a substring must be rejected.' );
	}

	// ── POST /broker/enroll-confirm — single-use, public ────────────────────

	public function test_confirm_with_pending_nonce_confirms_and_deletes_it(): void {
		set_transient( 'mcpwp_broker_pending_confirmNonce1', 1, 300 );

		// Requires NO auth — the broker calls this server-to-server before the
		// site has any broker credential.
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$ctrl     = $this->controller();
		$request  = new WP_REST_Request( 'POST', '/mcpwp/v1/broker/enroll-confirm', array(
			'nonce' => 'confirmNonce1',
		) );
		$response = $ctrl->handle_enroll_confirm( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['confirmed'] );

		$this->assertFalse(
			get_transient( 'mcpwp_broker_pending_confirmNonce1' ),
			'A confirmed nonce must be deleted immediately (single-use).'
		);
	}

	public function test_confirm_same_nonce_twice_second_call_returns_false(): void {
		set_transient( 'mcpwp_broker_pending_confirmNonce2', 1, 300 );
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$ctrl    = $this->controller();
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/broker/enroll-confirm', array(
			'nonce' => 'confirmNonce2',
		) );

		$first  = $ctrl->handle_enroll_confirm( $request );
		$second = $ctrl->handle_enroll_confirm( $request );

		$this->assertTrue( $first->get_data()['confirmed'] );
		$this->assertSame( 200, $second->get_status() );
		$this->assertFalse( $second->get_data()['confirmed'] );
	}

	public function test_confirm_unknown_nonce_returns_false_not_error(): void {
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$ctrl     = $this->controller();
		$request  = new WP_REST_Request( 'POST', '/mcpwp/v1/broker/enroll-confirm', array(
			'nonce' => 'never-issued-nonce',
		) );
		$response = $ctrl->handle_enroll_confirm( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['confirmed'] );
	}

	public function test_confirm_missing_nonce_returns_false_not_error(): void {
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$ctrl     = $this->controller();
		$request  = new WP_REST_Request( 'POST', '/mcpwp/v1/broker/enroll-confirm', array() );
		$response = $ctrl->handle_enroll_confirm( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['confirmed'] );
	}

	public function test_confirm_requires_no_admin_auth(): void {
		// Explicitly logged out — enroll-confirm must still work.
		$GLOBALS['mcpwp_test_current_user'] = 0;
		set_transient( 'mcpwp_broker_pending_publicNonce6', 1, 300 );

		$ctrl     = $this->controller();
		$request  = new WP_REST_Request( 'POST', '/mcpwp/v1/broker/enroll-confirm', array(
			'nonce' => 'publicNonce6',
		) );
		$response = $ctrl->handle_enroll_confirm( $request );

		$this->assertTrue( $response->get_data()['confirmed'] );
	}
}
