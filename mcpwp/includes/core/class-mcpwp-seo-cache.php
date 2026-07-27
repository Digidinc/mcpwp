<?php
/**
 * Cache for paid SEO lookups.
 *
 * No single DataForSEO call is expensive ($0.012/request + $0.00012/result).
 * The risk is an agent loop making thousands of cheap ones, so failures are
 * cached too — a rejected credential must not be retried on every request.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient-backed lookup cache.
 */
class Mcpwp_SEO_Cache {

	const PREFIX   = 'mcpwp_seo_';
	const TTL_OK   = DAY_IN_SECONDS;
	const TTL_FAIL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Return a cached value, or fetch and cache it.
	 *
	 * Errors are cached under a shorter TTL and returned as errors, so a
	 * failure never degrades into an empty-looking success.
	 *
	 * @param string   $key   Cache key, e.g. "seed|en|2840".
	 * @param callable $fetch Producer returning array|WP_Error.
	 * @return array|WP_Error
	 */
	public static function remember( $key, $fetch ) {
		$transient_key = self::PREFIX . md5( (string) $key );

		$cached = get_transient( $transient_key );
		if ( is_array( $cached ) && isset( $cached['is_error'] ) ) {
			return $cached['is_error']
				? new WP_Error( $cached['code'], $cached['message'] )
				: $cached['data'];
		}

		$result = call_user_func( $fetch );

		if ( is_wp_error( $result ) ) {
			set_transient(
				$transient_key,
				array(
					'is_error' => true,
					'code'     => $result->get_error_code(),
					'message'  => $result->get_error_message(),
				),
				self::TTL_FAIL
			);

			return $result;
		}

		set_transient(
			$transient_key,
			array(
				'is_error' => false,
				'data'     => $result,
			),
			self::TTL_OK
		);

		return $result;
	}
}
