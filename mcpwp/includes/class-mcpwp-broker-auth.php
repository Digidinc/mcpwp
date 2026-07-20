<?php
/**
 * mumcp.com broker — cached introspection validator.
 *
 * Validates broker-issued opaque Bearer tokens against mumcp.com's
 * `POST /oauth/introspect` endpoint (RFC 7662-shaped), with a short-lived
 * local cache and strictly fail-closed error handling. This is the plugin's
 * core token-validation security surface for the broker auth path — a
 * future Bearer branch in Mcpwp_Api_Auth::verify_api_key() (Plan 2 / Task 4)
 * is the sole caller.
 *
 * Security properties:
 *  - Kill-switch: if the site is not enrolled with the broker
 *    (`mcpwp_broker_enrollment` option), validate_token() rejects instantly
 *    with NO network call. A disabled/never-enrolled site can never be
 *    tricked into calling out to mumcp.com.
 *  - Fail-closed: any transport error, non-200 response, malformed body, or
 *    `active !== true` is treated as invalid — never as "unknown, allow."
 *  - Positive-only cache: only an active, audience-and-site-matched result
 *    is cached, bounded to at most 60 seconds and never longer than the
 *    token's own remaining lifetime (`exp`). Negative results are never
 *    cached, so a revoked/expired token can't get a stale local pass.
 *  - Audience + site_id enforcement: an otherwise-active token that was
 *    introspected successfully but issued for a different site or audience
 *    is rejected with 403, not silently accepted.
 *  - scope_to_capability() never returns 'admin' — the broker's contract
 *    only ever issues read/write scopes, but this is defense-in-depth in
 *    case a future/compromised broker response ever includes it.
 *
 * @package MCPWP
 * @since   3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cached broker-token introspection validator.
 */
class Mcpwp_Broker_Auth {

	/**
	 * Transient key prefix for a cached positive introspection result.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'mcpwp_bt_';

	/**
	 * Upper bound (seconds) on how long a positive result may be cached,
	 * regardless of how far in the future the token's own `exp` is.
	 *
	 * @var int
	 */
	const MAX_CACHE_TTL = 60;

	/**
	 * HTTP timeout (seconds) for the introspection call.
	 *
	 * @var int
	 */
	const HTTP_TIMEOUT = 5;

	/**
	 * Whether this site is enrolled with the mumcp.com broker.
	 *
	 * True iff `mcpwp_broker_enrollment` is a non-empty array carrying a
	 * `site_id`, a `site_credential`, and an `audience`. This is the
	 * kill-switch: validate_token() must check this FIRST, before any
	 * network call, so a disabled/unenrolled site never reaches out.
	 *
	 * @return bool
	 */
	public function is_enrolled() {
		$enrollment = get_option( 'mcpwp_broker_enrollment', array() );

		return is_array( $enrollment )
			&& ! empty( $enrollment['site_id'] )
			&& ! empty( $enrollment['site_credential'] )
			&& ! empty( $enrollment['audience'] );
	}

	/**
	 * Validate an opaque broker Bearer token.
	 *
	 * @param string $token Opaque bearer token as received in the Authorization header.
	 * @return array{scope:string[],sub:mixed,site_id:string,aud:string,exp:int}|WP_Error
	 *         Normalized introspection result on success, WP_Error (fail-closed) otherwise.
	 */
	public function validate_token( $token ) {
		// 1. Kill-switch — no network call unless actively enrolled.
		if ( ! $this->is_enrolled() ) {
			return new WP_Error(
				'broker_not_enrolled',
				__( 'This site is not enrolled with the mumcp.com broker.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		$enrollment = get_option( 'mcpwp_broker_enrollment', array() );

		// 2. Cache — serve a prior positive result within its TTL window.
		// The key binds the token to THIS enrollment's site_id + audience (not
		// just the token), so a re-enrollment (new site_id/audience — e.g.
		// disconnect + reconnect, or the broker re-issuing under a different
		// audience) can never serve a cached positive minted under the old
		// enrollment; the cache key itself changes and the old entry is simply
		// orphaned (it will expire on its own within MAX_CACHE_TTL regardless).
		$cache_key = self::CACHE_PREFIX . hash( 'sha256', $token . '|' . $enrollment['site_id'] . '|' . $enrollment['audience'] );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// 3. Cache miss — introspect against the broker.
		$credential = Mcpwp_Encryption::get_instance()->decrypt( $enrollment['site_credential'] );
		if ( false === $credential ) {
			// Encryption key/credential unreadable — fail closed, no network call
			// with a broken/garbage credential.
			return new WP_Error(
				'broker_token_invalid',
				__( 'Unable to validate the broker credential.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		$endpoint = isset( $enrollment['introspection_endpoint'] )
			? $enrollment['introspection_endpoint']
			: 'https://mumcp.com/oauth/introspect';

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $enrollment['site_id'] . ':' . $credential ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => 'token=' . rawurlencode( (string) $token ),
				'timeout' => self::HTTP_TIMEOUT,
			)
		);

		// 4. Fail-closed — transport error, non-200, unparseable body, or
		// active !== true. Never cache a negative result.
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'broker_token_invalid',
				__( 'Broker introspection request failed.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error(
				'broker_token_invalid',
				__( 'Broker introspection returned an unexpected status.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['active'] ) || true !== $data['active'] ) {
			return new WP_Error(
				'broker_token_invalid',
				__( 'Broker token is not active.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		// 5. Audience + site_id enforcement — an otherwise-active token that
		// wasn't issued for THIS site's audience/site_id is not ours to accept.
		$aud_matches     = isset( $data['aud'] ) && $data['aud'] === $enrollment['audience'];
		$site_id_matches = isset( $data['site_id'] ) && $data['site_id'] === $enrollment['site_id'];
		if ( ! $aud_matches || ! $site_id_matches ) {
			return new WP_Error(
				'broker_audience_mismatch',
				__( 'Broker token was not issued for this site.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}

		$result = array(
			'scope'   => isset( $data['scope'] ) ? (array) $data['scope'] : array(),
			'sub'     => isset( $data['sub'] ) ? $data['sub'] : null,
			'site_id' => $data['site_id'],
			'aud'     => $data['aud'],
			'exp'     => isset( $data['exp'] ) ? (int) $data['exp'] : ( time() + self::MAX_CACHE_TTL ),
		);

		// 6. Cache the positive, normalized result only. TTL is bounded to
		// [1, MAX_CACHE_TTL] seconds so a token expiring sooner than 60s
		// isn't cached as valid past its real expiry, and a bad/negative
		// $exp never produces an invalid (<=0) transient expiration.
		$ttl = max( 1, min( self::MAX_CACHE_TTL, $result['exp'] - time() ) );
		set_transient( $cache_key, $result, $ttl );

		return $result;
	}

	/**
	 * Map a broker scope list to the plugin's read/write capability model.
	 *
	 * Never returns 'admin' — the broker's contract only ever issues
	 * read/write scopes, but this is a defense-in-depth backstop: even if
	 * 'admin' somehow appeared in a scope array, it is mapped down to 'read'
	 * rather than trusted.
	 *
	 * @param array $scope Scope list from a validated introspection result.
	 * @return string 'write' or 'read'.
	 */
	public function scope_to_capability( array $scope ) {
		return in_array( 'write', $scope, true ) ? 'write' : 'read';
	}
}
