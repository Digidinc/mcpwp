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
 * The oauth-authorization-server document remains gated on the legacy
 * `oauth_enabled` setting alone.  The oauth-protected-resource document is
 * gated on `oauth_enabled` OR mumcp.com broker enrollment
 * (`mcpwp_broker_enrollment` option, non-empty with a `site_id`) — broker
 * enrollment independently enables protected-resource discovery, and when
 * enrolled its `authorization_servers` points at `https://mumcp.com` instead
 * of this site's own authorization-server document (the broker is the AS,
 * not the site). When neither is active the filter returns true (WordPress
 * processes the request normally, resulting in a 404 from the theme or
 * rewrite fallback).
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
	 * Scopes the mumcp.com broker will grant (mumcp-broker ALLOWED_SCOPES).
	 *
	 * The broker rejects any other value with invalid_scope, and MCP clients
	 * request every scope advertised in scopes_supported, so a broker-enrolled
	 * site must never advertise more than this list.
	 */
	const BROKER_SCOPES = array( 'read', 'write' );

	/**
	 * Scopes this site's own self-issued OAuth server can grant: the broker set
	 * plus 'admin' (clamped to the logged-in user's capability at issuance).
	 */
	const SELF_AS_SCOPES = array( 'read', 'write', 'admin' );

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

		if ( ! self::is_legacy_oauth_enabled() && ! self::is_broker_enrolled() ) {
			return $response;
		}

		$resource_metadata_url = self::get_site_origin() . '/.well-known/oauth-protected-resource' . self::get_site_path_prefix();
		$response->header( 'WWW-Authenticate', 'Bearer resource_metadata="' . $resource_metadata_url . '"' );

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

		$prefix = self::get_site_path_prefix();

		$protected_resource_paths = array(
			'/.well-known/oauth-protected-resource' . $prefix,
			$prefix . '/.well-known/oauth-protected-resource',
		);
		if ( in_array( $path_only, $protected_resource_paths, true ) ) {
			// Broker enrollment independently enables this document, regardless
			// of the legacy `oauth_enabled` setting — see should_serve_protected_resource_metadata().
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
			// Unchanged: this document only ever describes this site's own
			// self-issued AS, so it stays gated on the legacy setting alone.
			if ( ! self::is_legacy_oauth_enabled() ) {
				return $do_parse;
			}
			self::serve_authorization_server_metadata();
			exit;
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
	 * When this site is enrolled with the mumcp.com broker
	 * (`mcpwp_broker_enrollment` option, non-empty with a `site_id` —
	 * see is_broker_enrolled()), `authorization_servers` points at mumcp.com:
	 * the broker is the authorization server, not this site. Otherwise the
	 * site remains its own AS, exactly as before broker support existed.
	 *
	 * @return array{resource:string,authorization_servers:string[],scopes_supported:string[],bearer_methods_supported:string[]}
	 */
	public static function build_protected_resource_metadata() {
		$origin   = self::get_site_origin();
		$prefix   = self::get_site_path_prefix();
		$resource = $origin . $prefix;

		if ( self::is_broker_enrolled() ) {
			$authorization_servers = array( 'https://mumcp.com' );
			$scopes_supported      = self::BROKER_SCOPES;
		} else {
			$authorization_servers = array( $origin . '/.well-known/oauth-authorization-server' . $prefix );
			$scopes_supported      = self::SELF_AS_SCOPES;
		}

		return array(
			'resource'                 => $resource,
			'authorization_servers'    => $authorization_servers,
			'scopes_supported'         => $scopes_supported,
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
	 * Whether the legacy self-issued OAuth AS is enabled.
	 *
	 * @return bool
	 */
	public static function is_legacy_oauth_enabled() {
		$settings = get_option( 'mcpwp_settings', array() );
		return ! empty( $settings['oauth_enabled'] );
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
			'issuer'                                => $origin . self::get_site_path_prefix(),
			'authorization_endpoint'                => self::get_front_channel_authorize_url( $origin ),
			'token_endpoint'                        => $rest_base . '/oauth/token',
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post' ),
			'scopes_supported'                      => self::SELF_AS_SCOPES,
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
