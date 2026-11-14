<?php
/**
 * OAuth 2.1 Well-Known Metadata Endpoints
 *
 * Serves the two RFC-mandated discovery documents at the domain root (not under
 * /wp-json) so that MCP clients (Claude, ChatGPT) can auto-discover the
 * authorization server without any prior configuration.
 *
 *   GET /.well-known/oauth-protected-resource   — RFC 9728
 *   GET /.well-known/oauth-authorization-server — RFC 8414
 *   GET|POST /mcpwp-oauth/authorize             — interactive consent screen
 *
 * WordPress's REST API is mounted at /wp-json, so these root-level paths are
 * served via a `do_parse_request` filter (priority 1) which intercepts the
 * request before WordPress's own rewrite engine processes it.  When the path
 * matches, we emit JSON + exit immediately.
 *
 * The consent screen rides the same interceptor for a different reason: core
 * treats a cookie-authenticated REST request with no X-WP-Nonce as logged out
 * (rest_cookie_check_errors → wp_set_current_user( 0 )), and a browser
 * following an OAuth redirect can never send that header — so on /wp-json the
 * consent handler bounced the user to wp-login forever.  Here, cookie auth is
 * already resolved: wp-settings.php sets up the current user before wp() calls
 * WP::parse_request().
 *
 * The oauth-authorization-server document is gated on the `oauth_enabled`
 * setting alone, which defaults to ON when it was never stored (PR-2 D10).
 * The oauth-protected-resource document is gated on `oauth_enabled` OR
 * mumcp.com broker enrollment.  Its `authorization_servers` names this site's
 * own issuer whenever local OAuth is on, and mumcp.com only when local OAuth
 * is off and the site is broker-enrolled (D11: the local AS takes precedence;
 * broker bearer tokens are still accepted).  When neither is active the filter
 * returns true (WordPress processes the request normally, resulting in a 404
 * from the theme or rewrite fallback).
 *
 * The protected resource is the MCP endpoint itself, rest_url('mcpwp/v1/mcp')
 * (D1), and its metadata is also served at the RFC 9728 §3.1 path-insertion
 * URL a strictly compliant client derives from that URL (D12):
 *   /.well-known/oauth-protected-resource/wp-json/mcpwp/v1/mcp
 *
 * Security note: these documents contain NO secrets — only public metadata
 * (endpoint URLs, supported algorithms, resource identifier).  They are always
 * returned without authentication.
 *
 * @package MCPWP
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles /.well-known/oauth-* at the WordPress domain root.
 */
class Mcpwp_OAuth_Well_Known {

	/**
	 * Path segment of the front-channel authorization endpoint.
	 *
	 * @var string
	 */
	const FRONT_CHANNEL_AUTHORIZE_SLUG = '/mcpwp-oauth/authorize';

	/**
	 * Query var that reaches the same endpoint on sites without rewrite rules.
	 *
	 * A plain-permalink site has no rewrite rules, so a pretty path 404s at the
	 * web server before WordPress ever loads.  This is the same accommodation
	 * core makes for the REST API with ?rest_route=.
	 *
	 * @var string
	 */
	const FRONT_CHANNEL_QUERY_VAR = 'mcpwp_oauth';

	/**
	 * Register WordPress hooks.
	 *
	 * Called once during plugin bootstrap.
	 */
	public static function init() {
		add_filter( 'do_parse_request', array( __CLASS__, 'maybe_serve_well_known' ), 1, 2 );

		// Add WWW-Authenticate discovery header to 401 REST responses when OAuth
		// is enabled.  This is how Claude and ChatGPT discover the OAuth flow:
		// they receive 401 + WWW-Authenticate: Bearer resource_metadata="<url>"
		// on their first unauthenticated request and follow the discovery chain.
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_www_authenticate_header' ), 10, 3 );
	}

	/**
	 * Inject WWW-Authenticate header on 401 responses for MCPWP routes.
	 *
	 * Fires when the legacy self-issued OAuth AS is enabled OR the site is
	 * enrolled with the mumcp.com broker (same independent-gate pair as
	 * should_serve_protected_resource_metadata()), so a broker-only site
	 * (legacy oauth_enabled never turned on) still advertises discovery on an
	 * unauthenticated request. Leaves the existing X-API-Key auth path
	 * completely unaffected (that path still returns 401, just without the
	 * discovery header when neither gate is active).
	 *
	 * Header format per MCP Auth Spec §2.1 / RFC 9728:
	 *   WWW-Authenticate: Bearer resource_metadata="<site>/.well-known/oauth-protected-resource"
	 *
	 * @param WP_HTTP_Response $response Dispatched response.
	 * @param WP_REST_Server   $server   REST server.
	 * @param WP_REST_Request  $request  Incoming request.
	 * @return WP_HTTP_Response Unmodified or with added header.
	 */
	public static function add_www_authenticate_header( $response, $server, $request ) {
		if ( ! method_exists( $response, 'get_status' ) || ! method_exists( $response, 'header' ) ) {
			return $response;
		}

		if ( 401 !== (int) $response->get_status() ) {
			return $response;
		}

		if ( ! method_exists( $request, 'get_route' ) ) {
			return $response;
		}

		$route = (string) $request->get_route();
		if ( 0 !== strpos( $route, '/mcpwp/v1/' ) && '/mcpwp/v1/mcp' !== $route ) {
			return $response;
		}

		// RFC 6750 §3.1: an expired/revoked/unknown OAuth token is answered
		// with error="invalid_token", so an MCP client re-runs discovery and
		// auth instead of retrying. Same challenge for every cause.
		$data          = method_exists( $response, 'get_data' ) ? $response->get_data() : null;
		$invalid_token = is_array( $data ) && isset( $data['code'] ) && 'invalid_token' === $data['code'];

		$discoverable = self::is_legacy_oauth_enabled() || self::is_broker_enrolled();
		if ( ! $discoverable && ! $invalid_token ) {
			return $response;
		}

		// D12: point at the RFC 9728 path-insertion URL for the MCP resource —
		// the same URL a strictly compliant client would derive by itself.
		$params = array();
		if ( $invalid_token ) {
			$params[] = 'error="invalid_token"';
		}
		if ( $discoverable ) {
			$params[] = 'resource_metadata="' . self::get_protected_resource_metadata_url() . '"';
		}
		$response->header( 'WWW-Authenticate', 'Bearer ' . implode( ', ', $params ) );

		return $response;
	}

	/**
	 * Intercept /.well-known/oauth-* requests before WP rewrite runs.
	 *
	 * Hooked on `do_parse_request` (priority 1). Returning false tells WordPress
	 * to skip its own rewrite logic for this request; we have already sent the
	 * full HTTP response and called exit().  Returning true means "carry on,
	 * WordPress" (path did not match or OAuth is disabled).
	 *
	 * Matches TWO path shapes for a subdirectory-installed site (e.g. this
	 * WordPress at /staging/5707), since MCP clients don't consistently agree
	 * on which one to use:
	 *   - RFC 8414 §3.1 insertion form: /.well-known/oauth-authorization-server/staging/5707
	 *     (the well-known segment is spliced in BEFORE the site's own path —
	 *     this is what a strictly spec-compliant client computes from an
	 *     issuer that has a path component, and is now what our own issuer/
	 *     resource claims below actually point at).
	 *   - Simple suffix form: /staging/5707/.well-known/oauth-authorization-server
	 *     (some real-world client implementations just append the well-known
	 *     segment to whatever base URL they already have, instead of doing
	 *     the RFC 8414 splice — support this defensively too, no downside).
	 * A root-installed site (no path prefix) only ever needs the bare path,
	 * which both forms collapse to.
	 *
	 * @param bool $do_parse   Whether WordPress should continue parsing the request.
	 * @param WP   $wp         Current WP instance (has ->request set to the request path).
	 * @return bool
	 */
	public static function maybe_serve_well_known( $do_parse, $wp ) {
		// Determine the request path, stripping leading slash.
		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Strip query string for path comparison.
		$path_only = strtok( $request_path, '?' );
		$path_only = '/' . ltrim( (string) $path_only, '/' );

		// Cheap reject: every discovery path carries the well-known segment,
		// and building the candidate list below costs option reads on every
		// front-end request.  Only the front-channel check remains otherwise.
		if ( false !== strpos( $path_only, '/.well-known/oauth-' ) ) {
			$prefix = self::get_site_path_prefix();

			$protected_resource_paths = array(
				'/.well-known/oauth-protected-resource' . $prefix,
				$prefix . '/.well-known/oauth-protected-resource',
				// D12: RFC 9728 §3.1 path insertion for the MCP resource URL
				// (pretty permalinks: /.well-known/oauth-protected-resource/wp-json/mcpwp/v1/mcp;
				// plain permalinks: /.well-known/oauth-protected-resource/index.php).
				self::get_protected_resource_metadata_path(),
			);
			if ( in_array( untrailingslashit( $path_only ), $protected_resource_paths, true ) ) {
				// Broker enrollment independently enables this document, regardless
				// of the `oauth_enabled` setting — see should_serve_protected_resource_metadata().
				if ( ! self::should_serve_protected_resource_metadata() ) {
					return $do_parse;
				}
				self::serve_protected_resource_metadata();
				exit;
			}

			$authorization_server_paths = array(
				'/.well-known/oauth-authorization-server' . $prefix,
				$prefix . '/.well-known/oauth-authorization-server',
			);
			if ( in_array( $path_only, $authorization_server_paths, true ) ) {
				// This document only ever describes this site's own AS, so it
				// stays gated on the local OAuth setting alone.
				if ( ! self::is_legacy_oauth_enabled() ) {
					return $do_parse;
				}
				self::serve_authorization_server_metadata();
				exit;
			}
		}

		if ( self::is_front_channel_authorize_request( $path_only ) ) {
			// Same gate as the AS metadata document: this endpoint only exists
			// for this site's own self-issued AS.  A broker-enrolled site never
			// sends users here (mumcp.com is the AS).  When the gate is closed
			// the request falls through to WordPress and behaves exactly as any
			// other unknown request would — no new surface is exposed.
			if ( ! self::is_legacy_oauth_enabled() || ! class_exists( 'Mcpwp_REST_OAuth' ) ) {
				return $do_parse;
			}
			Mcpwp_REST_OAuth::serve_front_channel_authorize();
			exit;
		}

		return $do_parse;
	}

	/**
	 * Whether this request targets the front-channel authorize endpoint.
	 *
	 * Matches three shapes:
	 *   - <home path prefix>/mcpwp-oauth/authorize   (what we advertise)
	 *   - <site path prefix>/mcpwp-oauth/authorize   (defensive: subdirectory
	 *     installs where home_url() and site_url() disagree)
	 *   - any path carrying ?mcpwp_oauth=authorize   (sites without rewrites)
	 *
	 * @param string $path_only Request path with a leading slash, query stripped.
	 * @return bool
	 */
	public static function is_front_channel_authorize_request( $path_only ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public OAuth front-channel entry point; the consent POST carries its own CSRF nonce, verified in handle_authorize_post().
		$query_var = isset( $_GET[ self::FRONT_CHANNEL_QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::FRONT_CHANNEL_QUERY_VAR ] ) ) : '';
		if ( 'authorize' === $query_var ) {
			return true;
		}

		// Cheap reject before touching options.  This method runs on EVERY
		// front-end request via do_parse_request, and building the candidate
		// list below costs two option reads (home_url() + get_site_url()).
		if ( false === strpos( (string) $path_only, self::FRONT_CHANNEL_AUTHORIZE_SLUG ) ) {
			return false;
		}

		$candidates = array_unique(
			array(
				self::get_front_channel_authorize_path(),
				self::get_site_path_prefix() . self::FRONT_CHANNEL_AUTHORIZE_SLUG,
			)
		);

		return in_array( untrailingslashit( (string) $path_only ), $candidates, true );
	}

	// -------------------------------------------------------------------------
	// RFC 9728 — Protected Resource Metadata
	// -------------------------------------------------------------------------

	/**
	 * Emit the RFC 9728 oauth-protected-resource JSON document.
	 *
	 * This tells a client WHERE to find the authorization server that protects
	 * this resource.  Required fields per the spec:
	 *   - resource           — canonical HTTPS URL of the protected resource
	 *   - authorization_servers — array of AS issuer URIs
	 *
	 * We add scopes_supported and bearer_methods_supported as optional hints.
	 */
	private static function serve_protected_resource_metadata() {
		self::emit_json( self::build_protected_resource_metadata() );
	}

	/**
	 * Build the RFC 9728 oauth-protected-resource metadata array.
	 *
	 * Split out from serve_protected_resource_metadata() so unit tests can
	 * assert on the exact shape without going through emit_json()'s exit().
	 *
	 * - `resource` is the MCP endpoint URL (D1).  RFC 9728 §3.3 requires it
	 *   to equal the URL the client is protecting — the URL the user pasted.
	 * - `authorization_servers` lists ISSUER identifiers (RFC 9728 §2), not
	 *   metadata URLs.  It is this site's issuer whenever local OAuth is on
	 *   (D11); mumcp.com only when local OAuth is off and the site is
	 *   broker-enrolled.
	 * - `scopes_supported` never lists admin: OAuth never grants it (D8).
	 *
	 * @return array{resource:string,authorization_servers:string[],scopes_supported:string[],bearer_methods_supported:string[]}
	 */
	public static function build_protected_resource_metadata() {
		if ( ! self::is_legacy_oauth_enabled() && self::is_broker_enrolled() ) {
			$authorization_servers = array( 'https://mumcp.com' );
		} else {
			$authorization_servers = array( self::get_issuer() );
		}

		return array(
			'resource'                 => self::get_canonical_resource(),
			'authorization_servers'    => $authorization_servers,
			'scopes_supported'         => array( 'read', 'write' ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	/**
	 * Whether the RFC 9728 oauth-protected-resource document should be served.
	 *
	 * True when the legacy self-issued AS is enabled OR the site is enrolled
	 * with the mumcp.com broker — the two gates are independent, so a
	 * broker-only site (legacy `oauth_enabled` never turned on) still gets
	 * discovery, and vice versa during any transition period.
	 *
	 * @return bool
	 */
	public static function should_serve_protected_resource_metadata() {
		return self::is_legacy_oauth_enabled() || self::is_broker_enrolled();
	}

	/**
	 * Whether this site's own (local) OAuth authorization server is enabled.
	 *
	 * D10: ON when `oauth_enabled` was never stored — new installs and
	 * upgrades that never touched the setting.  A stored value, on or off, is
	 * respected exactly; an explicit opt-out stays off.
	 *
	 * The name predates PR-2 (when local OAuth was the "legacy" path next to
	 * the broker) and is kept because callers outside this class use it.
	 *
	 * @return bool
	 */
	public static function is_legacy_oauth_enabled() {
		return self::resolve_oauth_enabled( get_option( 'mcpwp_settings', array() ) );
	}

	/**
	 * Resolve `oauth_enabled` from a stored settings value (pure, testable).
	 *
	 * The single rule every reader uses (this class, the auth trait's
	 * get_oauth_settings(), the admin notice), so "absent means on" cannot
	 * drift between the discovery documents and the token endpoint.
	 *
	 * @param mixed $settings Stored mcpwp_settings value.
	 * @return bool
	 */
	public static function resolve_oauth_enabled( $settings ) {
		if ( ! is_array( $settings ) || ! array_key_exists( 'oauth_enabled', $settings ) ) {
			return true;
		}
		return ! empty( $settings['oauth_enabled'] );
	}

	/**
	 * Option holding the minimum WordPress role allowed to approve an AI
	 * connection (PR-2 P0b).  Its own option: the settings sanitizer rebuilds
	 * mcpwp_settings from a fixed key list and would drop it.
	 *
	 * @var string
	 */
	const MIN_ROLE_OPTION = 'mcpwp_oauth_min_role';

	/**
	 * Minimum-role choices → the capability that defines each.
	 *
	 * @return array<string,string>
	 */
	public static function min_role_choices() {
		return array(
			'editor'        => 'edit_others_posts',
			'author'        => 'publish_posts',
			'administrator' => 'manage_options',
		);
	}

	/**
	 * The configured minimum role; Editor when unset or unrecognised.
	 *
	 * @return string
	 */
	public static function get_min_role() {
		$role    = (string) get_option( self::MIN_ROLE_OPTION, 'editor' );
		$choices = self::min_role_choices();
		return isset( $choices[ $role ] ) ? $role : 'editor';
	}

	/**
	 * Whether a user meets the minimum role (by its capability, so custom
	 * roles carrying it qualify and a demotion takes effect at once).
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function user_meets_min_role( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! function_exists( 'user_can' ) ) {
			return false;
		}
		$choices = self::min_role_choices();
		return (bool) user_can( $user_id, $choices[ self::get_min_role() ] );
	}

	/**
	 * Whether a user may hold or obtain an OAuth grant right now (P1-A).
	 *
	 * Three independent conditions, each checked on its own so none relies on
	 * another: the account still exists (get_userdata), it still holds the
	 * base `read` capability (a user whose roles were all removed has none),
	 * and it meets the minimum connection role.  Used at consent GET/POST,
	 * at code redemption, at refresh and on every token use — refresh does
	 * not rely on authorize having checked.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function user_can_connect( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) || ! function_exists( 'user_can' ) ) {
			return false;
		}
		if ( ! get_userdata( $user_id ) ) {
			return false;
		}
		if ( ! user_can( $user_id, 'read' ) ) {
			return false;
		}
		return self::user_meets_min_role( $user_id );
	}

	/**
	 * Whether `oauth_enabled` is ON only because it was never stored (D10).
	 *
	 * @return bool
	 */
	public static function oauth_enabled_by_default() {
		$settings = get_option( 'mcpwp_settings', array() );
		return ! is_array( $settings ) || ! array_key_exists( 'oauth_enabled', $settings );
	}

	/**
	 * Whether this site is enrolled with the mumcp.com broker.
	 *
	 * Enrollment is only "active" when the site can actually complete a broker
	 * introspection: it needs a `site_id`, a `site_credential`, AND an
	 * `audience`. A half-enrolled site (e.g. `site_id` written but no
	 * credential/audience yet) must NOT advertise the broker discovery chain
	 * (401 WWW-Authenticate header, protected-resource metadata) it cannot
	 * complete. This deliberately delegates to — and stays byte-identical with —
	 * Mcpwp_Broker_Auth::is_enrolled(), the same predicate the auth chokepoint
	 * uses before it will attempt introspection, so the "advertise" and "accept"
	 * gates can never disagree.
	 *
	 * @return bool
	 */
	public static function is_broker_enrolled() {
		if ( class_exists( 'Mcpwp_Broker_Auth' ) ) {
			$broker_auth = new Mcpwp_Broker_Auth();
			return $broker_auth->is_enrolled();
		}

		// Fallback when Mcpwp_Broker_Auth is not loaded: replicate its exact
		// three-field check rather than the looser site_id-only test.
		$enrollment = get_option( 'mcpwp_broker_enrollment', array() );
		return is_array( $enrollment )
			&& ! empty( $enrollment['site_id'] )
			&& ! empty( $enrollment['site_credential'] )
			&& ! empty( $enrollment['audience'] );
	}

	// -------------------------------------------------------------------------
	// RFC 8414 — Authorization Server Metadata
	// -------------------------------------------------------------------------

	/**
	 * Emit the RFC 8414 oauth-authorization-server JSON document.
	 *
	 * Required fields per RFC 8414 §2:
	 *   - issuer
	 *   - authorization_endpoint
	 *   - token_endpoint
	 *   - response_types_supported
	 *
	 * Additional MCP-required fields (MCP Auth Spec §2.3):
	 *   - grant_types_supported
	 *   - code_challenge_methods_supported
	 *   - token_endpoint_auth_methods_supported
	 *   - scopes_supported
	 */
	private static function serve_authorization_server_metadata() {
		self::emit_json( self::build_authorization_server_metadata() );
	}

	/**
	 * Build the RFC 8414 oauth-authorization-server metadata array.
	 *
	 * Split out from serve_authorization_server_metadata() so unit tests can
	 * assert on the exact shape without going through emit_json()'s exit(),
	 * mirroring build_protected_resource_metadata().
	 *
	 * authorization_endpoint points at the front-channel consent URL, NOT at
	 * the /wp-json REST route: core treats a cookie-authenticated REST request
	 * without an X-WP-Nonce as logged out, so a browser sent to the REST route
	 * can never satisfy is_user_logged_in() and loops on wp-login forever.
	 *
	 * @return array RFC 8414 metadata.
	 */
	public static function build_authorization_server_metadata() {
		$origin    = self::get_site_origin();
		$rest_base = rest_url( 'mcpwp/v1' );

		return array(
			'issuer'                                => self::get_issuer(),
			'authorization_endpoint'                => self::get_front_channel_authorize_url( $origin ),
			'token_endpoint'                        => $rest_base . '/oauth/token',
			// D3: RFC 7591 Dynamic Client Registration (public clients only).
			'registration_endpoint'                 => $rest_base . '/oauth/register',
			// #1101: RFC 7009 token revocation (RFC 8414 §2).
			'revocation_endpoint'                   => $rest_base . '/oauth/revoke',
			'revocation_endpoint_auth_methods_supported' => array( 'none' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			// P1-3: only public clients are registered (DCR answers "none").
			// The legacy settings client still authenticates with
			// client_secret_post at the token endpoint, but is not advertised.
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			// D8: OAuth never grants admin.
			'scopes_supported'                      => array( 'read', 'write' ),
			// D13: RFC 9207 — every authorization response carries `iss`.
			'authorization_response_iss_parameter_supported' => true,
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Emit a JSON body with proper headers and no output buffering.
	 *
	 * @param array $body Data to JSON-encode.
	 */
	private static function emit_json( $body ) {
		// Flush any output buffering that WordPress or plugins may have started.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		http_response_code( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'Access-Control-Allow-Origin: *' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Get the canonical HTTPS origin of this WordPress site.
	 *
	 * P2-F: Scheme is derived from get_site_url() as the source of truth.
	 * X-Forwarded-Proto is only honoured when a trusted-proxy constant is
	 * explicitly set (MCPWP_TRUST_PROXY_HEADERS === true), preventing a
	 * scheme-downgrade attack where a request with
	 * X-Forwarded-Proto: http causes an https site to emit an http issuer URL.
	 *
	 * @return string Origin without trailing slash, e.g. "https://example.com".
	 */
	public static function get_site_origin() {
		$site_url = get_site_url();
		$parsed   = wp_parse_url( $site_url );

		// P2-F: Start from the site_url scheme as the canonical source of truth.
		$proto = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';

		// Only honour X-Forwarded-Proto when an explicit trusted-proxy opt-in is set.
		// This prevents a spoofed X-Forwarded-Proto: http from downgrading https origins.
		$trust_proxy = defined( 'MCPWP_TRUST_PROXY_HEADERS' ) && MCPWP_TRUST_PROXY_HEADERS;

		if ( $trust_proxy && ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			$forwarded = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) );
			if ( in_array( $forwarded, array( 'https', 'http' ), true ) ) {
				$proto = $forwarded;
			}
		}

		// Additional guard: if site_url is https, never downgrade to http.
		if ( 'https' === ( isset( $parsed['scheme'] ) ? $parsed['scheme'] : '' ) ) {
			$proto = 'https';
		}

		$host = isset( $parsed['host'] ) ? $parsed['host'] : '';
		$port = isset( $parsed['port'] ) ? (int) $parsed['port'] : null;

		$origin = $proto . '://' . $host;

		// Only append non-standard ports.
		if ( null !== $port && ! in_array( $port, array( 80, 443 ), true ) ) {
			$origin .= ':' . $port;
		}

		return rtrim( $origin, '/' );
	}

	/**
	 * Get the path component of this WordPress install, if any.
	 *
	 * Empty string for a root install (siteurl == origin). For a subdirectory
	 * install (e.g. https://example.com/staging/5707), returns "/staging/5707"
	 * — needed so OAuth discovery works correctly for sites not installed at
	 * the domain root (see maybe_serve_well_known() for why this matters).
	 *
	 * @return string Path prefix with a leading slash, or '' for a root install.
	 */
	public static function get_site_path_prefix() {
		$parsed = wp_parse_url( get_site_url() );
		$path   = isset( $parsed['path'] ) ? rtrim( $parsed['path'], '/' ) : '';
		return $path;
	}

	/**
	 * This authorization server's issuer identifier (RFC 8414 §2).
	 *
	 * One builder for the AS metadata `issuer`, the PRM `authorization_servers`
	 * entry and the RFC 9207 `iss` authorization-response parameter, so the
	 * three can never disagree — a client compares them byte for byte.
	 *
	 * @return string
	 */
	public static function get_issuer() {
		return self::get_site_origin() . self::get_site_path_prefix();
	}

	/**
	 * The canonical protected-resource identifier: the MCP endpoint URL (D1).
	 *
	 * rest_url() is the source, but its scheme follows the CURRENT request's
	 * is_ssl() (core's 'rest' scheme), so behind a proxy that does not report
	 * TLS the same site could name itself http:// on one request and https://
	 * on the next — and a token's stored `aud` would stop matching.  The scheme
	 * is therefore pinned to the one configured in the `home` option.
	 *
	 * @return string
	 */
	public static function get_canonical_resource() {
		$url  = (string) rest_url( 'mcpwp/v1/mcp' );
		$home = function_exists( 'get_option' ) ? (string) get_option( 'home', '' ) : '';
		$home_scheme = '' !== $home ? strtolower( (string) wp_parse_url( $home, PHP_URL_SCHEME ) ) : '';

		if ( in_array( $home_scheme, array( 'http', 'https' ), true ) ) {
			$url = (string) preg_replace( '#^https?://#i', $home_scheme . '://', $url );
		}

		return $url;
	}

	/**
	 * The resource identifier PRM advertised before PR-2: origin + site path.
	 *
	 * Still accepted as a `resource` value at authorize/token (D1) so a client
	 * that discovered the old PRM keeps working.  Tokens minted for it still
	 * carry the canonical `aud`.
	 *
	 * @return string
	 */
	public static function get_legacy_resource() {
		return self::get_issuer();
	}

	/**
	 * Normalise a resource URI for comparison (RFC 8707 / RFC 3986 §6.2.2).
	 *
	 * Lower-cases scheme and host, drops a default port and a trailing slash
	 * on the path; keeps the query (plain-permalink sites address the MCP
	 * endpoint by ?rest_route=).  Anything that is not an absolute http(s)
	 * URI, or that carries a fragment or userinfo, normalises to '' — which
	 * matches nothing.
	 *
	 * @param mixed $uri Candidate URI.
	 * @return string Normalised URI, or '' when invalid.
	 */
	public static function normalize_resource( $uri ) {
		if ( ! is_string( $uri ) || '' === $uri || strlen( $uri ) > 2000 ) {
			return '';
		}
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		if ( isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || false !== strpos( $uri, '#' ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		$out = $scheme . '://' . strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$port = (int) $parts['port'];
			if ( ! ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) ) {
				$out .= ':' . $port;
			}
		}

		$out .= isset( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$out .= '?' . $parts['query'];
		}

		return $out;
	}

	/**
	 * Whether a client-supplied `resource` names this MCP endpoint (D1).
	 *
	 * Accepts the canonical MCP URL or the pre-PR-2 origin+prefix identifier;
	 * everything else is `invalid_target`.
	 *
	 * @param mixed $resource Value of the `resource` parameter.
	 * @return bool
	 */
	public static function is_acceptable_resource( $resource ) {
		$candidate = self::normalize_resource( $resource );
		if ( '' === $candidate ) {
			return false;
		}
		return $candidate === self::normalize_resource( self::get_canonical_resource() )
			|| $candidate === self::normalize_resource( self::get_legacy_resource() );
	}

	/**
	 * Whether a stored token audience is this site's canonical resource (D2).
	 *
	 * @param mixed $aud Stored `aud`.
	 * @return bool
	 */
	public static function audience_matches( $aud ) {
		$stored = self::normalize_resource( $aud );
		return '' !== $stored && $stored === self::normalize_resource( self::get_canonical_resource() );
	}

	/**
	 * Path of the RFC 9728 §3.1 path-insertion metadata URL for the MCP
	 * resource: the well-known segment spliced between the host and the MCP
	 * URL's path (query dropped).
	 *
	 * @return string Path beginning with '/'.
	 */
	public static function get_protected_resource_metadata_path() {
		$path = (string) wp_parse_url( self::get_canonical_resource(), PHP_URL_PATH );
		return '/.well-known/oauth-protected-resource' . rtrim( $path, '/' );
	}

	/**
	 * Absolute path-insertion metadata URL (advertised in WWW-Authenticate).
	 *
	 * @return string
	 */
	public static function get_protected_resource_metadata_url() {
		return self::get_site_origin() . self::get_protected_resource_metadata_path();
	}

	/**
	 * Path component of home_url(), without a trailing slash.
	 *
	 * The front channel is a front-end request, so it is routed by home_url(),
	 * not site_url() — only requests under the home base reach index.php (and
	 * therefore the do_parse_request interceptor).  Empty for a root install.
	 *
	 * @param string|null $home_url Optional home URL (pure/testable); defaults to home_url( '/' ).
	 * @return string Path prefix with a leading slash, or '' for a root install.
	 */
	public static function get_home_path_prefix( $home_url = null ) {
		if ( null === $home_url ) {
			$home_url = function_exists( 'home_url' ) ? home_url( '/' ) : '/';
		}

		$parsed = wp_parse_url( (string) $home_url );
		$path   = isset( $parsed['path'] ) ? (string) $parsed['path'] : '/';

		return rtrim( $path, '/' );
	}

	/**
	 * Build the front-channel authorize path from a home URL (pure, testable).
	 *
	 * @param string|null $home_url Site home URL (may include a subdirectory path).
	 * @return string Path beginning with '/'.
	 */
	public static function build_front_channel_authorize_path( $home_url ) {
		return self::get_home_path_prefix( $home_url ) . self::FRONT_CHANNEL_AUTHORIZE_SLUG;
	}

	/**
	 * Path of the front-channel authorization endpoint (no origin, no query).
	 *
	 * @return string Path beginning with '/', honouring subdirectory installs.
	 */
	public static function get_front_channel_authorize_path() {
		return self::build_front_channel_authorize_path( null );
	}

	/**
	 * Absolute URL of the front-channel authorization endpoint.
	 *
	 * Advertised in the RFC 8414 document, used as the consent form action and
	 * as the post-login redirect target — one builder so all three can never
	 * disagree.
	 *
	 * On a site with no permalink structure there are no rewrite rules, so a
	 * pretty path would 404 at the web server before WordPress loads; emit the
	 * query-var form instead (same degradation rest_url() already performs).
	 *
	 * @param string $origin Optional issuer origin (defaults to get_site_origin()).
	 * @return string
	 */
	public static function get_front_channel_authorize_url( $origin = '' ) {
		$origin    = '' !== $origin ? $origin : self::get_site_origin();
		$permalink = function_exists( 'get_option' ) ? (string) get_option( 'permalink_structure', '' ) : '';

		if ( '' === $permalink ) {
			return $origin . self::get_home_path_prefix() . '/?' . self::FRONT_CHANNEL_QUERY_VAR . '=authorize';
		}

		return $origin . self::get_front_channel_authorize_path();
	}
}
