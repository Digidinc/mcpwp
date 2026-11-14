<?php
/**
 * OAuth transport rule: bearer access tokens only over HTTPS.
 *
 * An OAuth access token (mcpwp_at_ / spai_at_) that reaches WordPress over
 * plain HTTP is refused (RFC 6750 §5.3, OAuth 2.1 §1.5), and so are the
 * consent screen and this site's own OAuth discovery documents, so a
 * connection cannot be started over HTTP either. A refused token is revoked
 * together with its refresh family: it travelled unencrypted, and whoever
 * holds it could already use it.
 *
 * The refusal is enforced, never warn-only. Because MCP clients often do not
 * show the error text, refusals are also counted (no token data) and shown
 * to an administrator in Site Health and in a notice on the MCPWP screens.
 *
 * API keys, the mumcp.com broker bearer and the WebMCP / admin-AJAX
 * principals are not covered by this class and are unchanged.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mcpwp-proxy-trust.php';

class Mcpwp_OAuth_Transport {

	/** Transient holding the refusal record. Never holds token data. */
	const REFUSALS_TRANSIENT = 'mcpwp_oauth_insecure_refusals';

	/** At most one write of the record per this many seconds. */
	const RECORD_INTERVAL = 60;

	/** How long a refusal stays reported (seconds; a week). */
	const RECORD_TTL = 604800;

	/**
	 * Register the Site Health test and the admin notice.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_site_status_test' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	// ── pure decisions ───────────────────────────────────────────────────

	/**
	 * Whether a request arrived over TLS, the way WordPress decides it.
	 *
	 * is_ssl() is core's answer: $_SERVER['HTTPS'] is "on" or "1", or, when
	 * HTTPS is not set at all, SERVER_PORT is 443. (That port fallback is
	 * core's own quirk: a server listening for plain HTTP on 443 would pass.)
	 * A site behind a TLS-terminating proxy normally makes is_ssl() true in
	 * wp-config.php. As a second route, and only behind the explicit
	 * MCPWP_TRUST_PROXY_HEADERS opt-in, X-Forwarded-Proto counts — and only
	 * when EVERY hop in it says https, so a client-supplied "https" in front
	 * of a proxy-appended "http" is not enough.
	 *
	 * @param bool   $is_ssl          Result of is_ssl().
	 * @param bool   $trust_proxy     Whether MCPWP_TRUST_PROXY_HEADERS is true.
	 * @param string $forwarded_proto Raw X-Forwarded-Proto value, or ''.
	 * @return bool
	 */
	public static function request_is_secure( $is_ssl, $trust_proxy, $forwarded_proto ) {
		if ( $is_ssl ) {
			return true;
		}
		if ( ! $trust_proxy || '' === trim( (string) $forwarded_proto ) ) {
			return false;
		}
		foreach ( explode( ',', (string) $forwarded_proto ) as $hop ) {
			if ( 'https' !== strtolower( trim( $hop ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether OAuth may be used over plain HTTP anyway (local development).
	 *
	 *  - MCPWP_ALLOW_INSECURE_OAUTH is defined as true (exactly true);
	 *  - wp_get_environment_type() is 'local' (not 'development' or
	 *    'staging': those are often reachable from the internet);
	 *  - the configured site address (the `home` option) AND the request's
	 *    Host are both loopback. The configured address is not something an
	 *    outside caller can change, and the Host check keeps a
	 *    localhost-configured site reached over a LAN or a published port
	 *    from accepting tokens sent to it by another name. REMOTE_ADDR is
	 *    deliberately not used: behind a proxy on the same machine every
	 *    request comes from 127.0.0.1.
	 *
	 * @param bool   $allow_constant Whether MCPWP_ALLOW_INSECURE_OAUTH is true.
	 * @param string $environment    wp_get_environment_type().
	 * @param string $home_url       The `home` option.
	 * @param string $request_host   The request's Host header, or ''.
	 * @return bool
	 */
	public static function insecure_transport_allowed( $allow_constant, $environment, $home_url, $request_host ) {
		if ( $allow_constant ) {
			return true;
		}
		if ( 'local' === (string) $environment ) {
			return true;
		}
		$home_host = '' !== (string) $home_url ? (string) wp_parse_url( (string) $home_url, PHP_URL_HOST ) : '';
		return self::is_loopback_host( $home_host ) && self::is_loopback_host( $request_host );
	}

	/**
	 * Whether a host (optionally with a port, IPv6 optionally bracketed) names
	 * the local machine: localhost, a *.localhost name (RFC 6761 §6.3),
	 * 127.0.0.1 or ::1. Anything else, including '', is not loopback.
	 *
	 * @param string $host Host, as in a URL or a Host header.
	 * @return bool
	 */
	public static function is_loopback_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		if ( '' === $host ) {
			return false;
		}
		if ( '[' === $host[0] ) {
			$end  = strpos( $host, ']' );
			$host = false === $end ? '' : substr( $host, 1, $end - 1 );
		} elseif ( 1 === substr_count( $host, ':' ) ) {
			// host:port. A bare IPv6 literal has two or more colons.
			$host = substr( $host, 0, strpos( $host, ':' ) );
		}
		$host = rtrim( $host, '.' );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		return strlen( $host ) > strlen( '.localhost' ) && '.localhost' === substr( $host, -strlen( '.localhost' ) );
	}

	/**
	 * Whether the site's configured address is https.
	 *
	 * @param string $home_url The `home` option.
	 * @return bool
	 */
	public static function home_is_https( $home_url ) {
		return 'https' === strtolower( (string) wp_parse_url( (string) $home_url, PHP_URL_SCHEME ) );
	}

	/**
	 * Whether a refused request carried the marks of a TLS-terminating proxy
	 * that does not tell WordPress (Cloudflare "Flexible", nginx, Traefik,
	 * Caddy, Coolify): the site's address is https AND the request carries a
	 * header such proxies add. The site address alone is not enough: anyone
	 * can send a plain-HTTP request to an https site.
	 *
	 * @param string $home_url       The `home` option.
	 * @param bool   $proxy_headers  Whether proxy headers were present.
	 * @return bool
	 */
	public static function proxy_suspected( $home_url, $proxy_headers ) {
		return $proxy_headers && self::home_is_https( $home_url );
	}

	/**
	 * Whether this request carries a header a reverse proxy adds. A hint
	 * for the wording only; it never decides whether a request is secure.
	 *
	 * @return bool
	 */
	public static function proxy_headers_present() {
		foreach ( array( 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_CF_VISITOR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * proxy_suspected() for the current request.
	 *
	 * @return bool
	 */
	public static function request_proxy_suspected() {
		return self::proxy_suspected( self::home(), self::proxy_headers_present() );
	}

	// ── this request ─────────────────────────────────────────────────────

	/**
	 * Whether MCPWP_TRUST_PROXY_HEADERS is on. Exactly true: a string such
	 * as "false" or a 1 does not count.
	 *
	 * @return bool
	 */
	public static function trust_proxy_headers() {
		return Mcpwp_Proxy_Trust::headers_trusted();
	}

	/**
	 * @return string The `home` option, or ''.
	 */
	private static function home() {
		return function_exists( 'get_option' ) ? (string) get_option( 'home', '' ) : '';
	}

	/**
	 * Whether the current request would be refused for OAuth.
	 *
	 * Reads only the request's transport facts and site configuration, never
	 * a token, so it is the same answer for every token.
	 *
	 * @return bool
	 */
	public static function is_refused() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalised by the pure helpers.
		$forwarded_proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) : '';
		$request_host    = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( self::request_is_secure( function_exists( 'is_ssl' ) && is_ssl(), self::trust_proxy_headers(), $forwarded_proto ) ) {
			return false;
		}

		return ! self::insecure_transport_allowed(
			defined( 'MCPWP_ALLOW_INSECURE_OAUTH' ) && true === constant( 'MCPWP_ALLOW_INSECURE_OAUTH' ),
			function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production',
			self::home(),
			$request_host
		);
	}

	// ── the refusal ──────────────────────────────────────────────────────

	/**
	 * The one answer for an OAuth access token sent over plain HTTP.
	 *
	 * 403, not 401: a 401 carries the WWW-Authenticate challenge, and the
	 * client would sign in again and send the fresh token the same way. The
	 * text depends only on the request's transport and the site's address
	 * (proxy suspected or not), never on the token, so it is identical for
	 * every token and every entry path.
	 *
	 * @return WP_Error
	 */
	public static function refusal_error() {
		$proxy = self::request_proxy_suspected();
		return new WP_Error(
			'https_required',
			self::message( $proxy ),
			array(
				'status' => 403,
				'hint'   => self::hint( $proxy ),
			)
		);
	}

	/**
	 * @param bool $proxy Whether a TLS-terminating proxy is suspected.
	 * @return string
	 */
	public static function message( $proxy ) {
		if ( $proxy ) {
			return __( 'OAuth access tokens are accepted only over HTTPS. This site\'s address is https, but the request reached WordPress through a proxy over plain HTTP: the proxy terminates TLS without telling WordPress.', 'mcpwp' );
		}
		return __( 'OAuth access tokens are accepted only over HTTPS. This request arrived over plain HTTP and was refused.', 'mcpwp' );
	}

	/**
	 * The hint. Client-facing, so it names only what a site owner should do
	 * on a real site; the local-development exceptions are in the docs.
	 *
	 * @param bool $proxy Whether a TLS-terminating proxy is suspected.
	 * @return string
	 */
	public static function hint( $proxy ) {
		$revoked = 'Any OAuth token sent over plain HTTP is revoked on arrival, with its refresh token; reconnect once the site is fixed. API keys are not affected.';
		if ( $proxy ) {
			return 'Use the site\'s https:// MCP URL. If the app already does, site owner: configure the proxy or host so WordPress is told the request was HTTPS ($_SERVER[\'HTTPS\'] = \'on\'). Only if the server can be reached solely through that proxy, wp-config.php may set it from the proxy\'s header: if ( isset( $_SERVER[\'HTTP_X_FORWARDED_PROTO\'] ) && \'https\' === $_SERVER[\'HTTP_X_FORWARDED_PROTO\'] ) { $_SERVER[\'HTTPS\'] = \'on\'; } If the server is also reachable directly, do not: anyone could then send that header and fake HTTPS. MCPWP_TRUST_PROXY_HEADERS has the same requirement and also makes MCPWP trust client-IP headers (X-Forwarded-For, CF-Connecting-IP) for rate limits and logs. ' . $revoked;
		}
		return 'Use the site\'s https:// MCP URL. Site owner: serve the site over HTTPS; behind a TLS-terminating proxy, configure the proxy or host so WordPress is told the request was HTTPS ($_SERVER[\'HTTPS\'] = \'on\'). ' . $revoked;
	}

	/**
	 * Revoke a token presented over a refused transport, with its refresh
	 * family. It travelled unencrypted; whoever holds it can already use it,
	 * so no client authentication is needed, and it takes the full token
	 * string, so it can never be aimed at a token the caller does not hold.
	 *
	 * Writes only when there is something live to kill: a token that was
	 * never issued, or whose row and family are already revoked, writes
	 * nothing. Charges no rate-limit bucket. The caller's answer must not
	 * depend on the result; it only decides whether the refusal is recorded
	 * for an administrator (a real, live token was exposed).
	 *
	 * @param string $token The presented access token.
	 * @return bool True when a live token or a live refresh family was revoked.
	 */
	public static function revoke_presented_token( $token ) {
		$token = (string) $token;
		if ( '' === $token || ! class_exists( 'Mcpwp_OAuth_Token_Store' ) ) {
			return false;
		}

		$row = Mcpwp_OAuth_Token_Store::find( $token );
		if ( is_array( $row ) ) {
			if ( Mcpwp_OAuth_Token_Store::TYPE_ACCESS !== $row['type'] ) {
				return false;
			}
			$killed = false;
			if ( Mcpwp_OAuth_Token_Store::is_active( $row, Mcpwp_OAuth_Token_Store::TYPE_ACCESS ) ) {
				Mcpwp_OAuth_Token_Store::revoke( $token );
				$killed = true;
			} elseif ( null === $row['revoked_at'] || '' === $row['revoked_at'] ) {
				// Expired but never marked: mark it, but it was not live.
				Mcpwp_OAuth_Token_Store::revoke( $token );
			}
			$family = isset( $row['family_id'] ) ? (string) $row['family_id'] : '';
			if ( '' !== $family && class_exists( 'Mcpwp_OAuth_Revocation' ) && ! Mcpwp_OAuth_Revocation::family_revoked( $family ) ) {
				Mcpwp_OAuth_Revocation::revoke_family( $family );
				$killed = true;
			}
			return $killed;
		}

		// A token from before the token table, still in its old transient.
		$legacy_keys = array( 'mcpwp_oauth_token_' . md5( $token ) );
		if ( 0 === strpos( $token, 'spai_at_' ) ) {
			$legacy_keys[] = 'spai_oauth_token_' . md5( $token );
		}
		$killed = false;
		foreach ( $legacy_keys as $key ) {
			if ( false !== get_transient( $key ) ) {
				delete_transient( $key );
				$killed = true;
			}
		}
		return $killed;
	}

	// ── what an administrator sees ───────────────────────────────────────

	/**
	 * Note, for Site Health and the admin notice, that a real live token was
	 * refused over plain HTTP. Called only after revoke_presented_token()
	 * actually revoked something, so it takes a genuinely issued token to
	 * write it: an anonymous request with a made-up token, the consent
	 * screen or the discovery documents never do. No token data: when, and
	 * whether proxy headers came with it. Written at most once per
	 * RECORD_INTERVAL.
	 *
	 * @return void
	 */
	public static function record_refusal() {
		$now    = time();
		$record = self::get_record();
		if ( is_array( $record ) && isset( $record['last_at'] ) && ( $now - (int) $record['last_at'] ) < self::RECORD_INTERVAL ) {
			return;
		}
		set_transient(
			self::REFUSALS_TRANSIENT,
			array(
				'first_at'        => is_array( $record ) && isset( $record['first_at'] ) ? (int) $record['first_at'] : $now,
				'last_at'         => $now,
				'proxy_suspected' => self::request_proxy_suspected(),
			),
			self::RECORD_TTL
		);
	}

	/**
	 * @return array|false The refusal record, or false when there is none.
	 */
	public static function get_record() {
		$record = get_transient( self::REFUSALS_TRANSIENT );
		return is_array( $record ) ? $record : false;
	}

	/**
	 * Site Health / notice status.
	 *
	 * 'critical' when a TLS-terminating proxy is suspected (every OAuth
	 * connection on the real https address is refused): the administrator's
	 * own request right now arrives over plain HTTP although the site's
	 * address is https, or a recorded refusal of a real token carried proxy
	 * headers. 'recommended' when a real token was refused otherwise; else
	 * 'good'. Nothing an anonymous caller can send reaches 'critical'.
	 *
	 * @return array{status:string,proxy:bool,record:array|false}
	 */
	public static function get_status() {
		$record    = self::get_record();
		$proxy_now = self::is_refused() && self::home_is_https( self::home() );
		$proxy     = $proxy_now || ( is_array( $record ) && ! empty( $record['proxy_suspected'] ) );
		if ( $proxy ) {
			$status = 'critical';
		} elseif ( is_array( $record ) ) {
			$status = 'recommended';
		} else {
			$status = 'good';
		}
		return array(
			'status' => $status,
			'proxy'  => $proxy,
			'record' => $record,
		);
	}

	/**
	 * @param array $tests Registered tests.
	 * @return array
	 */
	public static function register_site_status_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		$tests['direct']['mcpwp_oauth_transport'] = array(
			'label' => __( 'MCPWP OAuth connections arrive over HTTPS', 'mcpwp' ),
			'test'  => array( __CLASS__, 'run_site_status_test' ),
		);
		return $tests;
	}

	/**
	 * @return array Site Health result.
	 */
	public static function run_site_status_test() {
		$status = self::get_status();
		$result = array(
			'label'       => __( 'MCPWP OAuth connections arrive over HTTPS', 'mcpwp' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'mcpwp' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'No OAuth request has been refused for arriving over plain HTTP.', 'mcpwp' ) . '</p>',
			'actions'     => '',
			'test'        => 'mcpwp_oauth_transport',
		);
		if ( 'good' === $status['status'] ) {
			return $result;
		}
		$result['status']         = $status['status'];
		$result['label']          = $status['proxy']
			? __( 'MCPWP is refusing every OAuth connection: your HTTPS proxy does not tell WordPress', 'mcpwp' )
			: __( 'MCPWP refused OAuth requests that arrived over plain HTTP', 'mcpwp' );
		$result['badge']['color'] = 'critical' === $status['status'] ? 'red' : 'orange';
		$result['description']    = '<p>' . esc_html( self::admin_text( $status ) ) . '</p><p>' . esc_html( self::hint( $status['proxy'] ) ) . '</p>';
		return $result;
	}

	/**
	 * @param array $status get_status().
	 * @return string
	 */
	private static function admin_text( array $status ) {
		$when = '';
		if ( is_array( $status['record'] ) && ! empty( $status['record']['last_at'] ) ) {
			$when = ' ' . sprintf(
				/* translators: %s: date and time */
				__( 'Last refusal: %s (UTC).', 'mcpwp' ),
				gmdate( 'Y-m-d H:i', (int) $status['record']['last_at'] )
			);
		}
		if ( $status['proxy'] ) {
			return __( 'This site\'s address is https, but requests reach WordPress as plain HTTP, so AI apps connected with OAuth (claude.ai, ChatGPT) are refused, and those apps usually do not show why.', 'mcpwp' ) . $when;
		}
		return __( 'OAuth requests reached this site over plain HTTP and were refused. The AI app is probably set up with the http:// address.', 'mcpwp' ) . $when;
	}

	/**
	 * Admin notice on the MCPWP screens (and Plugins) while refusals are
	 * being reported.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$slug   = ( $screen && isset( $screen->id ) ) ? (string) $screen->id : '';
		if ( 'plugins' !== $slug && false === strpos( $slug, 'mcpwp' ) ) {
			return;
		}
		$status = self::get_status();
		if ( 'good' === $status['status'] ) {
			return;
		}
		printf(
			'<div class="notice %s"><p><strong>%s</strong> %s</p><p>%s</p></div>',
			'critical' === $status['status'] ? 'notice-error' : 'notice-warning',
			esc_html__( 'MCPWP is refusing OAuth over plain HTTP.', 'mcpwp' ),
			esc_html( self::admin_text( $status ) ),
			esc_html( self::hint( $status['proxy'] ) )
		);
	}
}
