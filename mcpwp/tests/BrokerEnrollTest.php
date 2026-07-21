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

	/**
	 * Mint a valid CSRF state transient for $user_id and return the state token.
	 *
	 * Mirrors what Mcpwp_Broker_Admin::handle_connect() does in production:
	 * mint state, set_transient( 'mcpwp_broker_state_<state>', $user_id, 600 ).
	 */
	private function mint_state( $user_id, $state = 'stateTokenValueABC' ) {
		set_transient( 'mcpwp_broker_state_' . $state, $user_id, 600 );
		return $state;
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

		// enroll-callback must ALSO be publicly callable: it is reached by the
		// broker's top-level browser 302 with no X-WP-Nonce header, so a
		// manage_options gate would always 403 in production. The single-use
		// `state` param is the admin-initiation proof instead (see
		// handle_enroll_callback()). Both the READABLE and CREATABLE variants
		// must be public.
		$callback_args = $routes['/broker/enroll-callback'];
		$this->assertIsArray( $callback_args );
		foreach ( $callback_args as $callback_entry ) {
			$this->assertSame( '__return_true', $callback_entry['permission_callback'] );
		}
	}

	// ── GET /broker/enroll-callback — public + state-gated (Fix 1) ──────────

	/**
	 * Fix 1 (CORRECTNESS): the callback is PUBLIC. It must work with a valid
	 * `state` even when NO admin cookie is present (current_user_id == 0) —
	 * exactly the production reality of a top-level browser 302 with no
	 * X-WP-Nonce header. This is the happy path that the old manage_options
	 * gate broke.
	 */
	public function test_callback_public_with_valid_state_and_no_admin_sets_pending_and_redirects(): void {
		// No admin session — mirrors the real cookie-without-nonce 302 context.
		$GLOBALS['mcpwp_test_current_user'] = 0;
		// The state was minted earlier by the admin-gated Connect flow (keyed to
		// user 1); the browser hitting the callback carries no user context.
		$state = $this->mint_state( 1 );

		$ctrl    = $this->controller();
		$request = $this->make_request( 'GET', array(
			'nonce'  => 'publicPathNonce',
			'return' => 'https://mumcp.com/enroll/complete',
			'state'  => $state,
		) );

		$response = $ctrl->handle_enroll_callback( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'A valid state must produce a 302 even with no admin cookie.' );
		$this->assertSame( 302, $response->get_status() );

		$entry = get_transient( 'mcpwp_broker_enroll_publicPathNonce' );
		$this->assertIsArray( $entry, 'A valid state-gated callback must record the nonce as pending — with no admin session.' );
		$this->assertSame( 'pending', $entry['state'] );

		// Single-use state consumed.
		$this->assertFalse( get_transient( 'mcpwp_broker_state_' . $state ) );
	}

	// ── GET /broker/enroll-callback — valid return ──────────────────────────

	public function test_callback_with_valid_state_and_return_sets_pending_and_redirects(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$state = $this->mint_state( 1 );

		$ctrl    = $this->controller();
		$request = $this->make_request( 'GET', array(
			'nonce'  => 'nonceValueOne1',
			'return' => 'https://mumcp.com/enroll/complete',
			'state'  => $state,
		) );

		$response = $ctrl->handle_enroll_callback( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 302, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertSame( 'https://mumcp.com/enroll/complete', $headers['Location'] );

		$entry = get_transient( 'mcpwp_broker_enroll_nonceValueOne1' );
		$this->assertIsArray( $entry, 'A valid admin-initiated callback must record the nonce as pending.' );
		$this->assertSame( 'pending', $entry['state'] );

		$this->assertFalse(
			get_transient( 'mcpwp_broker_state_' . $state ),
			'The CSRF state transient must be consumed (single-use) once the callback succeeds.'
		);
	}

	// ── GET /broker/enroll-callback — CSRF state verification ──────────────

	public function test_callback_missing_state_is_rejected_and_stores_no_pending_enrollment(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;

		$ctrl    = $this->controller();
		$request = $this->make_request( 'GET', array(
			'nonce'  => 'noStateNonce01',
			'return' => 'https://mumcp.com/enroll/complete',
		) );

		$response = $ctrl->handle_enroll_callback( $request );

		$this->assertTrue( is_wp_error( $response ), 'A callback with no state param must be rejected (CSRF defense).' );
		$this->assertSame( 403, $response->get_error_data()['status'] );
		$this->assertFalse( get_transient( 'mcpwp_broker_enroll_noStateNonce01' ) );
	}

	public function test_callback_state_not_matching_any_transient_is_rejected(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;

		$ctrl    = $this->controller();
		$request = $this->make_request( 'GET', array(
			'nonce'  => 'badStateNonce02',
			'return' => 'https://mumcp.com/enroll/complete',
			'state'  => 'never-issued-state-value',
		) );

		$response = $ctrl->handle_enroll_callback( $request );

		$this->assertTrue( is_wp_error( $response ) );
		$this->assertSame( 403, $response->get_error_data()['status'] );
		$this->assertFalse( get_transient( 'mcpwp_broker_enroll_badStateNonce02' ) );
	}

	public function test_callback_state_is_not_bound_to_a_user_id(): void {
		// Fix 1: the callback no longer compares the state to the current user
		// id — current_user is 0 in the real cookie-without-nonce 302 context,
		// so a user-id match is unenforceable. A valid, unconsumed state is
		// accepted regardless of which session presents it; its unguessability +
		// single-use consumption is the CSRF guarantee. Here the state was minted
		// for user 5 but is presented with no session at all.
		$state = $this->mint_state( 5 );
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$ctrl    = $this->controller();
		$request = $this->make_request( 'GET', array(
			'nonce'  => 'anyUserNonce03',
			'return' => 'https://mumcp.com/enroll/complete',
			'state'  => $state,
		) );

		$response = $ctrl->handle_enroll_callback( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 302, $response->get_status() );
		$entry = get_transient( 'mcpwp_broker_enroll_anyUserNonce03' );
		$this->assertIsArray( $entry );
		$this->assertSame( 'pending', $entry['state'] );
		$this->assertFalse( get_transient( 'mcpwp_broker_state_' . $state ), 'The state must be consumed single-use.' );
	}

	public function test_callback_consumed_state_cannot_be_replayed(): void {
		// A state is single-use: once a callback consumes it, a second callback
		// presenting the same state is rejected (unknown/expired) → 403, no
		// pending stored.
		$state = $this->mint_state( 1 );
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$ctrl = $this->controller();

		$first = $ctrl->handle_enroll_callback( $this->make_request( 'GET', array(
			'nonce'  => 'replayNonceA',
			'return' => 'https://mumcp.com/enroll/complete',
			'state'  => $state,
		) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $first );

		$second = $ctrl->handle_enroll_callback( $this->make_request( 'GET', array(
			'nonce'  => 'replayNonceB',
			'return' => 'https://mumcp.com/enroll/complete',
			'state'  => $state,
		) ) );

		$this->assertTrue( is_wp_error( $second ), 'A consumed state must not be replayable.' );
		$this->assertSame( 403, $second->get_error_data()['status'] );
		$this->assertFalse( get_transient( 'mcpwp_broker_enroll_replayNonceB' ) );
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
		$this->assertFalse( get_transient( 'mcpwp_broker_enroll_nonceValueTwo2' ) );
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

	public function test_confirm_with_pending_nonce_confirms_and_marks_confirmed_not_deleted(): void {
		set_transient( 'mcpwp_broker_enroll_confirmNonce1', array( 'state' => 'pending' ), 300 );

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

		// NOT deleted — moved to 'confirmed' so /broker/enroll-store can consume it.
		$entry = get_transient( 'mcpwp_broker_enroll_confirmNonce1' );
		$this->assertIsArray( $entry, 'A confirmed nonce must remain readable for the store step.' );
		$this->assertSame( 'confirmed', $entry['state'] );
	}

	public function test_confirm_same_nonce_twice_second_call_returns_false(): void {
		set_transient( 'mcpwp_broker_enroll_confirmNonce2', array( 'state' => 'pending' ), 300 );
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$ctrl    = $this->controller();
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/broker/enroll-confirm', array(
			'nonce' => 'confirmNonce2',
		) );

		$first  = $ctrl->handle_enroll_confirm( $request );
		$second = $ctrl->handle_enroll_confirm( $request );

		$this->assertTrue( $first->get_data()['confirmed'] );
		$this->assertSame( 200, $second->get_status() );
		// Once already confirmed, a second confirm call is no longer a match for
		// the 'pending' predicate — it doesn't break the store step (which is
		// the actual single-use boundary), but confirm itself won't re-affirm.
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
		set_transient( 'mcpwp_broker_enroll_publicNonce6', array( 'state' => 'pending' ), 300 );

		$ctrl     = $this->controller();
		$request  = new WP_REST_Request( 'POST', '/mcpwp/v1/broker/enroll-confirm', array(
			'nonce' => 'publicNonce6',
		) );
		$response = $ctrl->handle_enroll_confirm( $request );

		$this->assertTrue( $response->get_data()['confirmed'] );
	}

	// ── Nonce length — tightened {8,150} regex (WP transient-key 191-char limit) ──

	public function test_confirm_nonce_longer_than_150_chars_is_rejected(): void {
		// 200 chars: within the OLD {8,255} limit but beyond the tightened {8,150}.
		$overlong_nonce = str_repeat( 'a', 200 );
		set_transient( 'mcpwp_broker_enroll_' . $overlong_nonce, array( 'state' => 'pending' ), 300 );
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$ctrl     = $this->controller();
		$request  = new WP_REST_Request( 'POST', '/mcpwp/v1/broker/enroll-confirm', array(
			'nonce' => $overlong_nonce,
		) );
		$response = $ctrl->handle_enroll_confirm( $request );

		$this->assertFalse(
			$response->get_data()['confirmed'],
			'A 200-char nonce must be rejected — WordPress transient option names truncate at 191 chars, so an overlong nonce risks silent key truncation.'
		);
	}

	public function test_confirm_nonce_at_150_chars_is_accepted(): void {
		$nonce_150 = str_repeat( 'b', 150 );
		set_transient( 'mcpwp_broker_enroll_' . $nonce_150, array( 'state' => 'pending' ), 300 );
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$ctrl     = $this->controller();
		$request  = new WP_REST_Request( 'POST', '/mcpwp/v1/broker/enroll-confirm', array(
			'nonce' => $nonce_150,
		) );
		$response = $ctrl->handle_enroll_confirm( $request );

		$this->assertTrue( $response->get_data()['confirmed'], 'A 150-char nonce is still within the tightened limit and must be accepted.' );
	}
}
