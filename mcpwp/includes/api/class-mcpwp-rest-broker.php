<?php
/**
 * mumcp.com broker — site-control proof (enrollment handshake).
 *
 * Implements the two REST endpoints that prove an admin, acting inside an
 * authenticated WordPress browser session, initiated enrollment with the
 * mumcp.com sign-in broker:
 *
 *   GET|POST /wp-json/mcpwp/v1/broker/enroll-callback
 *   POST     /wp-json/mcpwp/v1/broker/enroll-confirm
 *
 * Flow:
 *   1. The broker 302s the admin's browser to enroll-callback with a random
 *      `nonce` and a `return` URL (always on mumcp.com) to send the browser
 *      back to once the site has recorded the nonce.
 *   2. enroll-callback is gated on `current_user_can('manage_options')` —
 *      THIS is the site-control proof: only a request carrying a valid,
 *      authenticated wp-admin session cookie can pass. It records the nonce
 *      as PENDING (single-use, 5-minute TTL) and 302-redirects the browser
 *      back to `return`.
 *   3. The broker then calls enroll-confirm server-to-server (no WordPress
 *      session exists for that call — the site has no broker credential yet
 *      at this point in the handshake). enroll-confirm's permission_callback
 *      is intentionally `__return_true`; all of its security comes from the
 *      single-use pending-nonce match, not from WordPress auth.
 *
 * Security properties:
 *  - enroll-callback: admin-only (manage_options), `return` restricted to an
 *    https://mumcp.com/... URL (prevents this endpoint being used as an open
 *    redirect / phishing vector for an attacker-chosen destination).
 *  - enroll-confirm: single-use — the pending transient is deleted BEFORE the
 *    success response is built, so a raced or replayed confirm can only ever
 *    win once. A miss (unknown, expired, or already-consumed nonce) returns a
 *    clean `{confirmed:false}`, never a 500 and never a distinguishing error
 *    code, so the response leaks nothing about why it failed.
 *
 * @package MCPWP
 * @since   3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Broker enrollment REST controller.
 */
class Mcpwp_REST_Broker extends Mcpwp_REST_API {

	/**
	 * Transient key prefix for a pending (not-yet-confirmed) enrollment nonce.
	 *
	 * @var string
	 */
	const PENDING_TRANSIENT_PREFIX = 'mcpwp_broker_pending_';

	/**
	 * Seconds a pending enrollment nonce stays valid before it must be re-issued.
	 *
	 * @var int
	 */
	const PENDING_TTL = 300;

	/**
	 * The only host a `return` URL may point at.
	 *
	 * @var string
	 */
	const ALLOWED_RETURN_HOST = 'mumcp.com';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/broker/enroll-callback',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_enroll_callback' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_enroll_callback' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/broker/enroll-confirm',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_enroll_confirm' ),
					// Intentionally public: the broker calls this server-to-server,
					// before the site holds any broker credential to authenticate
					// with. Security is the single-use pending-nonce match below,
					// not WordPress auth — see class docblock.
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permission callback — proves an admin's browser session initiated this.
	// -------------------------------------------------------------------------

	/**
	 * Whether the current request carries an authenticated admin session.
	 *
	 * This is the site-control proof: only a request with a valid,
	 * authenticated wp-admin session cookie (an admin logged in to THIS site)
	 * can pass. Returns the boolean straight from current_user_can() on
	 * success so the contract matches "a method returning
	 * current_user_can('manage_options')" exactly; on failure it returns a
	 * WP_Error carrying a proper 401 (no session) or 403 (session, wrong
	 * role) status instead of a bare false, so REST clients get an
	 * informative response rather than WordPress's generic default.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function check_admin_permission( $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'This endpoint requires an authenticated WordPress administrator session.', 'mcpwp' ),
			array( 'status' => 0 !== get_current_user_id() ? 403 : 401 )
		);
	}

	// -------------------------------------------------------------------------
	// GET|POST /broker/enroll-callback
	// -------------------------------------------------------------------------

	/**
	 * Record the broker's nonce as pending and redirect the browser back to it.
	 *
	 * Only reachable once check_admin_permission() has already passed (wired
	 * as this route's permission_callback) — re-checked here too as a cheap
	 * defense-in-depth belt-and-suspenders against a future registration
	 * mistake, since this handler's side effect (minting a pending
	 * enrollment) is exactly what the admin gate exists to protect.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error 302 redirect on success, WP_Error on
	 *                                   invalid input.
	 */
	public function handle_enroll_callback( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_response( 'forbidden', __( 'Administrator session required.', 'mcpwp' ), 403 );
		}

		$nonce = $this->sanitize_broker_nonce( $request->get_param( 'nonce' ) );
		if ( '' === $nonce ) {
			return $this->error_response( 'invalid_nonce', __( 'A valid nonce parameter is required.', 'mcpwp' ), 400 );
		}

		$return_url = $request->get_param( 'return' );
		if ( ! $this->is_allowed_broker_return_url( $return_url ) ) {
			return $this->error_response(
				'invalid_return_url',
				__( 'The return URL must be an https://mumcp.com/... address.', 'mcpwp' ),
				400
			);
		}

		set_transient( self::PENDING_TRANSIENT_PREFIX . $nonce, 1, self::PENDING_TTL );

		$this->log_activity( 'broker_enroll_callback', $request );

		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $return_url );
		return $response;
	}

	// -------------------------------------------------------------------------
	// POST /broker/enroll-confirm
	// -------------------------------------------------------------------------

	/**
	 * Confirm (and consume) a pending enrollment nonce.
	 *
	 * Public endpoint (no WordPress auth) — the broker calls this
	 * server-to-server. The pending transient is deleted BEFORE the success
	 * response is built so the nonce can only ever be confirmed once, even
	 * under a race. Any miss (never issued, expired, or already consumed)
	 * returns a clean `{confirmed:false}` — never a 500, and no distinction
	 * between "never existed" and "already used" is exposed.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Always 200; body carries the boolean result.
	 */
	public function handle_enroll_confirm( $request ) {
		$nonce = $this->sanitize_broker_nonce( $request->get_param( 'nonce' ) );

		$confirmed = false;

		if ( '' !== $nonce ) {
			$key = self::PENDING_TRANSIENT_PREFIX . $nonce;
			if ( false !== get_transient( $key ) ) {
				// Delete first — single-use — before ever returning success.
				delete_transient( $key );
				$confirmed = true;
			}
		}

		$this->log_activity( 'broker_enroll_confirm', $request, array( 'confirmed' => $confirmed ) );

		return $this->success_response( array( 'confirmed' => $confirmed ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Sanitize the broker's opaque nonce token.
	 *
	 * Deliberately NOT sanitize_key() — that lowercases and would corrupt a
	 * case-sensitive random token. Restricted to a conservative URL-safe
	 * charset so it is always safe to splice directly into a transient
	 * option name.
	 *
	 * @param mixed $nonce Raw nonce param.
	 * @return string Sanitized nonce, or '' if missing/malformed.
	 */
	private function sanitize_broker_nonce( $nonce ) {
		$nonce = is_string( $nonce ) ? trim( $nonce ) : '';
		if ( '' === $nonce || ! preg_match( '/^[A-Za-z0-9_-]{8,255}$/', $nonce ) ) {
			return '';
		}
		return $nonce;
	}

	/**
	 * Whether a `return` URL is an allowed https://mumcp.com/... address.
	 *
	 * Rejects anything else outright — including a host that merely contains
	 * "mumcp.com" as a substring (e.g. "mumcp.com.evil.com") — so this
	 * callback can never be used as an open redirect to an attacker-chosen
	 * destination.
	 *
	 * @param mixed $return_url Raw return param.
	 * @return bool
	 */
	private function is_allowed_broker_return_url( $return_url ) {
		if ( ! is_string( $return_url ) || '' === trim( $return_url ) ) {
			return false;
		}

		$parts = wp_parse_url( $return_url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}

		return self::ALLOWED_RETURN_HOST === strtolower( $parts['host'] );
	}
}
