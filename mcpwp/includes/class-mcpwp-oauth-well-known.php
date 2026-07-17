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
 *
 * WordPress's REST API is mounted at /wp-json, so these root-level paths are
 * served via a `do_parse_request` filter (priority 1) which intercepts the
 * request before WordPress's own rewrite engine processes it.  When the path
 * matches, we emit JSON + exit immediately.
 *
 * Both endpoints are gated on the `oauth_enabled` setting.  When OAuth is
 * disabled the filter returns true (WordPress processes the request normally,
 * resulting in a 404 from the theme or rewrite fallback).
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
	 * Only fires when OAuth is enabled; leaves existing X-API-Key auth path
	 * completely unaffected (that path still returns 401, just without the
	 * discovery header).
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

		$settings = get_option( 'mcpwp_settings', array() );
		if ( empty( $settings['oauth_enabled'] ) ) {
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
		$settings = get_option( 'mcpwp_settings', array() );
		if ( empty( $settings['oauth_enabled'] ) ) {
			return $do_parse;
		}

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
			self::serve_protected_resource_metadata();
			exit;
		}

		$authorization_server_paths = array(
			'/.well-known/oauth-authorization-server' . $prefix,
			$prefix . '/.well-known/oauth-authorization-server',
		);
		if ( in_array( $path_only, $authorization_server_paths, true ) ) {
			self::serve_authorization_server_metadata();
			exit;
		}

		return $do_parse;
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
		$origin = self::get_site_origin();
		$prefix = self::get_site_path_prefix();

		$body = array(
			'resource'              => $origin . $prefix,
			'authorization_servers' => array( $origin . '/.well-known/oauth-authorization-server' . $prefix ),
			'scopes_supported'      => array( 'read', 'write', 'admin' ),
			'bearer_methods_supported' => array( 'header' ),
		);

		self::emit_json( $body );
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
		$origin           = self::get_site_origin();
		$rest_base        = rest_url( 'mcpwp/v1' );

		$body = array(
			'issuer'                                 => $origin . self::get_site_path_prefix(),
			'authorization_endpoint'                 => $rest_base . '/oauth/authorize',
			'token_endpoint'                         => $rest_base . '/oauth/token',
			'response_types_supported'               => array( 'code' ),
			'grant_types_supported'                  => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'       => array( 'S256' ),
			'token_endpoint_auth_methods_supported'  => array( 'none', 'client_secret_post' ),
			'scopes_supported'                       => array( 'read', 'write', 'admin' ),
		);

		self::emit_json( $body );
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
}
