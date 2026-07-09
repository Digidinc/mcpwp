<?php
/**
 * OAuth 2.1 Authorization Server — HTTP surface
 *
 * Implements the four public endpoints required for an MCP OAuth connector:
 *
 *   GET  /.well-known/oauth-protected-resource   (RFC 9728, served at domain root)
 *   GET  /.well-known/oauth-authorization-server (RFC 8414, served at domain root)
 *   GET  /wp-json/mcpwp/v1/oauth/authorize       (interactive consent — WP REST)
 *   POST /wp-json/mcpwp/v1/oauth/token           (token exchange — WP REST)
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

	/**
	 * Maximum dynamically-registered OAuth clients retained in wp_options.
	 * Bounds the unauthenticated DCR endpoint against option bloat / DoS.
	 */
	const DCR_CLIENT_MAX = 100;

	/**
	 * Seconds a dynamic client is kept before it is eligible for GC pruning.
	 * 30 days — long enough for legitimate reconnects, short enough to shed
	 * abandoned registrations.
	 */
	const DCR_CLIENT_TTL = 2592000;

	/**
	 * Maximum stored length of a dynamic client's client_name.
	 */
	const DCR_CLIENT_NAME_MAX = 120;

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
		register_rest_route(
			$this->namespace,
			'/oauth/authorize',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_authorize_get' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_authorize_post' ),
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

		register_rest_route(
			$this->namespace,
			'/oauth/register',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_register' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// POST /oauth/register — Dynamic Client Registration
	// -------------------------------------------------------------------------

	/**
	 * Register an OAuth client dynamically.
	 *
	 * MCP clients such as ChatGPT can discover this URL from authorization-server
	 * metadata and obtain a client_id without the site owner manually pre-filling
	 * OAuth connector settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_register( $request ) {
		if ( ! $this->is_oauth_enabled() ) {
			return $this->oauth_error_response( 'server_error', 'OAuth is not enabled on this site.', 501 );
		}

		// Key the DCR throttle off REMOTE_ADDR, not forwarded IP headers: this
		// endpoint is unauthenticated, and get_client_ip() trusts spoofable
		// CF/X-Forwarded-For headers, letting an attacker mint unlimited
		// rate-limit buckets. Only trust proxy headers behind an explicit opt-in.
		$rate_limit_check = $this->check_rate_limit( 'oauth-register:' . $this->get_oauth_register_ip() );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$redirect_uris = $this->sanitize_registration_redirect_uris( $params['redirect_uris'] ?? array() );
		if ( empty( $redirect_uris ) ) {
			return $this->oauth_error_response( 'invalid_client_metadata', 'redirect_uris must contain at least one valid HTTPS redirect URI.', 400 );
		}

		$response_types = isset( $params['response_types'] ) && is_array( $params['response_types'] )
			? array_values( array_unique( array_map( 'sanitize_key', $params['response_types'] ) ) )
			: array( 'code' );
		if ( array_diff( $response_types, array( 'code' ) ) ) {
			return $this->oauth_error_response( 'invalid_client_metadata', 'Only response_type code is supported.', 400 );
		}

		$grant_types = isset( $params['grant_types'] ) && is_array( $params['grant_types'] )
			? array_values( array_unique( array_map( 'sanitize_key', $params['grant_types'] ) ) )
			: array( 'authorization_code', 'refresh_token' );
		$supported_grants = array( 'authorization_code', 'refresh_token' );
		if ( array_diff( $grant_types, $supported_grants ) ) {
			return $this->oauth_error_response( 'invalid_client_metadata', 'Only authorization_code and refresh_token grants are supported for dynamic clients.', 400 );
		}

		$token_auth_method = isset( $params['token_endpoint_auth_method'] )
			? sanitize_key( (string) $params['token_endpoint_auth_method'] )
			: 'none';
		if ( ! in_array( $token_auth_method, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
			return $this->oauth_error_response( 'invalid_client_metadata', 'Unsupported token_endpoint_auth_method.', 400 );
		}

		$client_id     = 'mcpwp_dcr_' . bin2hex( random_bytes( 16 ) );
		$client_secret = 'none' !== $token_auth_method ? 'mcpwp_cs_' . bin2hex( random_bytes( 24 ) ) : '';
		$now           = time();
		// Cap client_name so a single registration cannot bloat the option.
		$client_name   = substr( sanitize_text_field( (string) ( $params['client_name'] ?? 'OAuth Client' ) ), 0, self::DCR_CLIENT_NAME_MAX );

		// Bound the stored client set: prune expired entries, then evict the
		// oldest if still at capacity. mcpwp_oauth_clients is read on every
		// authorize/token/register call, so it must not grow without limit —
		// an unauthenticated attacker would otherwise DoS the option.
		$clients = $this->prune_dynamic_oauth_clients( $this->get_dynamic_oauth_clients(), $now );
		$clients[ $client_id ] = array(
			'client_id'                  => $client_id,
			'client_name'                => $client_name,
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => $grant_types,
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $token_auth_method,
			'client_secret_hash'         => '' !== $client_secret ? wp_hash_password( $client_secret ) : '',
			'created_at'                 => $now,
			'updated_at'                 => $now,
		);
		update_option( 'mcpwp_oauth_clients', $clients, false );

		$body = array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => $now,
			'client_name'                => $client_name,
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => $grant_types,
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $token_auth_method,
		);

		if ( ! empty( $params['scope'] ) ) {
			$body['scope'] = sanitize_text_field( (string) $params['scope'] );
		}

		if ( '' !== $client_secret ) {
			$body['client_secret']            = $client_secret;
			$body['client_secret_expires_at'] = 0;
		}

		$response = new WP_REST_Response( $body, 201 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	// -------------------------------------------------------------------------
	// GET /oauth/authorize — display consent screen (or redirect to WP login)
	// -------------------------------------------------------------------------

	/**
	 * Handle GET /oauth/authorize.
	 *
	 * Validates parameters, forces WP login if user is not authenticated, then
	 * renders a minimal consent screen.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|void May redirect (wp_redirect + exit).
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

		// Require logged-in WP user. Send them back to the FRONT-CHANNEL
		// authorize URL after login — the REST route would see them as
		// logged-out again (core strips cookie auth without an X-WP-Nonce).
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			$callback_url = add_query_arg( $params, Mcpwp_OAuth_Well_Known::get_front_channel_authorize_url() );
			$login_url    = wp_login_url( $callback_url );
			wp_redirect( $login_url );
			exit;
		}

		// Clamp scope to what the current user is actually capable of before
		// rendering the consent screen so users only see what they can get.
		$params['scope'] = $this->clamp_scope_to_user_capability( $params['scope'] );

		$this->render_consent_screen( $params );
		exit;
	}

	// -------------------------------------------------------------------------
	// POST /oauth/authorize — process consent form submission
	// -------------------------------------------------------------------------

	/**
	 * Handle POST /oauth/authorize (consent form submission).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|void May redirect (wp_redirect + exit).
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

		// Denial path — redirect with error.
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( 'deny' === $action ) {
			$redirect = add_query_arg(
				array(
					'error'             => 'access_denied',
					'error_description' => 'User denied access.',
					'state'             => $params['state'],
				),
				$params['redirect_uri']
			);
			wp_redirect( $redirect );
			exit;
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

		wp_redirect( $redirect );
		exit;
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
		$basic_auth    = $this->extract_basic_auth_client_credentials();
		if ( ! empty( $basic_auth['client_id'] ) || ! empty( $basic_auth['client_secret'] ) ) {
			$client_id     = sanitize_key( (string) $basic_auth['client_id'] );
			$client_secret = (string) $basic_auth['client_secret'];
		}

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
		if ( ! empty( $client_secret ) ) {
			// Confidential client path — secret must verify.
			if ( ! $this->verify_oauth_client_credentials( $client_id, $client_secret ) ) {
				return $this->oauth_error_response( 'invalid_client', 'Invalid client credentials.', 401 );
			}
		} elseif ( ! empty( $client_id ) ) {
			// Public client path — validate client_id only (no secret check).
			if ( ! $this->is_registered_oauth_client_id( $client_id ) ) {
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
		$oauth_settings = $this->get_oauth_settings();
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
		$basic_auth    = $this->extract_basic_auth_client_credentials();
		if ( ! empty( $basic_auth['client_id'] ) || ! empty( $basic_auth['client_secret'] ) ) {
			$client_id     = sanitize_key( (string) $basic_auth['client_id'] );
			$client_secret = (string) $basic_auth['client_secret'];
		}

		if ( empty( $client_id ) ) {
			return $this->oauth_error_response( 'invalid_request', 'client_id is required for client_credentials grant.', 400 );
		}

		if ( empty( $client_secret ) ) {
			return $this->oauth_error_response( 'invalid_request', 'client_secret is required for client_credentials grant.', 400 );
		}

		if ( ! $this->verify_oauth_client_credentials( $client_id, $client_secret ) ) {
			return $this->oauth_error_response( 'invalid_client', 'Invalid client credentials.', 401 );
		}

		if ( ! $this->oauth_client_allows_grant( $client_id, 'client_credentials' ) ) {
			return $this->oauth_error_response( 'unauthorized_client', 'This client is not allowed to use client_credentials.', 400 );
		}

		$scope_string   = (string) $request->get_param( 'scope' );
		$scopes         = $this->parse_oauth_scope_string( $scope_string );
		$oauth_settings = $this->get_oauth_settings();
		$token_data     = $this->issue_oauth_access_token( $scopes, $oauth_settings['oauth_token_ttl'] );

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

		// Validate client_id (configured static client or DCR-issued client).
		if ( empty( $params['client_id'] ) || ! $this->is_registered_oauth_client_id( $params['client_id'] ) ) {
			return new WP_Error( 'invalid_client', 'Unknown or missing client_id.', array( 'status' => 400 ) );
		}

		// Validate redirect_uri against allow-list.
		if ( empty( $params['redirect_uri'] ) ) {
			return new WP_Error( 'invalid_request', 'redirect_uri is required.', array( 'status' => 400 ) );
		}
		if ( ! $this->is_redirect_uri_allowed( $params['redirect_uri'], $params['client_id'] ) ) {
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
	 * Render the minimal HTML consent screen and exit.
	 *
	 * All OAuth parameters are embedded as hidden fields.
	 * CSRF nonce is generated fresh per render.
	 *
	 * @param array $params Validated authorize parameters (scope already clamped).
	 */
	private function render_consent_screen( $params ) {
		$nonce       = wp_create_nonce( 'mcpwp_oauth_consent' );
		$site_name   = function_exists( 'get_bloginfo' ) ? esc_html( get_bloginfo( 'name' ) ) : 'this site';
		$client_id   = esc_html( $params['client_id'] );
		$scope_label = esc_html( $params['scope'] ? $params['scope'] : 'read' );
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
				esc_attr( $value )
			);
		}

		// Security headers for the consent page. It carries a live per-user CSRF
		// nonce and grants an admin-scoped token on Approve, so it must never be
		// frameable (clickjacking → token theft) or cached (nonce/username leak).
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Cache-Control: no-store, private' );
		header( 'Pragma: no-cache' );
		header( 'Referrer-Policy: no-referrer' );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
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
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
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
	 * Extract OAuth client credentials from an HTTP Basic Authorization header.
	 *
	 * @return array{client_id:string,client_secret:string}
	 */
	private function extract_basic_auth_client_credentials() {
		$username = isset( $_SERVER['PHP_AUTH_USER'] ) ? (string) wp_unslash( $_SERVER['PHP_AUTH_USER'] ) : '';
		$password = isset( $_SERVER['PHP_AUTH_PW'] ) ? (string) wp_unslash( $_SERVER['PHP_AUTH_PW'] ) : '';
		if ( '' !== $username || '' !== $password ) {
			return array(
				'client_id'     => rawurldecode( $username ),
				'client_secret' => rawurldecode( $password ),
			);
		}

		$header = '';
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$header = (string) wp_unslash( $_SERVER[ $key ] );
				break;
			}
		}

		if ( ! preg_match( '/^Basic\\s+(.+)$/i', trim( $header ), $matches ) ) {
			return array( 'client_id' => '', 'client_secret' => '' );
		}

		$decoded = base64_decode( $matches[1], true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return array( 'client_id' => '', 'client_secret' => '' );
		}

		list( $client_id, $client_secret ) = explode( ':', $decoded, 2 );
		return array(
			'client_id'     => rawurldecode( $client_id ),
			'client_secret' => rawurldecode( $client_secret ),
		);
	}

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
	public function is_redirect_uri_allowed( $redirect_uri, $client_id = '' ) {
		$allowed = $this->get_allowed_redirect_uris( $client_id );
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
	public function get_allowed_redirect_uris( $client_id = '' ) {
		$client_id = sanitize_key( (string) $client_id );
		if ( '' !== $client_id ) {
			$client = $this->get_dynamic_oauth_client( $client_id );
			if ( $client && ! empty( $client['redirect_uris'] ) && is_array( $client['redirect_uris'] ) ) {
				return array_values( array_filter( array_map( 'esc_url_raw', array_map( 'trim', $client['redirect_uris'] ) ) ) );
			}
		}

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
	 * Validate and normalize redirect_uris from dynamic client registration.
	 *
	 * @param mixed $raw Raw redirect_uris value.
	 * @return string[]
	 */
	private function sanitize_registration_redirect_uris( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = array( $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$uris = array();
		foreach ( array_slice( $raw, 0, 10 ) as $uri ) {
			$uri = esc_url_raw( trim( (string) $uri ) );
			if ( '' === $uri ) {
				continue;
			}

			$parts = wp_parse_url( $uri );
			if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				continue;
			}

			$scheme = strtolower( (string) $parts['scheme'] );
			$host   = strtolower( (string) $parts['host'] );
			$is_loopback = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
			if ( 'https' !== $scheme && ! ( 'http' === $scheme && $is_loopback ) ) {
				continue;
			}

			if ( isset( $parts['fragment'] ) ) {
				continue;
			}

			$uris[] = $uri;
		}

		return array_values( array_unique( $uris ) );
	}

	/**
	 * Get all dynamically registered OAuth clients.
	 *
	 * @return array
	 */
	private function get_dynamic_oauth_clients() {
		$clients = get_option( 'mcpwp_oauth_clients', array() );
		return is_array( $clients ) ? $clients : array();
	}

	/**
	 * Prune the dynamic client set to keep the option bounded.
	 *
	 * Drops clients untouched for longer than DCR_CLIENT_TTL, then, if still at
	 * or above DCR_CLIENT_MAX, evicts the oldest by created_at until one free
	 * slot remains for the incoming registration.
	 *
	 * @param array $clients Existing clients keyed by client_id.
	 * @param int   $now     Current unix time.
	 * @return array Pruned clients.
	 */
	private function prune_dynamic_oauth_clients( $clients, $now ) {
		if ( empty( $clients ) ) {
			return $clients;
		}

		// TTL prune (a DCR client unused for the TTL window is abandoned).
		foreach ( $clients as $id => $client ) {
			$seen = isset( $client['updated_at'] ) ? (int) $client['updated_at'] : ( isset( $client['created_at'] ) ? (int) $client['created_at'] : 0 );
			if ( $seen > 0 && ( $now - $seen ) > self::DCR_CLIENT_TTL ) {
				unset( $clients[ $id ] );
			}
		}

		// Capacity prune — evict oldest first, leaving room for one new client.
		if ( count( $clients ) >= self::DCR_CLIENT_MAX ) {
			uasort(
				$clients,
				static function ( $a, $b ) {
					return ( isset( $a['created_at'] ) ? (int) $a['created_at'] : 0 ) <=> ( isset( $b['created_at'] ) ? (int) $b['created_at'] : 0 );
				}
			);
			while ( count( $clients ) >= self::DCR_CLIENT_MAX ) {
				array_shift( $clients );
			}
		}

		return $clients;
	}

	/**
	 * Client IP used to throttle unauthenticated DCR.
	 *
	 * Deliberately does NOT use get_client_ip(): that trusts CF/X-Forwarded-For
	 * headers, which are spoofable on an unauthenticated endpoint and would let
	 * an attacker rotate the header to mint unlimited throttle buckets. Forwarded
	 * headers are honored only behind the explicit MCPWP_TRUST_PROXY_HEADERS
	 * opt-in (same gate used by the OAuth issuer origin).
	 *
	 * @return string
	 */
	private function get_oauth_register_ip() {
		if ( defined( 'MCPWP_TRUST_PROXY_HEADERS' ) && MCPWP_TRUST_PROXY_HEADERS ) {
			return $this->get_client_ip();
		}
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : 'unknown';
	}

	/**
	 * Get one dynamically registered OAuth client.
	 *
	 * @param string $client_id Client ID.
	 * @return array|null
	 */
	private function get_dynamic_oauth_client( $client_id ) {
		$client_id = sanitize_key( (string) $client_id );
		$clients   = $this->get_dynamic_oauth_clients();
		return isset( $clients[ $client_id ] ) && is_array( $clients[ $client_id ] ) ? $clients[ $client_id ] : null;
	}

	/**
	 * Check whether a client_id is either the configured static client or a DCR client.
	 *
	 * @param string $client_id Client ID.
	 * @return bool
	 */
	private function is_registered_oauth_client_id( $client_id ) {
		$client_id      = sanitize_key( (string) $client_id );
		$oauth_settings = $this->get_oauth_settings();

		if ( '' !== $client_id && hash_equals( $oauth_settings['oauth_client_id'], $client_id ) ) {
			return true;
		}

		return null !== $this->get_dynamic_oauth_client( $client_id );
	}

	/**
	 * Check whether a client may use a grant type.
	 *
	 * Static clients keep existing behavior. Dynamic clients are limited to the
	 * grant_types accepted during registration.
	 *
	 * @param string $client_id  Client ID.
	 * @param string $grant_type Grant type.
	 * @return bool
	 */
	private function oauth_client_allows_grant( $client_id, $grant_type ) {
		$client_id      = sanitize_key( (string) $client_id );
		$oauth_settings = $this->get_oauth_settings();

		if ( '' !== $client_id && hash_equals( $oauth_settings['oauth_client_id'], $client_id ) ) {
			return true;
		}

		$client = $this->get_dynamic_oauth_client( $client_id );
		if ( ! $client ) {
			return false;
		}

		$grant_types = isset( $client['grant_types'] ) && is_array( $client['grant_types'] )
			? array_map( 'sanitize_key', $client['grant_types'] )
			: array();

		return in_array( sanitize_key( (string) $grant_type ), $grant_types, true );
	}

	/**
	 * Verify static or dynamically registered confidential client credentials.
	 *
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret.
	 * @return bool
	 */
	protected function verify_oauth_client_credentials( $client_id, $client_secret ) {
		$client_id      = sanitize_key( (string) $client_id );
		$oauth_settings = $this->get_oauth_settings();

		if (
			! empty( $oauth_settings['oauth_enabled'] )
			&& '' !== $client_id
			&& hash_equals( $oauth_settings['oauth_client_id'], $client_id )
			&& ! empty( $oauth_settings['oauth_client_secret_hash'] )
		) {
			return wp_check_password( (string) $client_secret, $oauth_settings['oauth_client_secret_hash'] );
		}

		$client = $this->get_dynamic_oauth_client( $client_id );
		if ( ! $client || empty( $client['client_secret_hash'] ) ) {
			return false;
		}

		return wp_check_password( (string) $client_secret, (string) $client['client_secret_hash'] );
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
		$token_response = $this->issue_oauth_access_token( $scopes, $ttl );

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

	// -------------------------------------------------------------------------
	// Front-channel authorize (served outside /wp-json)
	// -------------------------------------------------------------------------

	/**
	 * Serve GET/POST /mcpwp-oauth/authorize from the do_parse_request interceptor.
	 *
	 * Browsers cannot complete the consent flow on the REST route: WordPress
	 * core treats cookie-authenticated REST requests without an X-WP-Nonce as
	 * logged-out (rest_cookie_check_errors → wp_set_current_user(0)), which
	 * bounces the user to wp-login indefinitely. This wrapper reuses the exact
	 * same handlers on a front-end request where cookie auth works normally.
	 *
	 * Called by Mcpwp_OAuth_Well_Known::maybe_serve_well_known(); never returns.
	 */
	public static function serve_front_channel_authorize() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		$request = new WP_REST_Request( $method, '/mcpwp/v1/oauth/authorize' );
		// phpcs:disable WordPress.Security.NonceVerification -- OAuth front-channel entry
		// point; the consent POST carries its own CSRF nonce, verified in
		// handle_authorize_post(). Parameters are sanitized in extract_authorize_params().
		$request->set_query_params( wp_unslash( $_GET ) );
		if ( 'POST' === $method ) {
			$request->set_body_params( wp_unslash( $_POST ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification

		$controller = new self();
		$response   = 'POST' === $method
			? $controller->handle_authorize_post( $request )
			: $controller->handle_authorize_get( $request );

		// Success paths redirect + exit inside the handlers; a returned value is
		// always an OAuth error object — emit it as JSON.
		if ( $response instanceof WP_REST_Response ) {
			status_header( $response->get_status() );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( $response->get_data() );
		}
		exit;
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
}
