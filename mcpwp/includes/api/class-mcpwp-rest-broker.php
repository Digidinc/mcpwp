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
 *  - enroll-store: `issuer` and `introspection_endpoint` are host-locked to
 *    https://mumcp.com (same check as `return`, see is_allowed_mumcp_url()) —
 *    Mcpwp_Broker_Auth::validate_token() sends the decrypted site credential
 *    to whatever `introspection_endpoint` this endpoint stores, so an
 *    unvalidated value here would be a credential-exfiltration / auth-bypass
 *    vector, not just an open redirect.
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
	 * Transient key prefix for an enrollment nonce record.
	 *
	 * Value shape: array( 'state' => 'pending' | 'confirmed' ).
	 *   - enroll-callback sets it to 'pending' once an admin's session proves
	 *     site control.
	 *   - enroll-confirm (broker, S2S) flips 'pending' -> 'confirmed'.
	 *   - enroll-store (broker, S2S) consumes a 'confirmed' record and deletes
	 *     it — the single-use boundary for credential delivery.
	 *
	 * @var string
	 */
	const ENROLL_TRANSIENT_PREFIX = 'mcpwp_broker_enroll_';

	/**
	 * Seconds an enrollment nonce record stays valid before it must be re-issued.
	 *
	 * @var int
	 */
	const PENDING_TTL = 300;

	/**
	 * Transient key prefix for the CSRF `state` token minted by
	 * Mcpwp_Broker_Admin::handle_connect() and verified here on the callback.
	 *
	 * Value: the WordPress user id that initiated the connect flow.
	 *
	 * @var string
	 */
	const STATE_TRANSIENT_PREFIX = 'mcpwp_broker_state_';

	/**
	 * Seconds a CSRF state token stays valid before it must be re-issued.
	 *
	 * @var int
	 */
	const STATE_TTL = 600;

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

		register_rest_route(
			$this->namespace,
			'/broker/enroll-store',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_enroll_store' ),
					// Intentionally public — same rationale as enroll-confirm: the
					// broker calls this server-to-server with no WordPress session.
					// Security is the CONFIRMED-nonce match, single-use before any
					// write happens. See handle_enroll_store() docblock.
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
	 * CSRF defense (Plan 2 / Task 5): the admin session cookie alone proves
	 * "an admin's browser hit this URL" but not "this admin's browser chose
	 * to start THIS enrollment" — a logged-in admin could be tricked into
	 * loading this URL cross-site with an attacker-supplied nonce/return.
	 * The `state` param closes that gap: it was minted and stored server-side
	 * by Mcpwp_Broker_Admin::handle_connect() only when the admin themselves
	 * clicked "Connect" (with its own WP nonce check), keyed to their user id.
	 * A request lacking a state transient that matches BOTH the token and the
	 * current user is rejected — the state is consumed (deleted) before any
	 * further processing either way, so it can never be replayed.
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
		if ( ! $this->is_allowed_mumcp_url( $return_url ) ) {
			return $this->error_response(
				'invalid_return_url',
				__( 'The return URL must be an https://mumcp.com/... address.', 'mcpwp' ),
				400
			);
		}

		$state = $this->sanitize_broker_state( $request->get_param( 'state' ) );
		if ( '' === $state ) {
			return $this->error_response( 'invalid_state', __( 'A valid state parameter is required.', 'mcpwp' ), 403 );
		}

		$state_key       = self::STATE_TRANSIENT_PREFIX . $state;
		$state_user_id   = get_transient( $state_key );
		$current_user_id = get_current_user_id();
		if ( false === $state_user_id || (int) $state_user_id !== (int) $current_user_id ) {
			// Delete on a mismatch too — a state value that's floating around
			// mismatched is not worth keeping alive for a second attempt.
			delete_transient( $state_key );
			return $this->error_response( 'invalid_state', __( 'The state parameter did not match this session.', 'mcpwp' ), 403 );
		}
		// Single-use — consume the CSRF token now that it has proven this
		// browser session initiated the connect flow.
		delete_transient( $state_key );

		set_transient( self::ENROLL_TRANSIENT_PREFIX . $nonce, array( 'state' => 'pending' ), self::PENDING_TTL );

		$this->log_activity( 'broker_enroll_callback', $request );

		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $return_url );
		return $response;
	}

	// -------------------------------------------------------------------------
	// POST /broker/enroll-confirm
	// -------------------------------------------------------------------------

	/**
	 * Confirm a pending enrollment nonce (PENDING -> CONFIRMED).
	 *
	 * Public endpoint (no WordPress auth) — the broker calls this
	 * server-to-server. Unlike the pre-Task-5 behavior, confirming does NOT
	 * delete the record: it flips its state from 'pending' to 'confirmed' so
	 * that the subsequent /broker/enroll-store call (the actual single-use
	 * boundary — see handle_enroll_store()) has something to consume. A
	 * record already in the 'confirmed' state (or never issued, expired, or
	 * unknown) is not a 'pending' match, so confirming it again yields a
	 * clean `{confirmed:false}` rather than re-affirming — no distinction
	 * between "never existed" and "already confirmed" is exposed.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Always 200; body carries the boolean result.
	 */
	public function handle_enroll_confirm( $request ) {
		$nonce = $this->sanitize_broker_nonce( $request->get_param( 'nonce' ) );

		$confirmed = false;

		if ( '' !== $nonce ) {
			$key   = self::ENROLL_TRANSIENT_PREFIX . $nonce;
			$entry = get_transient( $key );
			if ( is_array( $entry ) && isset( $entry['state'] ) && 'pending' === $entry['state'] ) {
				set_transient( $key, array( 'state' => 'confirmed' ), self::PENDING_TTL );
				$confirmed = true;
			}
		}

		$this->log_activity( 'broker_enroll_confirm', $request, array( 'confirmed' => $confirmed ) );

		return $this->success_response( array( 'confirmed' => $confirmed ) );
	}

	// -------------------------------------------------------------------------
	// POST /broker/enroll-store
	// -------------------------------------------------------------------------

	/**
	 * Store the broker-issued site credential for a CONFIRMED enrollment.
	 *
	 * Public endpoint (no WordPress auth) — the broker calls this
	 * server-to-server, per the enroll-store contract in
	 * `broker-patch-report.md`. Security is entirely the CONFIRMED-nonce
	 * match: only a nonce this site itself answered `{confirmed:true}` for
	 * (via handle_enroll_confirm()) can cause a write here. The record is
	 * deleted BEFORE the option is written — single-use, so a replay of the
	 * same nonce (network retry, or an attacker capturing the S2S call)
	 * cannot re-deliver or overwrite the credential a second time.
	 *
	 * `issuer` and `introspection_endpoint` are host-locked to
	 * https://mumcp.com (see is_allowed_mumcp_url()) before anything is
	 * written. Mcpwp_Broker_Auth::validate_token() later POSTs the decrypted
	 * site_credential, Basic-auth-encoded, straight to whatever
	 * `introspection_endpoint` is on file — without this lock, a spoofed S2S
	 * enroll-store call (or a confused-deputy broker bug) could park an
	 * attacker's own endpoint there and have this site hand it the site
	 * credential plus every bearer token it is ever asked to validate.
	 *
	 * The plaintext `site_credential` exists only for the lifetime of this
	 * request: it is never returned in the response, never passed to
	 * log_activity() (which would otherwise redact it, but the safest rule is
	 * to never hand it to the logger at all), and is encrypted at rest via
	 * Mcpwp_Encryption before update_option() persists it.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Always 200; body carries the boolean result.
	 */
	public function handle_enroll_store( $request ) {
		$nonce = $this->sanitize_broker_nonce( $request->get_param( 'nonce' ) );
		if ( '' === $nonce ) {
			return $this->success_response( array( 'stored' => false ) );
		}

		$key   = self::ENROLL_TRANSIENT_PREFIX . $nonce;
		$entry = get_transient( $key );
		if ( ! is_array( $entry ) || ! isset( $entry['state'] ) || 'confirmed' !== $entry['state'] ) {
			return $this->success_response( array( 'stored' => false ) );
		}

		// Consume now — single-use — before any write is attempted, so a
		// failure below (e.g. encryption unavailable) still burns the nonce
		// rather than leaving it replayable.
		delete_transient( $key );

		$site_id                = (string) $request->get_param( 'site_id' );
		$audience               = (string) $request->get_param( 'audience' );
		$issuer                 = (string) $request->get_param( 'issuer' );
		$introspection_endpoint = (string) $request->get_param( 'introspection_endpoint' );
		$site_credential        = (string) $request->get_param( 'site_credential' );

		if ( '' === $site_id || '' === $audience || '' === $introspection_endpoint || '' === $site_credential ) {
			return $this->success_response( array( 'stored' => false ) );
		}

		// Host-lock: `issuer` and `introspection_endpoint` are the only two
		// enrollment fields later used to make an authenticated, credential-
		// bearing request (Mcpwp_Broker_Auth::validate_token() POSTs the
		// decrypted site_credential, Basic-auth-encoded, to whatever
		// `introspection_endpoint` says). A poisoned S2S enroll-store call
		// with an attacker-controlled `introspection_endpoint` would make this
		// site exfiltrate its own broker credential (and every bearer token it
		// is ever asked to validate) to that attacker host on every request.
		// Both must resolve to the same https://mumcp.com origin the `return`
		// URL is already locked to — same helper, same rejection shape as
		// every other malformed-input path here: a clean `{stored:false}`,
		// nothing written, nothing distinguishing why.
		if ( ! $this->is_allowed_mumcp_url( $issuer ) || ! $this->is_allowed_mumcp_url( $introspection_endpoint ) ) {
			return $this->success_response( array( 'stored' => false ) );
		}

		$encrypted = Mcpwp_Encryption::get_instance()->encrypt( $site_credential );
		if ( false === $encrypted ) {
			return $this->success_response( array( 'stored' => false ) );
		}

		update_option(
			'mcpwp_broker_enrollment',
			array(
				'issuer'                 => $issuer,
				'site_id'                => $site_id,
				'audience'               => $audience,
				'introspection_endpoint' => $introspection_endpoint,
				'site_credential'        => $encrypted,
			),
			false
		);

		// Log the fact of enrollment, never the credential. log_activity()
		// auto-captures $request->get_params() into the activity log when
		// site-level logging is enabled, and 'site_credential' is not
		// guaranteed to be in every site's (possibly customized)
		// log_redaction_keys list — so pass a request built from only the
		// non-secret fields rather than the raw $request, guaranteeing the
		// plaintext credential can never reach the log regardless of that
		// setting.
		$log_request = new WP_REST_Request( $request->get_method(), $request->get_route() );
		$log_request->set_param( 'nonce', $nonce );
		$log_request->set_param( 'site_id', $site_id );
		$log_request->set_param( 'audience', $audience );
		$log_request->set_param( 'issuer', $issuer );
		$log_request->set_param( 'introspection_endpoint', $introspection_endpoint );
		$this->log_activity( 'broker_enroll_store', $log_request, array( 'stored' => true ) );

		return $this->success_response( array( 'stored' => true ) );
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
	 * Length capped at 150 (not 255): a transient is stored as a
	 * `wp_options.option_name`, capped at 191 chars by WordPress's own schema.
	 * `self::ENROLL_TRANSIENT_PREFIX` is 20 chars, so 150 leaves comfortable
	 * headroom — a longer value used to be silently truncated by the DB
	 * layer instead of rejected outright, which could make two different
	 * over-length nonces collide on the same transient key.
	 *
	 * @param mixed $nonce Raw nonce param.
	 * @return string Sanitized nonce, or '' if missing/malformed.
	 */
	private function sanitize_broker_nonce( $nonce ) {
		$nonce = is_string( $nonce ) ? trim( $nonce ) : '';
		if ( '' === $nonce || ! preg_match( '/^[A-Za-z0-9_-]{8,150}$/', $nonce ) ) {
			return '';
		}
		return $nonce;
	}

	/**
	 * Sanitize the CSRF `state` token minted by Mcpwp_Broker_Admin::handle_connect().
	 *
	 * Same charset/length constraints as the nonce — it is spliced into a
	 * transient option name via self::STATE_TRANSIENT_PREFIX the same way.
	 *
	 * @param mixed $state Raw state param.
	 * @return string Sanitized state, or '' if missing/malformed.
	 */
	private function sanitize_broker_state( $state ) {
		$state = is_string( $state ) ? trim( $state ) : '';
		if ( '' === $state || ! preg_match( '/^[A-Za-z0-9_-]{8,150}$/', $state ) ) {
			return '';
		}
		return $state;
	}

	/**
	 * Whether a URL is an allowed https://mumcp.com/... address.
	 *
	 * Shared host-lock used for every broker-supplied URL this plugin will
	 * ever redirect to OR send an authenticated, credential-bearing request
	 * to: the `return` URL (enroll-callback), and `issuer` /
	 * `introspection_endpoint` (enroll-store — see handle_enroll_store()).
	 * Rejects anything else outright — including a host that merely contains
	 * "mumcp.com" as a substring (e.g. "mumcp.com.evil.com") or userinfo
	 * tricks (e.g. "https://mumcp.com@evil.com") — so none of these fields
	 * can ever be turned into an open redirect or, worse, a exfiltration
	 * target for a decrypted site credential.
	 *
	 * @param mixed $url Raw URL param.
	 * @return bool
	 */
	private function is_allowed_mumcp_url( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}

		return self::ALLOWED_RETURN_HOST === strtolower( $parts['host'] );
	}
}
