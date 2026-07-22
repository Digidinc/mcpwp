<?php
/**
 * OAuth 2.1 Authorization Server — HTTP surface
 *
 * Implements the four public endpoints required for an MCP OAuth connector:
 *
 *   GET  /.well-known/oauth-protected-resource   (RFC 9728, served at domain root)
 *   GET  /.well-known/oauth-authorization-server (RFC 8414, served at domain root)
 *   GET  /mcpwp-oauth/authorize                  (consent screen — front channel)
 *   POST /mcpwp-oauth/authorize                  (consent submission — front channel)
 *   POST /wp-json/mcpwp/v1/oauth/token           (token exchange — WP REST)
 *
 * The consent endpoint is deliberately NOT a REST route.  WordPress core's
 * rest_cookie_check_errors() calls wp_set_current_user( 0 ) for any cookie-
 * authenticated REST request carrying no X-WP-Nonce, and a browser following
 * an OAuth redirect can never send one — so is_user_logged_in() was always
 * false inside the handler and the login redirect pointed straight back at the
 * same nonce-less URL (infinite wp-login loop).  It is served instead through
 * the do_parse_request interceptor in Mcpwp_OAuth_Well_Known, where cookie
 * auth is already resolved.  The old REST route stays registered and
 * 307-redirects to the front channel so clients that cached the previous
 * authorization_endpoint keep working.
 *
 * The token backend (issue_oauth_access_token, authenticate_oauth_access_token,
 * get_oauth_settings, verify_oauth_client_credentials) lives in trait-mcpwp-api-auth.php
 * and is reused via the Mcpwp_REST_API base class.
 *
 * Security properties enforced here:
 *  - PKCE S256 mandatory; plain/absent rejected at authorize AND token steps.
 *  - redirect_uri must exact-match an entry in oauth_redirect_uris allow-list.
 *  - Auth codes are single-use (transient deleted on first use) with 60s TTL.
 *  - Code bound to client_id + redirect_uri + code_challenge + user_id + CLAMPED scope.
 *  - Scope clamped to user capability at authorize time and re-validated at token time.
 *  - client_secret OPTIONAL — public clients (PKCE only) work without a secret.
 *  - Refresh token rotation: presented token deleted, new one issued, old can't be reused.
 *  - Refresh token bound to user_id; re-issued token retains user binding.
 *  - client_id verified against stored code at token exchange (P1-E).
 *  - Consent POST protected by a wp_nonce.
 *  - constant-time comparison (hash_equals) everywhere.
 *  - No token/secret material written to any log or query-string persistence.
 *  - All endpoints gated on oauth_enabled setting.
 *
 * @package MCPWP
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth 2.1 REST controller.
 */
class Mcpwp_REST_OAuth extends Mcpwp_REST_API {

	// -------------------------------------------------------------------------
	// Route registration
	// -------------------------------------------------------------------------

	/**
	 * Register REST routes (authorize + token).
	 *
	 * The .well-known endpoints are served via the parse_request hook registered
	 * in Mcpwp_OAuth_Well_Known::init(), not as REST routes.
	 *
	 * NOTE: client_secret is intentionally NOT listed in route args, so it is
	 * never marked required by the WP REST router.  It is read directly from
	 * the raw request params in handle_token_authorization_code().
	 */
	public function register_routes() {
		// Back-compat only.  The interactive endpoint now lives on the front
		// channel (Mcpwp_OAuth_Well_Known::get_front_channel_authorize_url());
		// this route is kept registered so a client that cached the old
		// authorization_endpoint is redirected there instead of looping on
		// wp-login.  It never renders consent itself.
		register_rest_route(
			$this->namespace,
			'/oauth/authorize',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_authorize_legacy_redirect' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_authorize_legacy_redirect' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/oauth/token',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_token' ),
					'permission_callback' => '__return_true',
					// No 'args' with required:true here — client_secret must remain
					// optional for public clients (PKCE-only, no secret).
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// GET /oauth/authorize — display consent screen (or redirect to WP login)
	// -------------------------------------------------------------------------

	/**
	 * Handle GET of the authorize endpoint (front channel).
	 *
	 * Validates parameters, forces WP login if the user is not authenticated,
	 * then returns the consent screen.
	 *
	 * Never echoes and never exits: the caller
	 * (Mcpwp_REST_OAuth::serve_front_channel_authorize()) emits the returned
	 * response.  That keeps this whole path unit-testable.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Consent HTML (200), redirect (302 + Location), or an OAuth error.
	 */
	public function handle_authorize_get( $request ) {
		if ( ! $this->is_oauth_enabled() ) {
			return $this->oauth_error_response( 'server_error', 'OAuth is not enabled on this site.', 501 );
		}

		$params       = $this->extract_authorize_params( $request );
		$validate_err = $this->validate_authorize_params( $params );
		if ( is_wp_error( $validate_err ) ) {
			return $this->wpe_to_response( $validate_err );
		}

		// Require logged-in WP user.  Send them back to the FRONT-CHANNEL URL
		// after login — the REST route would see them as logged out again
		// (core strips cookie auth on REST requests without an X-WP-Nonce),
		// which is the wp-login loop this endpoint moved to escape.
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			$callback_url = add_query_arg( $params, Mcpwp_OAuth_Well_Known::get_front_channel_authorize_url() );
			return $this->oauth_redirect_response( wp_login_url( $callback_url ) );
		}

		// Clamp scope to what the current user is actually capable of before
		// rendering the consent screen so users only see what they can get.
		//
		// clamp_scope_to_user_capability() returns string[].  $params['scope']
		// MUST stay a space-separated STRING from here on: it is echoed into
		// the consent screen's hidden field and posted straight back, and a
		// bare array collapses to the literal "Array", which re-parses to
		// ['read'] and silently downgrades every browser-issued token.
		$params['scope'] = implode( ' ', $this->clamp_scope_to_user_capability( $params['scope'] ) );

		return $this->consent_response( $params );
	}

	// -------------------------------------------------------------------------
	// POST /oauth/authorize — process consent form submission
	// -------------------------------------------------------------------------

	/**
	 * Handle POST of the authorize endpoint (consent form submission).
	 *
	 * Never echoes and never exits — see handle_authorize_get().
	 *
	 * The wp_nonce check below is the ONLY CSRF defence on this endpoint:
	 * serving it outside /wp-json removed core's X-WP-Nonce check, so it must
	 * never be relaxed, made conditional, or moved after any state change.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Redirect (302 + Location) or an OAuth error.
	 */
	public function handle_authorize_post( $request ) {
		if ( ! $this->is_oauth_enabled() ) {
			return $this->oauth_error_response( 'server_error', 'OAuth is not enabled on this site.', 501 );
		}

		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return $this->oauth_error_response( 'access_denied', 'Authentication required.', 401 );
		}

		// Verify CSRF nonce.
		$nonce = $request->get_param( '_mcpwp_oauth_nonce' );
		if ( ! wp_verify_nonce( (string) $nonce, 'mcpwp_oauth_consent' ) ) {
			return $this->oauth_error_response( 'invalid_request', 'Invalid or expired nonce.', 400 );
		}

		$params       = $this->extract_authorize_params( $request );
		$validate_err = $this->validate_authorize_params( $params );
		if ( is_wp_error( $validate_err ) ) {
			return $this->wpe_to_response( $validate_err );
		}

		// Consent outcome.  Anything that is not an EXPLICIT approval is a
		// denial: this endpoint mints an authorization code, so an absent,
		// misspelled or attacker-truncated `action` must collapse toward
		// refusing access, never toward granting it.  (The previous
		// `if ( 'deny' === $action )` test fell through to the approval path
		// for every value other than the exact string "deny", including the
		// empty string.)
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( 'approve' !== $action ) {
			$redirect = add_query_arg(
				array(
					'error'             => 'access_denied',
					'error_description' => 'User denied access.',
					'state'             => $params['state'],
				),
				$params['redirect_uri']
			);
			return $this->oauth_redirect_response( $redirect );
		}

		// P0-A: Clamp the requested scope to the logged-in user's capability.
		// Store only the clamped scope in the auth code — never the raw requested scope.
		$clamped_scope = $this->clamp_scope_to_user_capability( $params['scope'] );

		// Approval path — generate auth code.
		$code          = bin2hex( random_bytes( 32 ) );
		$user_id       = get_current_user_id();
		$transient_key = 'mcpwp_oauth_code_' . $code;

		set_transient(
			$transient_key,
			array(
				'client_id'      => $params['client_id'],
				'redirect_uri'   => $params['redirect_uri'],
				'code_challenge' => $params['code_challenge'],
				'scope'          => implode( ' ', $clamped_scope ),
				'user_id'        => $user_id,
				'expires'        => time() + 60,
			),
			60
		);

		$redirect = add_query_arg(
			array_filter( array(
				'code'  => $code,
				'state' => $params['state'],
			) ),
			$params['redirect_uri']
		);

		return $this->oauth_redirect_response( $redirect );
	}

	// -------------------------------------------------------------------------
	// POST /oauth/token — token exchange
	// -------------------------------------------------------------------------

	/**
	 * Handle POST /oauth/token.
	 *
	 * Supports grant_type=authorization_code, grant_type=refresh_token, and
	 * grant_type=client_credentials (legacy machine-to-machine path).
	 *
	 * This is the SINGLE registered handler for POST /mcpwp/v1/oauth/token.
	 * The legacy registration in class-mcpwp-rest-site-updates.php has been
	 * removed to eliminate the route collision that caused WP REST to merge
	 * required:true args (client_id, client_secret) onto this endpoint, breaking
	 * public-client PKCE flows.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_token( $request ) {
		if ( ! $this->is_oauth_enabled() ) {
			return $this->oauth_error_response( 'server_error', 'OAuth is not enabled on this site.', 501 );
		}

		$grant_type = sanitize_key( (string) $request->get_param( 'grant_type' ) );

		if ( 'authorization_code' === $grant_type ) {
			return $this->handle_token_authorization_code( $request );
		}

		if ( 'refresh_token' === $grant_type ) {
			return $this->handle_token_refresh( $request );
		}

		if ( 'client_credentials' === $grant_type ) {
			return $this->handle_token_client_credentials( $request );
		}

		return $this->oauth_error_response( 'unsupported_grant_type', 'Supported grant types: authorization_code, refresh_token, client_credentials.', 400 );
	}

	// -------------------------------------------------------------------------
	// Token exchange — authorization_code
	// -------------------------------------------------------------------------

	/**
	 * Process the authorization_code token exchange.
	 *
	 * Public clients (PKCE only, no client_secret) are explicitly supported per
	 * OAuth 2.1 / MCP Auth Spec.  client_secret is always optional here.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	private function handle_token_authorization_code( $request ) {
		$code          = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$redirect_uri  = esc_url_raw( (string) $request->get_param( 'redirect_uri' ) );
		$code_verifier = sanitize_text_field( (string) $request->get_param( 'code_verifier' ) );
		$client_id     = sanitize_key( (string) $request->get_param( 'client_id' ) );
		// P0-C: client_secret is OPTIONAL — never required. Read it but don't error on absence.
		$client_secret = (string) $request->get_param( 'client_secret' );

		if ( empty( $code ) ) {
			return $this->oauth_error_response( 'invalid_request', 'code is required.', 400 );
		}
		if ( empty( $redirect_uri ) ) {
			return $this->oauth_error_response( 'invalid_request', 'redirect_uri is required.', 400 );
		}
		if ( empty( $code_verifier ) ) {
			return $this->oauth_error_response( 'invalid_request', 'code_verifier is required (PKCE mandatory).', 400 );
		}

		// Read + immediately delete the code transient (single-use).
		$transient_key = 'mcpwp_oauth_code_' . $code;
		$stored        = get_transient( $transient_key );
		delete_transient( $transient_key );

		if ( ! is_array( $stored ) || empty( $stored['code_challenge'] ) ) {
			return $this->oauth_error_response( 'invalid_grant', 'Authorization code is invalid or has expired.', 400 );
		}

		// Expiry double-check (transient TTL should cover this, but be explicit).
		if ( isset( $stored['expires'] ) && time() > (int) $stored['expires'] ) {
			return $this->oauth_error_response( 'invalid_grant', 'Authorization code has expired.', 400 );
		}

		// redirect_uri must match stored value exactly (constant-time).
		if ( ! hash_equals( $stored['redirect_uri'], $redirect_uri ) ) {
			return $this->oauth_error_response( 'invalid_grant', 'redirect_uri mismatch.', 400 );
		}

		// P1-E: client_id must match the client_id that was stored in the code.
		// Prevents code minted for client A being redeemed declaring client B.
		if ( ! empty( $client_id ) && isset( $stored['client_id'] ) ) {
			if ( ! hash_equals( (string) $stored['client_id'], $client_id ) ) {
				return $this->oauth_error_response( 'invalid_grant', 'client_id mismatch.', 400 );
			}
		}

		// PKCE: base64url(SHA256(code_verifier)) must match stored code_challenge.
		$computed_challenge = $this->compute_pkce_challenge( $code_verifier );
		if ( ! hash_equals( (string) $stored['code_challenge'], $computed_challenge ) ) {
			return $this->oauth_error_response( 'invalid_grant', 'PKCE verification failed.', 400 );
		}

		// P0-C: Client credential check is OPTIONAL.
		// - If client_secret is provided: verify against configured secret (confidential client).
		// - If only client_id (no secret): verify client_id matches configured id (public client).
		// - If neither: skip client validation — public client presenting only PKCE (allowed by OAuth 2.1).
		$oauth_settings = $this->get_oauth_settings();
		if ( ! empty( $client_secret ) ) {
			// Confidential client path — secret must verify.
			if ( ! $this->verify_oauth_client_credentials( $client_id, $client_secret ) ) {
				return $this->oauth_error_response( 'invalid_client', 'Invalid client credentials.', 401 );
			}
		} elseif ( ! empty( $client_id ) ) {
			// Public client path — validate client_id only (no secret check).
			if ( ! hash_equals( $oauth_settings['oauth_client_id'], sanitize_key( $client_id ) ) ) {
				return $this->oauth_error_response( 'invalid_client', 'Invalid client_id.', 401 );
			}
		}
		// else: no client_id and no secret — fully anonymous public client; PKCE is the sole proof.

		// Parse scopes from stored value.
		// P0-B: Never fall back to get_default_api_key_scopes() here — that would
		// grant read+write+admin when scope is absent. Use stored (already-clamped) scope.
		$scope_string = isset( $stored['scope'] ) ? (string) $stored['scope'] : '';
		$scopes       = $this->parse_oauth_scope_string( $scope_string );

		// P0-A defence-in-depth: re-clamp at token issuance in case scope was set
		// externally (e.g. seeded via wp eval in tests or by older code).
		// Use the user_id stored in the code — that was the user who consented.
		$stored_user_id = isset( $stored['user_id'] ) ? (int) $stored['user_id'] : 0;
		$scopes         = $this->clamp_scope_array_to_user_id( $scopes, $stored_user_id );

		// Issue token via existing backend.
		$ttl            = (int) $oauth_settings['oauth_token_ttl'];
		$token_response = $this->issue_oauth_access_token_with_user( $scopes, $ttl, $stored_user_id );

		return new WP_REST_Response( $token_response, 200 );
	}

	// -------------------------------------------------------------------------
	// Token exchange — refresh_token
	// -------------------------------------------------------------------------

	/**
	 * Process the refresh_token grant.
	 *
	 * P1-D: Refresh token rotation — the presented refresh token is deleted on
	 * use and a brand-new one is issued.  The old token cannot be reused.
	 * Tokens are bound to user_id.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	private function handle_token_refresh( $request ) {
		$refresh_token = sanitize_text_field( (string) $request->get_param( 'refresh_token' ) );

		if ( empty( $refresh_token ) ) {
			return $this->oauth_error_response( 'invalid_request', 'refresh_token is required.', 400 );
		}

		if ( ! $this->looks_like_oauth_refresh_token( $refresh_token ) ) {
			return $this->oauth_error_response( 'invalid_grant', 'Invalid refresh token format.', 400 );
		}

		$transient_key = $this->get_oauth_refresh_token_transient_key( $refresh_token );
		$record        = get_transient( $transient_key );

		// P1-D: Delete the presented refresh token immediately (rotate before issuing new one).
		// This ensures that even if the new issuance fails, the old token is gone.
		delete_transient( $transient_key );

		if ( ! is_array( $record ) || empty( $record['scopes'] ) ) {
			return $this->oauth_error_response( 'invalid_grant', 'Refresh token is invalid or has expired.', 400 );
		}

		$scopes  = $this->sanitize_scopes( (array) $record['scopes'] );
		$user_id = isset( $record['user_id'] ) ? (int) $record['user_id'] : 0;

		$oauth_settings = $this->get_oauth_settings();
		$ttl            = (int) $oauth_settings['oauth_token_ttl'];

		// Issue a new access token + new refresh token.
		$token_response = $this->issue_oauth_access_token_with_user( $scopes, $ttl, $user_id );

		return new WP_REST_Response( $token_response, 200 );
	}

	// -------------------------------------------------------------------------
	// Token exchange — client_credentials (machine-to-machine)
	// -------------------------------------------------------------------------

	/**
	 * Process the client_credentials grant.
	 *
	 * This reproduces the behavior previously handled by
	 * Mcpwp_REST_Site_Updates::issue_oauth_token() before the route collision fix.
	 *
	 * Security notes:
	 * - Both client_id and client_secret are REQUIRED for this grant (enforced
	 *   in the handler, not as route-level args, so public PKCE flows are unaffected).
	 * - Scopes are NOT user-clamped — there is no WP user in a machine flow.
	 *   However, empty/absent scope defaults to ['read'] (same as authorization_code).
	 *   The caller must explicitly request write or admin.
	 * - No refresh token is issued (parity with legacy behavior).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	private function handle_token_client_credentials( $request ) {
		$rate_limit_check = $this->check_rate_limit( 'oauth-client:' . $this->get_client_ip() );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		$client_id     = sanitize_key( (string) $request->get_param( 'client_id' ) );
		$client_secret = (string) $request->get_param( 'client_secret' );

		if ( empty( $client_id ) ) {
			return $this->oauth_error_response( 'invalid_request', 'client_id is required for client_credentials grant.', 400 );
		}

		if ( empty( $client_secret ) ) {
			return $this->oauth_error_response( 'invalid_request', 'client_secret is required for client_credentials grant.', 400 );
		}

		if ( ! $this->verify_oauth_client_credentials( $client_id, $client_secret ) ) {
			return $this->oauth_error_response( 'invalid_client', 'Invalid client credentials.', 401 );
		}

		$scope_string   = (string) $request->get_param( 'scope' );
		$scopes         = $this->parse_oauth_scope_string( $scope_string );
		$oauth_settings = $this->get_oauth_settings();
		$token_data     = $this->issue_oauth_access_token( $scopes, $oauth_settings['oauth_token_ttl'], array( 'client_id' => $client_id ) );

		return new WP_REST_Response( $token_data, 200 );
	}

	// -------------------------------------------------------------------------
	// Parameter extraction + validation
	// -------------------------------------------------------------------------

	/**
	 * Extract authorize endpoint parameters from request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array Param map.
	 */
	public function extract_authorize_params( $request ) {
		return array(
			'response_type'         => sanitize_key( (string) $request->get_param( 'response_type' ) ),
			'client_id'             => sanitize_key( (string) $request->get_param( 'client_id' ) ),
			'redirect_uri'          => esc_url_raw( (string) $request->get_param( 'redirect_uri' ) ),
			'code_challenge'        => sanitize_text_field( (string) $request->get_param( 'code_challenge' ) ),
			'code_challenge_method' => strtoupper( sanitize_key( (string) $request->get_param( 'code_challenge_method' ) ) ),
			'scope'                 => sanitize_text_field( (string) $request->get_param( 'scope' ) ),
			'state'                 => sanitize_text_field( (string) $request->get_param( 'state' ) ),
		);
	}

	/**
	 * Validate authorize endpoint parameters.
	 *
	 * Returns WP_Error on first validation failure.  Errors are returned to the
	 * caller rather than via redirect because we may not have a valid redirect_uri
	 * yet (and redirecting with an untrusted redirect_uri would be an open-redirect).
	 *
	 * @param array $params Extracted params.
	 * @return true|WP_Error
	 */
	public function validate_authorize_params( $params ) {
		if ( 'code' !== $params['response_type'] ) {
			return new WP_Error( 'invalid_request', 'response_type must be "code".', array( 'status' => 400 ) );
		}

		$oauth_settings = $this->get_oauth_settings();

		// Validate client_id (constant-time).
		if ( empty( $params['client_id'] ) || ! hash_equals( $oauth_settings['oauth_client_id'], $params['client_id'] ) ) {
			return new WP_Error( 'invalid_client', 'Unknown or missing client_id.', array( 'status' => 400 ) );
		}

		// Validate redirect_uri against allow-list.
		if ( empty( $params['redirect_uri'] ) ) {
			return new WP_Error( 'invalid_request', 'redirect_uri is required.', array( 'status' => 400 ) );
		}
		if ( ! $this->is_redirect_uri_allowed( $params['redirect_uri'] ) ) {
			return new WP_Error( 'invalid_request', 'redirect_uri is not on the allow-list.', array( 'status' => 400 ) );
		}

		// PKCE: code_challenge_method must be S256.
		if ( 'S256' !== $params['code_challenge_method'] ) {
			return new WP_Error( 'invalid_request', 'code_challenge_method must be S256.', array( 'status' => 400 ) );
		}

		// PKCE: code_challenge must be present.
		if ( empty( $params['code_challenge'] ) ) {
			return new WP_Error( 'invalid_request', 'code_challenge is required.', array( 'status' => 400 ) );
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Consent screen HTML
	// -------------------------------------------------------------------------

	/**
	 * Normalise an authorize parameter to a string for HTML output.
	 *
	 * Defence in depth for the scope round trip: clamp_scope_to_user_capability()
	 * returns string[], and an array used in string context collapses to the
	 * literal "Array", which sanitize_key()s to 'array', fails the
	 * read|write|admin allow-list, and silently re-parses to ['read'] — i.e.
	 * every browser-issued token gets downgraded to read-only no matter what
	 * the user approved.
	 *
	 * @param mixed $value Parameter value.
	 * @return string
	 */
	private function stringify_param_value( $value ) {
		if ( is_array( $value ) ) {
			return implode( ' ', array_map( 'strval', $value ) );
		}

		return (string) $value;
	}

	/**
	 * Wrap the consent screen in a response carrying its security headers.
	 *
	 * The page embeds a live per-user CSRF nonce and mints an access token on
	 * Approve, so it must never be frameable (clickjacking → token theft) and
	 * must never be cached (nonce + username leak).
	 *
	 * @param array $params Validated authorize parameters (scope already clamped to a string).
	 * @return WP_REST_Response
	 */
	private function consent_response( $params ) {
		$response = new WP_REST_Response( $this->build_consent_screen_html( $params ), 200 );
		$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		$response->header( 'X-Frame-Options', 'DENY' );
		$response->header( 'Content-Security-Policy', "frame-ancestors 'none'" );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Referrer-Policy', 'no-referrer' );

		return $response;
	}

	/**
	 * Build the minimal HTML consent screen.
	 *
	 * All OAuth parameters are embedded as hidden fields.  The CSRF nonce is
	 * generated fresh per render and is the sole CSRF defence on the consent
	 * POST — see handle_authorize_post().
	 *
	 * @param array $params Validated authorize parameters (scope already clamped to a string).
	 * @return string HTML document.
	 */
	public function build_consent_screen_html( $params ) {
		$nonce       = wp_create_nonce( 'mcpwp_oauth_consent' );
		$site_name   = function_exists( 'get_bloginfo' ) ? esc_html( get_bloginfo( 'name' ) ) : 'this site';
		$client_id   = esc_html( $params['client_id'] );
		$scope_value = $this->stringify_param_value( isset( $params['scope'] ) ? $params['scope'] : '' );
		$scope_label = esc_html( '' !== $scope_value ? $scope_value : 'read' );
		$user_login  = '';
		if ( function_exists( 'wp_get_current_user' ) ) {
			$current_user = wp_get_current_user();
			$user_login   = esc_html( $current_user->user_login );
		}

		$post_url = esc_url( Mcpwp_OAuth_Well_Known::get_front_channel_authorize_url() );

		$hidden_fields = '';
		foreach ( $params as $key => $value ) {
			$hidden_fields .= sprintf(
				'<input type="hidden" name="%s" value="%s">',
				esc_attr( $key ),
				esc_attr( $this->stringify_param_value( $value ) )
			);
		}

		return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
		   . '<meta name="viewport" content="width=device-width,initial-scale=1">'
		   . '<title>Authorize &mdash; ' . $site_name . '</title>'
		   . '<style>body{font-family:system-ui,sans-serif;max-width:480px;margin:60px auto;padding:0 16px}'
		   . 'h1{font-size:1.2rem}.scope{background:#f0f4ff;border:1px solid #c7d2fe;border-radius:6px;padding:10px 14px;margin:16px 0}'
		   . '.btn{display:inline-block;padding:10px 24px;border:none;border-radius:4px;cursor:pointer;font-size:1rem}'
		   . '.btn-allow{background:#2563eb;color:#fff;margin-right:8px}'
		   . '.btn-deny{background:#e5e7eb;color:#111}'
		   . '</style></head><body>'
		   . '<h1>Authorize access to <strong>' . $site_name . '</strong></h1>'
		   . '<p><strong>' . $client_id . '</strong> is requesting access'
		   . ( $user_login ? ' as <strong>' . $user_login . '</strong>' : '' ) . '.</p>'
		   . '<div class="scope"><strong>Scopes requested:</strong> ' . $scope_label . '</div>'
		   . '<form method="POST" action="' . $post_url . '">'
		   . $hidden_fields
		   . '<input type="hidden" name="_mcpwp_oauth_nonce" value="' . esc_attr( $nonce ) . '">'
		   . '<button type="submit" name="action" value="approve" class="btn btn-allow">Approve</button>'
		   . '<button type="submit" name="action" value="deny" class="btn btn-deny">Deny</button>'
		   . '</form></body></html>';
	}

	// -------------------------------------------------------------------------
	// Scope clamping — P0-A / P0-B
	// -------------------------------------------------------------------------

	/**
	 * Clamp a requested scope string to the current logged-in user's capability.
	 *
	 * Mapping (highest grant wins, silently downgrade if not capable):
	 *   admin  — requires current_user_can('manage_options')
	 *   write  — requires current_user_can('edit_posts')
	 *   read   — always granted for any logged-in user
	 *
	 * P0-B: Empty/absent scope defaults to ['read'] only (never admin).
	 *
	 * @param string $scope_string Space-separated requested scopes (may be empty).
	 * @return string[] Clamped scope array.
	 */
	public function clamp_scope_to_user_capability( $scope_string ) {
		// P0-B: empty scope must default to read only — never call get_default_api_key_scopes().
		$requested = $this->parse_oauth_scope_string( $scope_string );

		return $this->clamp_scope_array_to_user_cap_current( $requested );
	}

	/**
	 * Clamp a scope array to what the current user is capable of.
	 *
	 * Internal helper used at authorize time (current user is known).
	 *
	 * @param string[] $requested Parsed requested scopes.
	 * @return string[] Clamped scope array.
	 */
	private function clamp_scope_array_to_user_cap_current( $requested ) {
		// Determine the ceiling for this user.
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			$ceiling = 'admin';
		} elseif ( function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' ) ) {
			$ceiling = 'write';
		} else {
			$ceiling = 'read';
		}

		return $this->apply_scope_ceiling( $requested, $ceiling );
	}

	/**
	 * Clamp a scope array to what a specific user_id is capable of.
	 *
	 * Used at token issuance (defence-in-depth re-clamp) where we have a stored
	 * user_id but the current logged-in user may differ (token endpoint is not
	 * protected by interactive session).
	 *
	 * NOTE: In the test harness, current_user_can() is driven by
	 * $GLOBALS['mcpwp_test_current_user']; there is no get_user_by+caps lookup.
	 * For production this method checks the stored user's capabilities.
	 *
	 * @param string[] $scopes  Scope array to clamp.
	 * @param int      $user_id WordPress user ID.
	 * @return string[] Clamped scope array.
	 */
	public function clamp_scope_array_to_user_id( $scopes, $user_id ) {
		if ( $user_id <= 0 ) {
			// No user bound — default to read only.
			return array( 'read' );
		}

		// Ask WordPress about this specific user's caps.
		$ceiling = 'read';
		if ( function_exists( 'user_can' ) ) {
			if ( user_can( $user_id, 'manage_options' ) ) {
				$ceiling = 'admin';
			} elseif ( user_can( $user_id, 'edit_posts' ) ) {
				$ceiling = 'write';
			}
		} elseif ( function_exists( 'get_userdata' ) ) {
			// Fallback: load user and check roles/caps directly.
			$user = get_userdata( $user_id );
			if ( $user ) {
				$roles = isset( $user->roles ) ? (array) $user->roles : array();
				if ( in_array( 'administrator', $roles, true ) ) {
					$ceiling = 'admin';
				} elseif ( array_intersect( $roles, array( 'editor', 'author', 'contributor' ) ) ) {
					$ceiling = 'write';
				}
			}
		}

		return $this->apply_scope_ceiling( $scopes, $ceiling );
	}

	/**
	 * Apply a scope ceiling — drop any scopes above the ceiling.
	 *
	 * When all requested scopes are above the ceiling, grant the ceiling itself.
	 * Example: editor (ceiling=write) requesting admin → gets ['read','write'].
	 * Example: subscriber (ceiling=read) requesting admin → gets ['read'].
	 *
	 * @param string[] $requested Requested scopes.
	 * @param string   $ceiling   Maximum scope: 'read', 'write', or 'admin'.
	 * @return string[] Clamped scope array (always at least ['read']).
	 */
	private function apply_scope_ceiling( $requested, $ceiling ) {
		$ceiling_rank = $this->scope_rank( $ceiling );

		$clamped = array();
		foreach ( $requested as $s ) {
			if ( $this->scope_rank( $s ) <= $ceiling_rank ) {
				$clamped[] = $s;
			}
			// Silently drop scopes above ceiling — no error.
		}

		// When nothing was kept (all requested scopes exceeded the ceiling),
		// grant the ceiling itself rather than dropping to bare 'read'.
		// This ensures an editor requesting admin actually gets write (their ceiling).
		if ( empty( $clamped ) ) {
			$clamped = array( $ceiling );
		}

		// Expand implied scopes (admin implies write+read, write implies read).
		if ( in_array( 'admin', $clamped, true ) ) {
			return array( 'read', 'write', 'admin' );
		}
		if ( in_array( 'write', $clamped, true ) ) {
			return array( 'read', 'write' );
		}

		return array( 'read' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Compute a PKCE S256 code challenge from a verifier.
	 *
	 * base64url( SHA256( code_verifier ) ) — no padding.
	 *
	 * @param string $verifier Raw code verifier string.
	 * @return string Base64url-encoded challenge without padding.
	 */
	public function compute_pkce_challenge( $verifier ) {
		$hash = hash( 'sha256', $verifier, true );
		return rtrim( strtr( base64_encode( $hash ), '+/', '-_' ), '=' );
	}

	/**
	 * Check whether a redirect_uri is in the configured allow-list.
	 *
	 * Comparison is exact-match on the full URI string (case-sensitive).
	 * Wildcard matching is never used.
	 *
	 * @param string $redirect_uri URI to check.
	 * @return bool True when on the allow-list.
	 */
	public function is_redirect_uri_allowed( $redirect_uri ) {
		$allowed = $this->get_allowed_redirect_uris();
		foreach ( $allowed as $allowed_uri ) {
			if ( hash_equals( $allowed_uri, $redirect_uri ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the configured redirect URI allow-list.
	 *
	 * Reads `oauth_redirect_uris` from mcpwp_settings.  Supports both an array
	 * (saved by a future settings UI) and a newline-separated string (admin
	 * option textarea).  Falls back to an empty array — no redirect_uri is
	 * allowed unless explicitly configured.
	 *
	 * @return string[]
	 */
	public function get_allowed_redirect_uris() {
		$settings = get_option( 'mcpwp_settings', array() );
		$raw      = isset( $settings['oauth_redirect_uris'] ) ? $settings['oauth_redirect_uris'] : array();

		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\r\n]+/', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'esc_url_raw', array_map( 'trim', $raw ) ) ) );
	}

	/**
	 * Parse a space-separated OAuth scope string into an array.
	 *
	 * P0-B: Unlike sanitize_scopes(), an empty/absent OAuth scope string returns
	 * ['read'] only — never the API-key default of ['read','write','admin'].
	 * This method must NOT call get_default_api_key_scopes().
	 *
	 * @param string $scope_string Space-separated scopes.
	 * @return array Sanitized scope array (at least ['read']).
	 */
	public function parse_oauth_scope_string( $scope_string ) {
		$scope_string = trim( (string) $scope_string );

		if ( '' === $scope_string ) {
			// P0-B: empty scope → read only.
			return array( 'read' );
		}

		$allowed = array( 'read', 'write', 'admin' );
		$parts   = preg_split( '/\s+/', $scope_string, -1, PREG_SPLIT_NO_EMPTY );
		$scopes  = array();
		foreach ( $parts as $s ) {
			$s = sanitize_key( $s );
			if ( in_array( $s, $allowed, true ) ) {
				$scopes[] = $s;
			}
		}

		// If nothing valid was parsed, fall back to read.
		if ( empty( $scopes ) ) {
			return array( 'read' );
		}

		return array_values( array_unique( $scopes ) );
	}

	/**
	 * Issue an OAuth access token plus a new refresh token, bound to a user.
	 *
	 * Wraps issue_oauth_access_token() from the auth trait and additionally
	 * creates a refresh token transient (P1-D: rotation on use).
	 *
	 * @param string[] $scopes  Granted scopes.
	 * @param int      $ttl     Access token TTL in seconds.
	 * @param int      $user_id WordPress user ID who consented.
	 * @return array Token response payload including refresh_token.
	 */
	private function issue_oauth_access_token_with_user( $scopes, $ttl, $user_id ) {
		$token_response = $this->issue_oauth_access_token( $scopes, $ttl, array( 'user_id' => (int) $user_id ) );

		// Issue a refresh token (separate, longer-lived transient).
		// Refresh TTL = 30 days max or 10x access TTL, whichever is smaller.
		$refresh_ttl    = min( 30 * DAY_IN_SECONDS, $ttl * 10 );
		$refresh_token  = 'mcpwp_rt_' . bin2hex( random_bytes( 32 ) );
		$refresh_record = array(
			'scopes'     => $scopes,
			'user_id'    => $user_id,
			'created_at' => time(),
			'expires_at' => time() + $refresh_ttl,
		);

		set_transient(
			$this->get_oauth_refresh_token_transient_key( $refresh_token ),
			$refresh_record,
			$refresh_ttl
		);

		$token_response['refresh_token'] = $refresh_token;

		return $token_response;
	}

	/**
	 * Check whether a token looks like a generated OAuth refresh token.
	 *
	 * @param string $token Token string.
	 * @return bool
	 */
	private function looks_like_oauth_refresh_token( $token ) {
		return 0 === strpos( (string) $token, 'mcpwp_rt_' );
	}

	/**
	 * Get the transient key for a refresh token.
	 *
	 * @param string $refresh_token Refresh token string.
	 * @return string Transient key.
	 */
	public function get_oauth_refresh_token_transient_key( $refresh_token ) {
		return 'mcpwp_oauth_rt_' . md5( (string) $refresh_token );
	}

	/**
	 * Check if OAuth is enabled.
	 *
	 * @return bool
	 */
	private function is_oauth_enabled() {
		$settings = $this->get_oauth_settings();
		return ! empty( $settings['oauth_enabled'] );
	}

	/**
	 * Build an RFC 6749-compliant error REST response.
	 *
	 * @param string $error             RFC 6749 error code.
	 * @param string $error_description Human-readable description.
	 * @param int    $status            HTTP status code.
	 * @return WP_REST_Response
	 */
	public function oauth_error_response( $error, $error_description, $status = 400 ) {
		return new WP_REST_Response(
			array(
				'error'             => $error,
				'error_description' => $error_description,
			),
			$status
		);
	}

	/**
	 * Convert a WP_Error to a WP_REST_Response in OAuth error format.
	 *
	 * @param WP_Error $error WP_Error instance.
	 * @return WP_REST_Response
	 */
	private function wpe_to_response( $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		return $this->oauth_error_response( $error->get_error_code(), $error->get_error_message(), $status );
	}

	/**
	 * Build a redirect response.
	 *
	 * Returned rather than emitted so the authorize handlers stay side-effect
	 * free and unit-testable.  serve_front_channel_authorize() turns this into
	 * a real redirect on the front channel; the WP REST server sends the
	 * Location header + status verbatim on the legacy route.
	 *
	 * @param string $location Absolute URL to redirect to.
	 * @param int    $status   HTTP status code (302 by default).
	 * @return WP_REST_Response
	 */
	public function oauth_redirect_response( $location, $status = 302 ) {
		$response = new WP_REST_Response( null, $status );
		$response->header( 'Location', $location );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Back-compat shim for the old REST authorize route.
	 *
	 * A client that cached authorization_endpoint = /wp-json/mcpwp/v1/oauth/authorize
	 * still works: it is bounced to the front channel, where cookie auth is
	 * honoured.  307 (not 302) so a stale consent POST keeps its method and
	 * body.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_authorize_legacy_redirect( $request ) {
		if ( ! $this->is_oauth_enabled() ) {
			return $this->oauth_error_response( 'server_error', 'OAuth is not enabled on this site.', 501 );
		}

		$target = Mcpwp_OAuth_Well_Known::get_front_channel_authorize_url();

		// Carry the query string across for GET.  A POST keeps its body under
		// 307, so re-appending its fields to the URL would only duplicate them
		// (and put state/code_challenge in a Referer-visible place).
		if ( 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			$params = array_filter( $this->extract_authorize_params( $request ) );
			if ( ! empty( $params ) ) {
				$target = add_query_arg( $params, $target );
			}
		}

		return $this->oauth_redirect_response( $target, 307 );
	}

	// -------------------------------------------------------------------------
	// Front-channel authorize (served outside /wp-json)
	// -------------------------------------------------------------------------

	/**
	 * Serve the front-channel authorize endpoint and exit.
	 *
	 * Called from Mcpwp_OAuth_Well_Known::maybe_serve_well_known() on the
	 * do_parse_request filter — i.e. from inside wp(), long after
	 * wp-settings.php ran $GLOBALS['wp']->init(), so the current user is
	 * established and is_user_logged_in() is reliable here (unlike on the REST
	 * route, where core zeroes it out without an X-WP-Nonce).
	 *
	 * Never returns.
	 */
	public static function serve_front_channel_authorize() {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		$request = new WP_REST_Request( $method, '/mcpwp/v1/oauth/authorize' );

		// phpcs:disable WordPress.Security.NonceVerification -- Front-channel OAuth entry point; the consent POST carries its own CSRF nonce, verified in handle_authorize_post(). Every value is sanitized in extract_authorize_params().
		$request->set_query_params( wp_unslash( $_GET ) );
		if ( 'POST' === $method ) {
			$request->set_body_params( wp_unslash( $_POST ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification

		$controller = new self();
		$response   = ( 'POST' === $method )
			? $controller->handle_authorize_post( $request )
			: $controller->handle_authorize_get( $request );

		self::emit_front_channel_response( $response );
		exit;
	}

	/**
	 * Emit a WP_REST_Response outside the REST server.
	 *
	 * @param WP_REST_Response $response Response to emit.
	 */
	private static function emit_front_channel_response( $response ) {
		// Discard anything a theme or plugin already buffered.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$status  = $response->get_status();
		$headers = $response->get_headers();

		if ( ! empty( $headers['Location'] ) ) {
			wp_redirect( $headers['Location'], $status );
			return;
		}

		status_header( $status );
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}

		$data = $response->get_data();
		if ( is_string( $data ) ) {
			// Consent markup, already escaped field-by-field in build_consent_screen_html().
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $data;
			return;
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( $data );
	}
}
