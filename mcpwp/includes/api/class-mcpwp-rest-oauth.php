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
 *   POST /wp-json/mcpwp/v1/oauth/register        (RFC 7591 Dynamic Client Registration — WP REST)
 *   POST /wp-json/mcpwp/v1/oauth/revoke          (RFC 7009 token revocation — WP REST, #1101)
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
 *  - client_id resolves to a DCR-registered client first (Mcpwp_OAuth_Clients),
 *    then to the single legacy settings client (PR-2 D6).
 *  - redirect_uri must exact-match the client's registered list (loopback:
 *    port-agnostic, RFC 8252 §7.3) or, for the legacy client, the
 *    oauth_redirect_uris allow-list.  A mismatch is a JSON error, never a
 *    redirect.
 *  - `resource` (RFC 8707), when sent, must name this MCP endpoint or the
 *    pre-PR-2 origin identifier, else invalid_target; every token carries
 *    aud = the MCP endpoint URL (D1/D2).
 *  - OAuth never grants admin: authorization_code and refresh grants are
 *    capped at write (D8).  No scope requested means read+write, still
 *    clamped to the user's capability (D9).  The consent screen lets the
 *    user untick write; the POST is HMAC-bound to what was shown, so a
 *    tampered form can only narrow the grant.
 *  - Authorization responses carry `iss` (RFC 9207, D13).
 *  - Auth codes are single-use (transient deleted on first successful use) with 60s TTL.
 *  - Code bound to client_id + redirect_uri + code_challenge + user_id + CLAMPED scope.
 *  - Scope clamped to user capability at authorize time and re-validated at token time.
 *  - client_secret OPTIONAL — public clients (PKCE only) work without a secret.
 *  - Refresh token rotation: presented token deleted, new one issued, old can't be reused;
 *    presenting a rotated-away token again kills its whole family (#1101).
 *  - Refresh token bound to user_id AND client_id (#1101); re-issued token retains both.
 *  - client_id required and verified against stored code at token exchange (P1-E, #1101).
 *  - A code or refresh token is claimed only after every check passed (#1101).
 *  - Revocation by epoch per client, user and refresh family (Mcpwp_OAuth_Revocation, #1101).
 *  - Consent POST protected by a wp_nonce.
 *  - constant-time comparison (hash_equals) everywhere.
 *  - No token/secret material written to any log or query-string persistence.
 *  - All endpoints gated on oauth_enabled setting (absent = on, D10).
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
	 * Transient prefix of the "this code was redeemed" markers (key: SHA-256
	 * of the code; value: the family and client it produced).
	 */
	const REDEEMED_CODE_PREFIX = 'mcpwp_oauth_codeused_';

	/**
	 * Seconds after a redemption during which a second presentation of the
	 * same code (a client double-submitting its callback) is refused without
	 * revoking the winner's tokens.
	 */
	const REPLAY_GRACE_SECONDS = 10;

	/** How long a redeemed-code marker is kept (1 day; the code itself lives 60 s). */
	const REDEEMED_CODE_TTL = 86400;

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

		// RFC 7591 Dynamic Client Registration (PR-2 D3).  Unauthenticated by
		// design — ChatGPT and Claude register before any user is involved —
		// and therefore bounded in Mcpwp_OAuth_Clients (rate limit, cap,
		// purge, strict redirect validation).
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

		// RFC 7009 token revocation (#1101).  Unauthenticated like /token:
		// the client identifies itself with client_id (public) or
		// client_id + client_secret (the legacy confidential client), and
		// only a token issued to that client is revoked.
		register_rest_route(
			$this->namespace,
			'/oauth/revoke',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_revoke' ),
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
	// POST /oauth/register — RFC 7591 Dynamic Client Registration
	// -------------------------------------------------------------------------

	/**
	 * Register a public OAuth client (RFC 7591 §3).
	 *
	 * Success is 201 with the client information response; failure is an
	 * RFC 7591 §3.2.2 error body (invalid_redirect_uri /
	 * invalid_client_metadata), or 429 temporarily_unavailable at the rate
	 * limit or the client cap.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_register( $request ) {
		if ( ! $this->is_oauth_enabled() ) {
			return $this->oauth_error_response( 'server_error', 'OAuth is not enabled on this site.', 501 );
		}

		$metadata = $request->get_json_params();
		if ( ! is_array( $metadata ) ) {
			// Some clients omit or mislabel Content-Type; the body is still
			// required to be a JSON object (RFC 7591 §3.1).
			$decoded  = json_decode( (string) $request->get_body(), true );
			$metadata = is_array( $decoded ) ? $decoded : null;
		}

		// The limits key on the plugin's trusted address resolution
		// (get_client_ip(): REMOTE_ADDR unless MCPWP_TRUST_PROXY_HEADERS).  An
		// authenticated administrator (cookie + nonce, or an application
		// password) is exempt from them: an anonymous flood must not stop
		// the site's own admin from connecting an app.
		$is_admin = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
		$result   = Mcpwp_OAuth_Clients::register( $metadata, $this->get_client_ip(), 0, $is_admin );

		if ( is_wp_error( $result ) ) {
			$response = $this->wpe_to_response( $result );
			$data     = $result->get_error_data();
			if ( is_array( $data ) && ! empty( $data['retry_after'] ) ) {
				$response->header( 'Retry-After', (string) (int) $data['retry_after'] );
			}
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}

		$response = new WP_REST_Response( $result, 201 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
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
			// Percent-encode first: add_query_arg() does not, and redirect_uri
			// and resource are themselves URLs (a plain-permalink resource
			// carries its own ?rest_route=…), which would otherwise bleed into
			// the callback's query string.
			$encoded = array();
			foreach ( array_filter( $params, 'strlen' ) as $key => $value ) {
				$encoded[ $key ] = rawurlencode( (string) $value );
			}
			$callback_url = add_query_arg( $encoded, Mcpwp_OAuth_Well_Known::get_front_channel_authorize_url() );
			return $this->oauth_redirect_response( wp_login_url( $callback_url ) );
		}

		// P0b: below the site's minimum role, there is no consent screen at
		// all — a plain page, never a redirect carrying a code.
		if ( ! Mcpwp_OAuth_Well_Known::user_can_connect( get_current_user_id() ) ) {
			return $this->min_role_refusal_response();
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

		// P0b: enforced on the POST too — a forged or replayed form from a
		// user below the minimum role mints nothing.
		if ( ! Mcpwp_OAuth_Well_Known::user_can_connect( get_current_user_id() ) ) {
			return $this->min_role_refusal_response();
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

		// The consent form is bound to exactly what was shown: client,
		// redirect, PKCE challenge, resource, state and the (clamped) scope.
		// Any edited hidden field fails here, before anything is minted — so a
		// tampered form cannot ask for more than the screen offered.  The
		// nonce proves the POST came from this user's session; this proves the
		// session saw these parameters.
		$binding = $this->stringify_param_value( $request->get_param( '_mcpwp_oauth_binding' ) );
		if ( '' === $binding || ! hash_equals( $this->consent_binding( $params ), $binding ) ) {
			return $this->oauth_error_response( 'invalid_request', 'The consent form was altered or has expired. Start the connection again.', 400 );
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
			$this->log_oauth_event( 'oauth_consent_denied', array( 'client_id' => $params['client_id'] ) );
			$redirect = add_query_arg(
				array(
					'error'             => 'access_denied',
					'error_description' => rawurlencode( 'User denied access.' ),
					'state'             => '' !== $params['state'] ? rawurlencode( $params['state'] ) : false,
					// D13: RFC 9207 — error responses carry iss too.
					'iss'               => rawurlencode( Mcpwp_OAuth_Well_Known::get_issuer() ),
				),
				$params['redirect_uri']
			);
			return $this->oauth_redirect_response( $redirect );
		}

		// P0-A: Clamp the requested scope to the logged-in user's capability
		// (capped at write, D8).  Store only the clamped scope in the auth
		// code — never the raw requested scope.
		$clamped_scope = $this->clamp_scope_to_user_capability( $params['scope'] );

		// The user may untick write on the consent screen.  An unticked box is
		// absent from the POST, so absence means read-only: the only way to
		// keep write is an explicit tick, and the tick can never ADD write —
		// it only keeps what the clamped request already contained.
		$keep_write = '1' === $this->stringify_param_value( $request->get_param( 'scope_write' ) );
		if ( ! $keep_write ) {
			$clamped_scope = array( 'read' );
		}

		// A registered client is marked authorized BEFORE its code exists, so
		// LRU eviction (which only takes never-authorized clients) can never
		// remove a client that holds a live code.  If the store is busy the
		// approval is not lost silently: the client is told to retry, at its
		// already-validated redirect_uri (RFC 6749 §4.1.2.1).
		if ( Mcpwp_OAuth_Clients::looks_like_client_id( $params['client_id'] ) && ! Mcpwp_OAuth_Clients::mark_authorized( $params['client_id'] ) ) {
			return $this->oauth_redirect_response(
				add_query_arg(
					array(
						'error'             => 'temporarily_unavailable',
						'error_description' => rawurlencode( 'The site was busy. Try connecting again.' ),
						'state'             => '' !== $params['state'] ? rawurlencode( $params['state'] ) : false,
						'iss'               => rawurlencode( Mcpwp_OAuth_Well_Known::get_issuer() ),
					),
					$params['redirect_uri']
				)
			);
		}

		// Remember the resource this site was authorized under, so a later
		// permalink or home-URL change (which changes every token's audience)
		// can be shown to the administrator.  First authorization only; the
		// Setup notice's dismiss moves it on.
		if ( class_exists( 'Mcpwp_OAuth_Local_Admin' ) ) {
			Mcpwp_OAuth_Local_Admin::remember_resource_if_unset();
		}

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
				// D1: the audience the token will carry.  Whatever accepted
				// `resource` form was sent, it is the canonical MCP URL.
				'aud'            => Mcpwp_OAuth_Well_Known::get_canonical_resource(),
				// #1101: compared against revocation epochs at redemption.
				'issued_at'      => Mcpwp_OAuth_Revocation::now(),
				'expires'        => time() + 60,
			),
			60
		);

		$this->log_oauth_event(
			'oauth_consent_approved',
			array(
				'client_id' => $params['client_id'],
				'scope'     => implode( ' ', $clamped_scope ),
			)
		);

		$redirect = add_query_arg(
			array(
				'code'  => $code,
				'state' => '' !== $params['state'] ? rawurlencode( $params['state'] ) : false,
				// D13: RFC 9207 issuer identification.
				'iss'   => rawurlencode( Mcpwp_OAuth_Well_Known::get_issuer() ),
			),
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
		// RFC 6749 §5.1 / §5.2: every answer of the token endpoint — a token
		// or an error, whichever grant produced it — is uncacheable.  Applied
		// here, once, rather than in each grant handler.
		return $this->no_store( $this->dispatch_token_grant( $request ) );
	}

	/**
	 * Route a token request to its grant handler.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	private function dispatch_token_grant( $request ) {
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
		// RFC 7636 §4.1 shape, checked before anything is read or consumed.
		if ( ! self::is_valid_pkce_string( $code_verifier ) ) {
			return $this->oauth_error_response( 'invalid_grant', 'code_verifier must be 43 to 128 characters of A-Z a-z 0-9 - . _ ~.', 400 );
		}
		// #1101: RFC 6749 §4.1.3 — a public client sends its client_id.  The
		// client check is never skipped: without an id there is nothing to
		// check the code's binding against.
		if ( '' === $client_id ) {
			return $this->oauth_error_response( 'invalid_request', 'client_id is required.', 400 );
		}

		// RFC 8707: a `resource` on the token request must name this MCP
		// endpoint (D1).  Checked before the code is consumed so a client
		// that only got the resource wrong can retry with the same code.
		$resource_err = $this->check_token_request_resource( $request );
		if ( null !== $resource_err ) {
			return $resource_err;
		}

		// #1101 (P2 from the #1099 gate): every check runs against the READ
		// record first, and the code is claimed only once they all pass —
		// so a request carrying a stolen code with a wrong verifier, client
		// or redirect_uri changes nothing, and the real client can still
		// redeem it.  The claim itself stays atomic (kasra-review P2-1): two
		// concurrent redemptions can both READ the code; only the one whose
		// DELETE actually removed it proceeds.  delete_transient() reports
		// that on both backends: on the options table it is delete_option(),
		// whose DELETE affects one row for exactly one caller (the other
		// gets 0 and false); on an object cache it is wp_cache_delete(),
		// which Redis (DEL's count) and Memcached (NOT_FOUND) answer per key
		// atomically.
		$transient_key = 'mcpwp_oauth_code_' . $code;
		$stored        = get_transient( $transient_key );
		// #1101 round 2: the tokens minted below carry THIS moment as
		// issued_at — taken before any revocation check — so a revocation
		// that lands between the checks and the mint still covers them.
		$checked_at = Mcpwp_OAuth_Revocation::now();

		if ( ! is_array( $stored ) || empty( $stored['code_challenge'] ) ) {
			// RFC 6749 §4.1.2: a code that was already redeemed and is
			// presented again means a copy exists.  Revoke what the first
			// redemption issued (the whole refresh family), then refuse like
			// any other dead code.
			$this->revoke_tokens_of_redeemed_code( $code, $client_id, $redirect_uri, $client_secret );
			return $this->oauth_error_response( 'invalid_grant', 'Authorization code is invalid or has expired.', 400 );
		}

		// Expiry double-check (transient TTL should cover this, but be explicit).
		if ( isset( $stored['expires'] ) && time() > (int) $stored['expires'] ) {
			return $this->oauth_error_response( 'invalid_grant', 'Authorization code has expired.', 400 );
		}

		// redirect_uri must match stored value exactly (constant-time).
		if ( ! hash_equals( (string) $stored['redirect_uri'], $redirect_uri ) ) {
			return $this->oauth_error_response( 'invalid_grant', 'redirect_uri mismatch.', 400 );
		}

		// P1-E: client_id must match the client_id that was stored in the
		// code.  A code with no stored client_id is refused (fail closed).
		if ( empty( $stored['client_id'] ) || ! hash_equals( (string) $stored['client_id'], $client_id ) ) {
			return $this->oauth_error_response( 'invalid_grant', 'client_id mismatch.', 400 );
		}

		// PKCE: base64url(SHA256(code_verifier)) must match stored code_challenge.
		$computed_challenge = $this->compute_pkce_challenge( $code_verifier );
		if ( ! hash_equals( (string) $stored['code_challenge'], $computed_challenge ) ) {
			return $this->oauth_error_response( 'invalid_grant', 'PKCE verification failed.', 400 );
		}

		// P0-C: client authentication.
		// - client_secret provided: verify against the configured secret (confidential client).
		// - otherwise: the client_id must exist — a client registered by
		//   DCR (D3), or the legacy settings client (D6).
		$oauth_settings = $this->get_oauth_settings();
		if ( ! empty( $client_secret ) ) {
			if ( ! $this->verify_oauth_client_credentials( $client_id, $client_secret ) ) {
				return $this->oauth_error_response( 'invalid_client', 'Invalid client credentials.', 401 );
			}
		} elseif ( null === $this->resolve_client( $client_id ) ) {
			return $this->oauth_error_response( 'invalid_client', 'Invalid client_id.', 401 );
		}

		// P0-A defence-in-depth: re-clamp at token issuance; the user stored
		// in the code is the one who consented.
		$stored_user_id = isset( $stored['user_id'] ) ? (int) $stored['user_id'] : 0;

		// P0b: the approving user must still meet the minimum role when the
		// code is redeemed (a demotion inside the code's 60 s counts).
		if ( ! Mcpwp_OAuth_Well_Known::user_can_connect( $stored_user_id ) ) {
			return $this->oauth_error_response( 'invalid_grant', 'The approving user can no longer connect AI apps on this site.', 400 );
		}

		// #1101: a code issued before its client or user was revoked is dead
		// too.  A code from before this release carries no issued_at; its
		// 60-second life puts it at expires - 60.
		$code_record = $stored;
		if ( ! isset( $code_record['issued_at'] ) && isset( $stored['expires'] ) ) {
			$code_record['issued_at'] = ( (int) $stored['expires'] - 60 ) * 1000000;
		}
		if ( Mcpwp_OAuth_Revocation::is_revoked( $code_record ) ) {
			delete_transient( $transient_key );
			return $this->oauth_error_response( 'invalid_grant', 'Authorization code has been revoked.', 400 );
		}

		// Parse scopes from stored value.
		// P0-B: Never fall back to get_default_api_key_scopes() here — that would
		// grant read+write+admin when scope is absent. Use stored (already-clamped) scope.
		$scope_string = isset( $stored['scope'] ) ? (string) $stored['scope'] : '';
		$scopes       = $this->parse_oauth_scope_string( $scope_string );
		$scopes       = $this->clamp_scope_array_to_user_id( $scopes, $stored_user_id );

		// Mint first, claim second (#1101 PR-3b): the tokens are written to
		// the table before the code is consumed, so a store that cannot
		// write burns nothing (503, the code still redeemable), and a lost
		// claim race revokes what this request minted.
		$ttl            = (int) $oauth_settings['oauth_token_ttl'];
		$family_id      = Mcpwp_OAuth_Revocation::new_family_id();
		$token_response = $this->issue_oauth_access_token_with_user( $scopes, $ttl, $stored_user_id, $client_id, $family_id, $checked_at );
		if ( is_wp_error( $token_response ) ) {
			return $this->no_store( $this->wpe_to_response( $token_response ) );
		}

		// Every check passed and the tokens exist: claim the code.  Only the
		// request whose delete removed it keeps what it minted.
		if ( ! delete_transient( $transient_key ) ) {
			$this->discard_minted( $token_response );
			return $this->oauth_error_response( 'invalid_grant', 'Authorization code is invalid or has expired.', 400 );
		}

		// Remember which family this code produced, so a replay of the code
		// can revoke it (revoke_tokens_of_redeemed_code()).  Keyed by the
		// code's hash; holds ids only.
		set_transient(
			self::REDEEMED_CODE_PREFIX . hash( 'sha256', $code ),
			array(
				'family_id'    => $family_id,
				'client_id'    => $client_id,
				'redirect_uri' => $redirect_uri,
				'redeemed_at'  => time(),
			),
			self::REDEEMED_CODE_TTL
		);

		Mcpwp_OAuth_Clients::touch( $client_id );

		$this->log_oauth_event(
			'oauth_token_issued',
			array(
				'grant_type' => 'authorization_code',
				'client_id'  => $client_id,
				'user_id'    => $stored_user_id,
				'scope'      => isset( $token_response['scope'] ) ? (string) $token_response['scope'] : '',
			)
		);

		return $this->no_store( new WP_REST_Response( $token_response, 200 ) );
	}

	/**
	 * A redeemed authorization code was presented again: revoke the token
	 * family its first redemption created (RFC 6749 §4.1.2).
	 *
	 * Nothing is revoked unless the presenter authenticates as the client the
	 * code was redeemed for: the exact client id and redirect URI, and — when
	 * a client secret is sent — a correct secret.  Someone who merely saw a
	 * used code (a log, a Referer) and knows the public client id therefore
	 * cannot disconnect the app with a wrong secret or redirect URI.  And
	 * nothing is revoked within REPLAY_GRACE_SECONDS of the redemption: a
	 * client that submits its callback twice in parallel gets invalid_grant
	 * for the loser without losing the winner's tokens.  The answer is
	 * invalid_grant either way.
	 *
	 * @param string $code          The presented code.
	 * @param string $client_id     The presenting client.
	 * @param string $redirect_uri  The presented redirect URI.
	 * @param string $client_secret The presented client secret ('' for a public client).
	 */
	private function revoke_tokens_of_redeemed_code( $code, $client_id, $redirect_uri, $client_secret ) {
		$marker = get_transient( self::REDEEMED_CODE_PREFIX . hash( 'sha256', (string) $code ) );
		if ( ! is_array( $marker ) || empty( $marker['family_id'] ) || empty( $marker['client_id'] ) ) {
			return;
		}
		if ( ! hash_equals( (string) $marker['client_id'], (string) $client_id ) ) {
			return;
		}
		if ( ! isset( $marker['redirect_uri'] ) || ! hash_equals( (string) $marker['redirect_uri'], (string) $redirect_uri ) ) {
			return;
		}
		$settings     = $this->get_oauth_settings();
		$confidential = '' !== $settings['oauth_client_id'] && hash_equals( $settings['oauth_client_id'], (string) $client_id ) && ! empty( $settings['oauth_client_secret_hash'] );
		if ( '' !== (string) $client_secret ) {
			if ( ! $this->verify_oauth_client_credentials( $client_id, (string) $client_secret ) ) {
				return;
			}
		} elseif ( $confidential || null === $this->resolve_client( $client_id ) ) {
			// A confidential client must prove itself; a public one must at
			// least be a client that exists.
			return;
		}
		if ( time() - (int) ( $marker['redeemed_at'] ?? 0 ) < self::REPLAY_GRACE_SECONDS ) {
			return;
		}
		Mcpwp_OAuth_Revocation::revoke_family( (string) $marker['family_id'] );
		$this->log_oauth_event(
			'oauth_code_reuse_detected',
			array(
				'client_id' => (string) $marker['client_id'],
			)
		);
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
	 * Scope handling matches handle_token_authorization_code() exactly:
	 * - The stored scope list is resolved with parse_oauth_scope_string(), which
	 *   falls back to array( 'read' ) on an empty or unrecognised set.
	 * - The result is re-clamped to the stored user's CURRENT capabilities on
	 *   every refresh, unconditionally.
	 * sanitize_scopes() itself now also fails closed to array( 'read' ), so using
	 * parse_oauth_scope_string() here is defense-in-depth, not a workaround for a
	 * fail-open helper.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	private function handle_token_refresh( $request ) {
		$refresh_token = sanitize_text_field( (string) $request->get_param( 'refresh_token' ) );
		$client_id     = sanitize_key( (string) $request->get_param( 'client_id' ) );

		if ( empty( $refresh_token ) ) {
			return $this->oauth_error_response( 'invalid_request', 'refresh_token is required.', 400 );
		}

		if ( ! $this->looks_like_oauth_refresh_token( $refresh_token ) ) {
			return $this->oauth_error_response( 'invalid_grant', 'Invalid refresh token format.', 400 );
		}

		// #1101: the client presents its id (RFC 6749 §6 / §3.2.1 for a
		// public client); the refresh token is bound to it.
		if ( '' === $client_id ) {
			return $this->oauth_error_response( 'invalid_request', 'client_id is required.', 400 );
		}

		$resource_err = $this->check_token_request_resource( $request );
		if ( null !== $resource_err ) {
			return $resource_err;
		}

		// #1101 PR-3b: refresh tokens live in the hashed token table.
		$row        = Mcpwp_OAuth_Token_Store::find( $refresh_token );
		$checked_at = Mcpwp_OAuth_Revocation::now(); // issued_at of what this mints (#1101 round 2).
		$record     = null;

		if ( Mcpwp_OAuth_Token_Store::is_active( $row, Mcpwp_OAuth_Token_Store::TYPE_REFRESH ) ) {
			$record = Mcpwp_OAuth_Token_Store::to_record( $row );
		} elseif ( is_array( $row ) && Mcpwp_OAuth_Token_Store::TYPE_TOMBSTONE === $row['type'] && '' !== (string) $row['family_id'] ) {
			// #1101 reuse detection: a token that was already rotated away is
			// a replay — someone else holds a copy.  Kill its whole family
			// (the legitimate holder reconnects; the copy is useless).  A
			// failed persist is reported inside revoke_family(); the
			// family's transient marker is written regardless.
			Mcpwp_OAuth_Revocation::revoke_family( (string) $row['family_id'] );
			$this->log_oauth_event(
				'oauth_refresh_reuse_detected',
				array(
					'client_id' => (string) $row['client_id'],
					'user_id'   => (int) $row['user_id'],
				)
			);
		} elseif ( null === $row ) {
			// Grace window: a refresh token issued before the table existed
			// is moved into it on this use (refused if that write fails).
			$record = Mcpwp_OAuth_Token_Store::migrate_from_transient(
				$refresh_token,
				Mcpwp_OAuth_Token_Store::TYPE_REFRESH,
				$this->get_oauth_refresh_token_transient_key( $refresh_token ),
				self::refresh_token_ttl()
			);
		}

		if ( ! is_array( $record ) || empty( $record['scopes'] ) ) {
			return $this->oauth_error_response( 'invalid_grant', 'Refresh token is invalid or has expired.', 400 );
		}

		// Every check below runs on the READ record, before the token is
		// claimed: a request with the wrong client changes nothing, and the
		// real client can still refresh (#1101 item 4).

		// #1101 client binding.  A record from before this release carries
		// no client_id: it is honoured ONCE, for a client that exists, and the
		// rotated token is bound to that client — every chain alive after
		// this release ends up bound.  (The alternative, refusing every
		// unbound record, would disconnect every connected app on upgrade;
		// honouring it unbound forever would keep the hole open.)
		$bound_client = isset( $record['client_id'] ) && is_string( $record['client_id'] ) ? $record['client_id'] : '';
		if ( '' !== $bound_client ) {
			if ( ! hash_equals( $bound_client, $client_id ) ) {
				return $this->oauth_error_response( 'invalid_grant', 'Refresh token was issued to a different client.', 400 );
			}
		} elseif ( null === $this->resolve_client( $client_id ) ) {
			return $this->oauth_error_response( 'invalid_grant', 'Refresh token was issued to a different client.', 400 );
		}

		// D2 parity: a refresh token bound to another resource (the site's
		// URL changed, or the stored record was altered) cannot mint a new
		// access token here.  Records from before PR-2 carry no `aud` and are
		// re-bound to the canonical audience by the new tokens.
		if ( array_key_exists( 'aud', $record ) && ! Mcpwp_OAuth_Well_Known::audience_matches( $record['aud'] ) ) {
			return $this->oauth_error_response( 'invalid_grant', 'Refresh token was issued for a different resource.', 400 );
		}

		$user_id = isset( $record['user_id'] ) ? (int) $record['user_id'] : 0;

		// P0b / P1-A: refresh carries its OWN live-user check — a user deleted,
		// stripped of every role, or demoted since consent cannot renew.  The
		// token is removed: nothing a caller sends can cause this refusal.
		if ( ! Mcpwp_OAuth_Well_Known::user_can_connect( $user_id ) ) {
			Mcpwp_OAuth_Token_Store::revoke( $refresh_token );
			return $this->oauth_error_response( 'invalid_grant', 'The approving user can no longer connect AI apps on this site.', 400 );
		}

		// #1101: revoked by epoch (client, user, or family).  A revoked token
		// is dead either way, so its row is marked revoked.  #1101 PR-3b
		// round 2: an unbound (pre-#1101) record is attributed, for this
		// check, to the client presenting it — the client it is about to be
		// bound to — so that client's epoch and its per-user epoch apply.
		$check = $record;
		if ( '' === $bound_client ) {
			$check['client_id'] = $client_id;
		}
		if ( Mcpwp_OAuth_Revocation::is_revoked( $check ) || ( '' === $bound_client && Mcpwp_OAuth_Revocation::is_revoked( $record ) ) ) {
			Mcpwp_OAuth_Token_Store::revoke( $refresh_token );
			return $this->oauth_error_response( 'invalid_grant', 'Refresh token has been revoked.', 400 );
		}

		// #1101: a family is one grant's chain of refresh tokens.  A record
		// without one (#1115) takes the family derived from the token itself,
		// never a random one: concurrent refreshes of the same token must
		// agree on it, so the claim's tombstone names the chain that really
		// received tokens.
		$family_id = '' !== (string) ( $record['family_id'] ?? '' ) ? (string) $record['family_id'] : Mcpwp_OAuth_Token_Store::legacy_family_id( $refresh_token );

		// P0-B parity with the authorization_code path: resolve the stored scope
		// list with parse_oauth_scope_string(), which falls back to array( 'read' )
		// on an empty or unrecognised set. A refresh record holding an unrecognised
		// scope list (corrupted option/transient, hand-seeded value, or a value
		// written by future code) therefore degrades to the least privilege rather
		// than the most.
		$stored_scopes = isset( $record['scopes'] ) ? (array) $record['scopes'] : array();
		$stored_scopes = array_filter( $stored_scopes, 'is_string' );
		$scopes        = $this->parse_oauth_scope_string( implode( ' ', $stored_scopes ) );

		// P0-A parity: re-clamp to the stored user's CURRENT capabilities on every
		// refresh, unconditionally (no user_id === 0 branch: such a record can
		// only already hold array( 'read' ), which is what the clamp returns).
		// Because the new refresh token is minted from $scopes as well, the clamp
		// ratchets: a downgrade cannot be undone by refreshing again.
		$scopes = $this->clamp_scope_array_to_user_id( $scopes, $user_id );

		$oauth_settings = $this->get_oauth_settings();
		$ttl            = (int) $oauth_settings['oauth_token_ttl'];

		// Mint first, claim second (#1101 PR-3b).  The new access + refresh
		// tokens (same client, same family) are written before the old one
		// is consumed, so a store that cannot write burns nothing.  Then the
		// claim: ONE conditional UPDATE turns the old row into its own reuse
		// tombstone, and only the request it affected (=== 1 row) keeps what
		// it minted; a concurrent refresh with the same token gets 0 and its
		// new tokens are revoked.  The tombstone IS the claimed row, so a
		// revocation racing this refresh always finds the token's family.
		$token_response = $this->issue_oauth_access_token_with_user( $scopes, $ttl, $user_id, $client_id, $family_id, $checked_at );
		if ( is_wp_error( $token_response ) ) {
			return $this->no_store( $this->wpe_to_response( $token_response ) );
		}
		if ( ! Mcpwp_OAuth_Token_Store::claim_refresh( $refresh_token, $family_id, $client_id ) ) {
			$this->discard_minted( $token_response );
			return $this->oauth_error_response( 'invalid_grant', 'Refresh token is invalid or has expired.', 400 );
		}

		$this->log_oauth_event(
			'oauth_token_issued',
			array(
				'grant_type' => 'refresh_token',
				'client_id'  => $client_id,
				'user_id'    => $user_id,
				'scope'      => isset( $token_response['scope'] ) ? (string) $token_response['scope'] : '',
			)
		);

		return $this->no_store( new WP_REST_Response( $token_response, 200 ) );
	}

	// -------------------------------------------------------------------------
	// POST /oauth/revoke — RFC 7009
	// -------------------------------------------------------------------------

	/**
	 * Revoke a token (RFC 7009 §2).
	 *
	 * The answer is 200 for every token that is unknown, expired, malformed
	 * or not this client's (§2.2): the endpoint never tells a caller whether
	 * a token exists.  Only a token issued to the presenting client is
	 * revoked.  A refresh token takes its whole family with it (every
	 * rotation of the grant and every access token issued alongside) — a
	 * refresh token already rotated away reaches its family through its
	 * reuse tombstone; an access token is deleted.  A token from before this
	 * release carries no client_id, so it belongs to no client and is left
	 * alone.  A family revocation that could not be made durable answers
	 * 503 temporarily_unavailable (RFC 7009 §2.2.1), never a false 200.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_revoke( $request ) {
		if ( ! $this->is_oauth_enabled() ) {
			return $this->oauth_error_response( 'server_error', 'OAuth is not enabled on this site.', 501 );
		}

		// Same limiter and bucket shape as the token endpoint's own limited
		// grant (client_credentials): per caller address.
		$rate_limit_check = $this->check_rate_limit( 'oauth-revoke:' . $this->get_client_ip() );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $this->no_store( $this->wpe_to_response( $rate_limit_check ) );
		}

		$token         = $request->get_param( 'token' );
		$token         = is_string( $token ) ? trim( $token ) : '';
		$hint          = sanitize_key( (string) $this->scalar_param( $request, 'token_type_hint' ) );
		$client_id     = sanitize_key( (string) $this->scalar_param( $request, 'client_id' ) );
		$client_secret = (string) $this->scalar_param( $request, 'client_secret' );

		if ( '' === $token ) {
			return $this->no_store( $this->oauth_error_response( 'invalid_request', 'token is required.', 400 ) );
		}
		if ( '' === $client_id ) {
			return $this->no_store( $this->oauth_error_response( 'invalid_request', 'client_id is required.', 400 ) );
		}
		if ( '' !== $client_secret ) {
			if ( ! $this->verify_oauth_client_credentials( $client_id, $client_secret ) ) {
				return $this->no_store( $this->oauth_error_response( 'invalid_client', 'Invalid client credentials.', 401 ) );
			}
		} elseif ( null === $this->resolve_client( $client_id ) ) {
			return $this->no_store( $this->oauth_error_response( 'invalid_client', 'Invalid client_id.', 401 ) );
		}

		// #1101 PR-3b: one table row, whatever the hint (§2.1 lets the
		// server ignore it).  A refresh token — live, or already rotated away
		// (its row is the reuse tombstone) — takes its family with it; an
		// access token is marked revoked.  A token from before the table
		// carries no client_id, so it belongs to no client and is left alone
		// (a pre-#1101 refresh token, once used, is bound: its tombstone names
		// the client and family it was rotated into).
		$persisted = true;
		$row       = Mcpwp_OAuth_Token_Store::find( $token );
		if ( is_array( $row ) && $this->record_belongs_to_client( $row, $client_id ) ) {
			$kind = (string) $row['type'];
			if ( in_array( $kind, array( Mcpwp_OAuth_Token_Store::TYPE_REFRESH, Mcpwp_OAuth_Token_Store::TYPE_TOMBSTONE ), true ) && '' !== (string) $row['family_id'] ) {
				$persisted = Mcpwp_OAuth_Revocation::revoke_family( (string) $row['family_id'] );
			}
			// #1101 PR-3b round 2: the row write is checked too.  If it failed,
			// the row is unchanged and a retry finds it again (and the
			// family's marker, for a refresh token, is already written).
			if ( ! Mcpwp_OAuth_Token_Store::revoke( $token ) ) {
				$persisted = false;
			}
			$this->log_oauth_event( 'oauth_token_revoked', array( 'client_id' => $client_id, 'user_id' => (int) $row['user_id'], 'grant_type' => Mcpwp_OAuth_Token_Store::TYPE_ACCESS === $kind ? 'access_token' : 'refresh_token' ) );
		}
		unset( $hint );

		// The family's transient marker is already written, so the tokens
		// stop working now; but a revocation that could not be made durable
		// (an object cache may evict the marker) is not reported as done.
		// RFC 7009 §2.2.1: 503 temporarily_unavailable, retry.
		if ( ! $persisted ) {
			$busy = $this->oauth_error_response( 'temporarily_unavailable', 'The revocation could not be saved; retry.', 503 );
			$busy->header( 'Retry-After', '1' );
			return $this->no_store( $busy );
		}

		return $this->no_store( new WP_REST_Response( null, 200 ) );
	}

	/**
	 * Whether a stored token record was issued to this client.
	 *
	 * @param array  $record    Token or refresh record.
	 * @param string $client_id Presenting client.
	 * @return bool
	 */
	private function record_belongs_to_client( array $record, $client_id ) {
		return isset( $record['client_id'] ) && is_string( $record['client_id'] ) && '' !== $record['client_id']
			&& hash_equals( $record['client_id'], (string) $client_id );
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
	 * - There is no WP user in a machine flow, so no user-capability clamp applies.
	 *   Instead the granted scope is capped by a SERVER-SIDE ceiling — the
	 *   'oauth_client_credentials_max_scope' setting, default 'write' — applied via
	 *   apply_scope_ceiling(). Requesting a scope above the ceiling is silently
	 *   downgraded to the ceiling, never refused and never honoured. Only a site
	 *   administrator can raise the ceiling to 'admin'; the token request cannot.
	 * - Empty/absent scope still defaults to ['read'] (same as authorization_code).
	 * - No refresh token is issued (parity with legacy behavior).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	private function handle_token_client_credentials( $request ) {
		$rate_limit_check = $this->check_rate_limit( 'oauth-client:' . $this->get_client_ip() );
		if ( is_wp_error( $rate_limit_check ) ) {
			// An OAuth error body (RFC 6749 §5.2), like the revoke endpoint's
			// limiter answer — not the REST framework's error shape.
			return $this->no_store( $this->wpe_to_response( $rate_limit_check ) );
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

		// Server-side ceiling. Every other issuance path clamps the requested scope
		// against something the caller does not control: the authorization_code path
		// clamps to the consenting user's capabilities, the broker path can never
		// produce 'admin' at all. This path clamped against nothing — whoever held
		// the client secret could ask for scope=admin and be handed
		// ['read','write','admin'], self-granting full administrative reach over the
		// site's REST surface from a machine credential that was never intended to
		// carry it.
		//
		// The ceiling is a site setting, not a request parameter, so raising it to
		// 'admin' is a deliberate administrator action recorded in wp_options.
		// get_oauth_settings() resolves it through a strict allow-list with a
		// 'write' fallback, so a malformed stored value cannot widen the ceiling.
		$scopes = $this->apply_scope_ceiling( $scopes, $oauth_settings['oauth_client_credentials_max_scope'] );

		$token_data = $this->issue_oauth_access_token( $scopes, $oauth_settings['oauth_token_ttl'], array( 'client_id' => $client_id ) );
		if ( is_wp_error( $token_data ) ) {
			return $this->no_store( $this->wpe_to_response( $token_data ) );
		}

		$this->log_oauth_event(
			'oauth_token_issued',
			array(
				'grant_type' => 'client_credentials',
				'client_id'  => $client_id,
				'user_id'    => 0,
				'scope'      => isset( $token_data['scope'] ) ? (string) $token_data['scope'] : '',
			)
		);

		return $this->no_store( new WP_REST_Response( $token_data, 200 ) );
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
		$params = array(
			'response_type'         => sanitize_key( $this->scalar_param( $request, 'response_type' ) ),
			'client_id'             => sanitize_key( $this->scalar_param( $request, 'client_id' ) ),
			'redirect_uri'          => esc_url_raw( $this->scalar_param( $request, 'redirect_uri' ) ),
			'code_challenge'        => sanitize_text_field( $this->scalar_param( $request, 'code_challenge' ) ),
			'code_challenge_method' => strtoupper( sanitize_key( $this->scalar_param( $request, 'code_challenge_method' ) ) ),
			'scope'                 => sanitize_text_field( $this->scalar_param( $request, 'scope' ) ),
			// Echoed back byte-exact (RFC 6749 §4.1.2), so validated in
			// validate_authorize_params() instead of sanitised: sanitize_text_field()
			// strips %xx sequences and would hand the client a different state.
			'state'                 => $this->scalar_param( $request, 'state' ),
			// RFC 8707.  Read raw (trimmed), not through esc_url_raw(): it is
			// only ever compared after normalize_resource(), never echoed
			// unescaped or followed, and esc_url_raw() could turn an invalid
			// value into a valid-looking one.  A non-scalar (resource[]=…)
			// becomes a value that matches nothing.
			'resource'              => $this->resource_param( $request ),
		);

		return $params;
	}

	/** Maximum length of the `state` parameter. */
	const STATE_MAX_LENGTH = 1024;

	/**
	 * Whether a `state` value may be echoed back as-is.
	 *
	 * @param mixed $state Value.
	 * @return bool
	 */
	public static function is_valid_state( $state ) {
		if ( ! is_string( $state ) ) {
			return false;
		}
		if ( strlen( $state ) > self::STATE_MAX_LENGTH ) {
			return false;
		}
		return '' === $state || 1 === preg_match( '/^[\x20-\x7E]+$/', $state );
	}

	/**
	 * Read a request parameter as a string; arrays and objects become ''.
	 *
	 * A crafted `client_id[]=…` must fail validation, not raise an
	 * "Array to string conversion" warning and continue as "Array".
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $key     Parameter name.
	 * @return string
	 */
	private function scalar_param( $request, $key ) {
		$value = $request->get_param( $key );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Read the `resource` parameter (RFC 8707).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string '' when absent; a non-matching marker when not a string.
	 */
	private function resource_param( $request ) {
		$value = $request->get_param( 'resource' );
		if ( null === $value ) {
			return '';
		}
		if ( ! is_string( $value ) ) {
			return 'invalid:resource';
		}
		return trim( $value );
	}

	/**
	 * Validate the `resource` of a token request (RFC 8707 §2.2).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|null Error response, or null when acceptable or absent.
	 */
	private function check_token_request_resource( $request ) {
		$resource = $this->resource_param( $request );
		if ( '' !== $resource && ! Mcpwp_OAuth_Well_Known::is_acceptable_resource( $resource ) ) {
			return $this->oauth_error_response( 'invalid_target', 'resource must be this site\'s MCP endpoint URL.', 400 );
		}
		return null;
	}

	/**
	 * Resolve a client_id: a DCR-registered client first, then the single
	 * legacy client configured in settings (PR-2 D6).
	 *
	 * @param string $client_id sanitize_key()ed client id.
	 * @return array|null {type:'registered'|'legacy', client_id, client_name, redirect_uris?}
	 */
	public function resolve_client( $client_id ) {
		$client_id = (string) $client_id;
		if ( '' === $client_id ) {
			return null;
		}

		$registered = Mcpwp_OAuth_Clients::find( $client_id );
		if ( null !== $registered ) {
			return array(
				'type'          => 'registered',
				'client_id'     => $client_id,
				'client_name'   => (string) $registered['client_name'],
				'redirect_uris' => (array) $registered['redirect_uris'],
				'record'        => $registered,
			);
		}

		$oauth_settings = $this->get_oauth_settings();
		if ( '' !== $oauth_settings['oauth_client_id'] && hash_equals( $oauth_settings['oauth_client_id'], $client_id ) ) {
			return array(
				'type'        => 'legacy',
				'client_id'   => $client_id,
				'client_name' => $client_id,
			);
		}

		return null;
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

		// state round-trips byte-exact, so it is validated, never rewritten:
		// visible ASCII plus space (RFC 6749 VSCHAR, %x20-7E), at most
		// STATE_MAX_LENGTH bytes.  Anything else is refused as JSON — before
		// the redirect_uri is trusted, so never by redirect.
		if ( ! self::is_valid_state( $params['state'] ) ) {
			return new WP_Error( 'invalid_request', 'state must be printable ASCII of at most 1024 characters.', array( 'status' => 400 ) );
		}

		// Resolve client_id: registered (DCR) first, legacy settings client second.
		$client = $this->resolve_client( $params['client_id'] );
		if ( null === $client ) {
			return new WP_Error( 'invalid_client', 'Unknown or missing client_id.', array( 'status' => 400 ) );
		}

		// Validate redirect_uri against THIS client's list.  Never fall back
		// to the other store: a registered client cannot borrow the legacy
		// allow-list, and the legacy client cannot borrow a registration.
		if ( empty( $params['redirect_uri'] ) ) {
			return new WP_Error( 'invalid_request', 'redirect_uri is required.', array( 'status' => 400 ) );
		}
		if ( 'registered' === $client['type'] ) {
			if ( ! Mcpwp_OAuth_Clients::client_allows_redirect( $client['record'], $params['redirect_uri'] ) ) {
				return new WP_Error( 'invalid_request', 'redirect_uri is not registered for this client.', array( 'status' => 400 ) );
			}
		} elseif ( ! $this->is_redirect_uri_allowed( $params['redirect_uri'] ) ) {
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

		// RFC 7636 §4.2: 43-128 characters of the unreserved set.  A one-
		// character "challenge" is a guessable proof, not a proof.
		if ( ! self::is_valid_pkce_string( $params['code_challenge'] ) ) {
			return new WP_Error( 'invalid_request', 'code_challenge must be 43 to 128 characters of A-Z a-z 0-9 - . _ ~.', array( 'status' => 400 ) );
		}

		// RFC 8707 (D1): optional, but when present it must name this MCP
		// endpoint (or the pre-PR-2 origin identifier).  Returned as JSON,
		// not redirected, like every other error here.
		$resource = isset( $params['resource'] ) ? (string) $params['resource'] : '';
		if ( '' !== $resource && ! Mcpwp_OAuth_Well_Known::is_acceptable_resource( $resource ) ) {
			return new WP_Error( 'invalid_target', 'resource must be this site\'s MCP endpoint URL.', array( 'status' => 400 ) );
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
	 * HMAC binding the consent form to the parameters it displayed.
	 *
	 * Covers every authorize parameter that decides what is minted and where
	 * it goes, plus the approving user.  The scope covered is the CLAMPED
	 * scope shown on screen, so the POST can only narrow it (by unticking
	 * write), never widen it.  Keyed with the site's nonce salt; a value from
	 * another site, user or request does not verify.
	 *
	 * @param array $params Authorize parameters (scope already clamped).
	 * @return string 64 hex characters.
	 */
	private function consent_binding( $params ) {
		$fields = array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'scope', 'state', 'resource' );
		$parts  = array( 'mcpwp-oauth-consent-v1', (string) get_current_user_id() );
		foreach ( $fields as $field ) {
			$value   = isset( $params[ $field ] ) ? $this->stringify_param_value( $params[ $field ] ) : '';
			$parts[] = $field . '=' . $value;
		}
		// Length-prefixed join: no field value can forge a boundary.
		$message = '';
		foreach ( $parts as $part ) {
			$message .= strlen( $part ) . ':' . $part . ';';
		}

		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : 'mcpwp-oauth-consent';
		return hash_hmac( 'sha256', $message, $secret );
	}

	/**
	 * Build the minimal HTML consent screen.
	 *
	 * What it shows (PR-2 D7), because with DCR the client name is chosen by
	 * whoever registered it:
	 *  - the client name, marked as supplied by the app and not verified;
	 *  - the HOST the authorization code will be sent to, in bold — that, not
	 *    the name, is what decides who receives access;
	 *  - this site, the WordPress user who is approving, and the scope in
	 *    words, with write as a box the user can untick (read is fixed).
	 *
	 * All OAuth parameters are embedded as hidden fields, bound by an HMAC
	 * (consent_binding()).  The CSRF nonce is generated fresh per render and
	 * is the sole CSRF defence on the consent POST — see handle_authorize_post().
	 *
	 * @param array $params Validated authorize parameters (scope already clamped to a string).
	 * @return string HTML document.
	 */
	public function build_consent_screen_html( $params ) {
		$nonce       = wp_create_nonce( 'mcpwp_oauth_consent' );
		$site_name   = function_exists( 'get_bloginfo' ) ? esc_html( get_bloginfo( 'name' ) ) : 'this site';
		$scope_value = $this->stringify_param_value( isset( $params['scope'] ) ? $params['scope'] : '' );
		$scopes      = $this->parse_oauth_scope_string( $scope_value );
		$user_login  = '';
		if ( function_exists( 'wp_get_current_user' ) ) {
			$current_user = wp_get_current_user();
			$user_login   = esc_html( $current_user->user_login );
		}

		$client_id = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';
		$client    = $this->resolve_client( $client_id );
		if ( null !== $client && 'registered' === $client['type'] ) {
			$name        = '' !== $client['client_name'] ? $client['client_name'] : __( 'An unnamed app', 'mcpwp' );
			$client_html = '<strong>' . esc_html( $name ) . '</strong> <em>' . esc_html__( '(name supplied by the app, not verified)', 'mcpwp' ) . '</em>';
		} elseif ( null !== $client ) {
			$client_html = '<strong>' . esc_html( $client_id ) . '</strong> <em>' . esc_html__( '(an app configured by this site\'s administrator)', 'mcpwp' ) . '</em>';
		} else {
			// Unreachable through the handlers (validate_authorize_params()
			// refuses unknown clients first); never vouch for an unknown id.
			$client_html = '<strong>' . esc_html( $client_id ) . '</strong> <em>' . esc_html__( '(unknown app)', 'mcpwp' ) . '</em>';
		}

		$redirect_uri  = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';
		$redirect_host = (string) wp_parse_url( $redirect_uri, PHP_URL_HOST );
		$redirect_port = wp_parse_url( $redirect_uri, PHP_URL_PORT );
		if ( $redirect_port ) {
			$redirect_host .= ':' . (int) $redirect_port;
		}

		$post_url = esc_url( Mcpwp_OAuth_Well_Known::get_front_channel_authorize_url() );

		$hidden_fields = '';
		foreach ( $params as $key => $value ) {
			$hidden_fields .= sprintf(
				'<input type="hidden" name="%s" value="%s">',
				esc_attr( $key ),
				// Byte-exact round trip: esc_attr() does not re-encode an
				// existing entity, so a state containing "&amp;" would come
				// back as "&".  Always-encode escaping is just as safe here.
				htmlspecialchars( $this->stringify_param_value( $value ), ENT_QUOTES, 'UTF-8', true )
			);
		}
		$hidden_fields .= '<input type="hidden" name="_mcpwp_oauth_binding" value="' . esc_attr( $this->consent_binding( $params ) ) . '">';

		$scope_items = '<li><label><input type="checkbox" checked disabled> '
			. esc_html__( 'Read your site\'s content', 'mcpwp' ) . '</label></li>';
		if ( in_array( 'write', $scopes, true ) ) {
			$scope_items .= '<li><label><input type="checkbox" name="scope_write" value="1" checked> '
				. esc_html__( 'Create and edit content (drafts, pages, posts, media, SEO, Elementor)', 'mcpwp' ) . '</label></li>';
		}

		return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
		   . '<meta name="viewport" content="width=device-width,initial-scale=1">'
		   . '<title>Authorize &mdash; ' . $site_name . '</title>'
		   . '<style>body{font-family:system-ui,sans-serif;max-width:480px;margin:60px auto;padding:0 16px}'
		   . 'h1{font-size:1.2rem}.scope{background:#f0f4ff;border:1px solid #c7d2fe;border-radius:6px;padding:10px 14px;margin:16px 0}'
		   . '.scope ul{list-style:none;padding:0;margin:8px 0 0}.scope li{margin:4px 0}'
		   . '.redirect{background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:10px 14px;margin:16px 0}'
		   . '.btn{display:inline-block;padding:10px 24px;border:none;border-radius:4px;cursor:pointer;font-size:1rem}'
		   . '.btn-allow{background:#2563eb;color:#fff;margin-right:8px}'
		   . '.btn-deny{background:#e5e7eb;color:#111}'
		   . '</style></head><body>'
		   . '<h1>Authorize access to <strong>' . $site_name . '</strong></h1>'
		   . '<p>' . $client_html . ' ' . esc_html__( 'is asking to connect to this site', 'mcpwp' )
		   . ( $user_login ? ' ' . esc_html__( 'as', 'mcpwp' ) . ' <strong>' . $user_login . '</strong>' : '' ) . '.</p>'
		   . '<div class="redirect">' . esc_html__( 'If you approve, access is sent to', 'mcpwp' ) . ' <strong>' . esc_html( $redirect_host ) . '</strong>. '
		   . esc_html__( 'Approve only if you started this connection from that app.', 'mcpwp' ) . '</div>'
		   . '<form method="POST" action="' . $post_url . '">'
		   . '<div class="scope"><strong>' . esc_html__( 'It will be able to:', 'mcpwp' ) . '</strong><ul>' . $scope_items . '</ul>'
		   . '<p style="margin:8px 0 0;font-size:.9em">' . esc_html__( 'Never administrator access: settings, API keys and approvals stay out of reach.', 'mcpwp' ) . '</p></div>'
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
	 *   write  — requires current_user_can('edit_posts')
	 *   read   — always granted for any logged-in user
	 *   admin  — never (D8): a requested admin is clamped to write, not
	 *            refused, so a client asking for every scope still connects.
	 *
	 * D9: Empty/absent scope requests read + write (never admin), clamped.
	 *
	 * @param string $scope_string Space-separated requested scopes (may be empty).
	 * @return string[] Clamped scope array.
	 */
	public function clamp_scope_to_user_capability( $scope_string ) {
		// D9: an authorization request with NO scope asks for read + write.
		// ChatGPT and Claude often send none, and a read-only token makes the
		// connector look broken on the first write.  The capability clamp
		// below still applies, so a Subscriber still gets read.  (Never via
		// get_default_api_key_scopes(), which would include admin — P0-B.)
		if ( '' === trim( (string) $scope_string ) ) {
			$requested = array( 'read', 'write' );
		} else {
			$requested = $this->parse_oauth_scope_string( $scope_string );
		}

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
		// Determine the ceiling for this user.  D8: OAuth never grants admin,
		// so an administrator's ceiling is write, the same as an editor's.
		// Admin stays behind a deliberately created API key.
		if ( function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' ) ) {
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
		// D8: the ceiling is write at most, even for manage_options — this
		// path serves the authorization_code exchange and every refresh, so a
		// pre-PR-2 admin refresh token is narrowed to write on its next use.
		$ceiling = 'read';
		if ( function_exists( 'user_can' ) ) {
			if ( user_can( $user_id, 'edit_posts' ) ) {
				$ceiling = 'write';
			}
		} elseif ( function_exists( 'get_userdata' ) ) {
			// Fallback: load user and check roles/caps directly.
			$user = get_userdata( $user_id );
			if ( $user ) {
				$roles = isset( $user->roles ) ? (array) $user->roles : array();
				if ( array_intersect( $roles, array( 'administrator', 'editor', 'author', 'contributor' ) ) ) {
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
	 * Whether a PKCE code_challenge / code_verifier has the RFC 7636 shape:
	 * 43 to 128 characters of the unreserved set (§4.1, §4.2).
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_valid_pkce_string( $value ) {
		return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9\-._~]{43,128}\z/', $value );
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
	 * @param int      $user_id   WordPress user ID who consented.
	 * @param string   $client_id Client the tokens are bound to (#1101).
	 * @param string   $family_id Refresh family; a new one when empty.
	 * @param int      $issued_at When the caller checked revocation (microseconds); now when 0.
	 * @return array|WP_Error Token response payload including refresh_token, or 503 when it could not be stored.
	 */
	private function issue_oauth_access_token_with_user( $scopes, $ttl, $user_id, $client_id = '', $family_id = '', $issued_at = 0 ) {
		// #1101: every token says which client it was issued to and which
		// refresh family it belongs to, so a client, user or family can be
		// revoked by epoch (Mcpwp_OAuth_Revocation).
		$family_id      = '' !== (string) $family_id ? (string) $family_id : Mcpwp_OAuth_Revocation::new_family_id();
		$issued_at      = (int) $issued_at > 0 ? (int) $issued_at : Mcpwp_OAuth_Revocation::now();
		$token_response = $this->issue_oauth_access_token(
			$scopes,
			$ttl,
			array(
				'user_id'   => (int) $user_id,
				'client_id' => (string) $client_id,
				'family_id' => $family_id,
				'issued_at' => $issued_at,
			)
		);
		if ( is_wp_error( $token_response ) ) {
			return $token_response;
		}

		// Issue a refresh token (separate, longer-lived transient).
		// P1-2: the refresh lifetime is its own setting, decoupled from the
		// access TTL (it used to be min(30 d, 10 x access TTL) — 10 hours at
		// the default 3600, so connections died overnight).  Rotation on
		// every use is unchanged.
		$refresh_ttl    = self::refresh_token_ttl();
		$refresh_token  = 'mcpwp_rt_' . bin2hex( random_bytes( 32 ) );
		$refresh_record = array(
			'scopes'     => $scopes,
			'user_id'    => $user_id,
			'client_id'  => (string) $client_id,
			'family_id'  => $family_id,
			'aud'        => Mcpwp_OAuth_Well_Known::get_canonical_resource(),
			'created_at' => time(),
			'issued_at'  => $issued_at,
			'expires_at' => time() + $refresh_ttl,
		);

		// #1101 PR-3b: the hashed token table, never a transient.  If the row
		// cannot be written, the access token just issued is revoked and
		// nothing is handed out (fail closed).
		if ( ! Mcpwp_OAuth_Token_Store::insert( $refresh_token, Mcpwp_OAuth_Token_Store::TYPE_REFRESH, $refresh_record ) ) {
			Mcpwp_OAuth_Token_Store::revoke( $token_response['access_token'] );
			return new WP_Error( 'temporarily_unavailable', 'The token could not be stored. Try again.', array( 'status' => 503 ) );
		}

		$token_response['refresh_token'] = $refresh_token;

		return $token_response;
	}

	/**
	 * Revoke tokens this request minted but must not hand out (it lost a
	 * claim race).
	 *
	 * @param array $token_response Issued tokens.
	 */
	private function discard_minted( array $token_response ) {
		foreach ( array( 'access_token', 'refresh_token' ) as $field ) {
			if ( ! empty( $token_response[ $field ] ) ) {
				Mcpwp_OAuth_Token_Store::revoke( (string) $token_response[ $field ] );
			}
		}
	}

	/**
	 * Refresh-token lifetime: 30 days by default, clamped to 14-30 days.
	 *
	 * Configurable through the `mcpwp_oauth_refresh_ttl_days` filter only —
	 * no UI, and never shorter than 14 days or longer than 30.
	 *
	 * @return int Seconds.
	 */
	public static function refresh_token_ttl() {
		$days = function_exists( 'apply_filters' ) ? (int) apply_filters( 'mcpwp_oauth_refresh_ttl_days', 30 ) : 30;
		$days = max( 14, min( 30, $days ) );
		return $days * DAY_IN_SECONDS;
	}

	/**
	 * Write an OAuth decision to the activity log: who, which client, what
	 * scope.  A synthetic request carrying ONLY those fields is logged, so a
	 * code, verifier, token, nonce or consent binding can never reach the log.
	 *
	 * @param string $action Log action.
	 * @param array  $fields Safe fields (client_id, scope, user_id, grant_type).
	 */
	private function log_oauth_event( $action, array $fields ) {
		if ( ! isset( $fields['user_id'] ) ) {
			$fields['user_id'] = get_current_user_id();
		}
		$entry = new WP_REST_Request( 'POST', '/' . $this->namespace . '/oauth/' . str_replace( 'oauth_', '', $action ) );
		$entry->set_body_params( $fields );
		$this->log_activity( $action, $entry );
	}

	/**
	 * The plain refusal page for a user below the minimum role (P0b).
	 *
	 * @return WP_REST_Response
	 */
	private function min_role_refusal_response() {
		$labels = array(
			'editor'        => __( 'Editor', 'mcpwp' ),
			'author'        => __( 'Author', 'mcpwp' ),
			'administrator' => __( 'Administrator', 'mcpwp' ),
		);
		$role = Mcpwp_OAuth_Well_Known::get_min_role();

		$html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . esc_html__( 'Not allowed', 'mcpwp' ) . '</title>'
			. '<style>body{font-family:system-ui,sans-serif;max-width:480px;margin:60px auto;padding:0 16px}</style></head><body>'
			. '<h1>' . esc_html__( 'Your WordPress role can\'t connect AI apps on this site; ask an administrator.', 'mcpwp' ) . '</h1>'
			. '<p>' . esc_html(
				sprintf(
					/* translators: %s: role name */
					__( 'Connecting an AI app needs the %s role or higher.', 'mcpwp' ),
					$labels[ $role ]
				)
			) . '</p></body></html>';

		$response = new WP_REST_Response( $html, 403 );
		$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		$response->header( 'X-Frame-Options', 'DENY' );
		$response->header( 'Content-Security-Policy', "frame-ancestors 'none'" );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
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
	 * Mark a token response uncacheable (RFC 6749 §5.1).
	 *
	 * @param WP_REST_Response $response Response.
	 * @return WP_REST_Response
	 */
	private function no_store( $response ) {
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
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
		//
		// Values are percent-encoded first: add_query_arg() deliberately does
		// not encode what it is handed, and these values legitimately contain
		// characters that may not appear raw in a URI — `scope` is a
		// space-separated list and `redirect_uri` is itself a full URL.  Left
		// raw they would produce a Location header that is not a valid
		// URI-reference.  urlencode() (space -> "+") is the encoding OAuth 2.0
		// specifies for these parameters (RFC 6749 §3.1, application/
		// x-www-form-urlencoded).
		if ( 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			$params = array_filter( $this->extract_authorize_params( $request ) );
			if ( ! empty( $params ) ) {
				$encoded = array();
				foreach ( $params as $key => $value ) {
					$encoded[ $key ] = urlencode( (string) $value );
				}
				$target = add_query_arg( $encoded, $target );
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
