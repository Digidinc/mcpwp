<?php
/**
 * Rate Limiter
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles API rate limiting using transients.
 */
class Mcpwp_Rate_Limiter {

	/**
	 * Singleton instance.
	 *
	 * @var Mcpwp_Rate_Limiter
	 */
	private static $instance = null;

	/**
	 * Rate limit settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Current request count data.
	 *
	 * @var array
	 */
	private $current_data;

	/**
	 * Bucket reported to the caller when it differs from the one metered.
	 *
	 * @var array
	 */
	private $reported_data = array();

	/**
	 * Get singleton instance.
	 *
	 * @return Mcpwp_Rate_Limiter
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_settings();
	}

	/**
	 * Load rate limit settings.
	 */
	private function load_settings() {
		$defaults = array(
			'enabled'            => true,
			'requests_per_minute' => 60,
			'requests_per_hour'   => 1000,
			'burst_limit'         => 30,
			'whitelist'           => array(),
		);

		$saved = get_option( 'mcpwp_rate_limit_settings', array() );
		$this->settings = wp_parse_args( $saved, $defaults );
		$this->settings['enabled'] = (bool) $this->settings['enabled'];
		$this->settings['requests_per_minute'] = max( 1, min( 100000, (int) $this->settings['requests_per_minute'] ) );
		$this->settings['requests_per_hour'] = max( 1, min( 100000, (int) $this->settings['requests_per_hour'] ) );
		$this->settings['burst_limit'] = max( 1, min( 100000, (int) $this->settings['burst_limit'] ) );
		$this->settings['whitelist'] = $this->sanitize_whitelist( $this->settings['whitelist'] );
		if ( $this->settings['burst_limit'] > $this->settings['requests_per_minute'] ) {
			$this->settings['burst_limit'] = $this->settings['requests_per_minute'];
		}
	}

	/**
	 * Check if rate limiting is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) $this->settings['enabled'];
	}

	/**
	 * Check rate limit for current request.
	 *
	 * @param string     $identifier Unique identifier (IP or API key).
	 * @param string     $method     HTTP method (GET requests get 2x burst/minute limits).
	 * @param array|null $key_record Optional scoped API key record with per-key rate_limits overrides.
	 * @return bool|WP_Error True if allowed, WP_Error if rate limited.
	 */
	public function check_limit( $identifier = null, $method = 'POST', $key_record = null, $weight = 1 ) {
		// A batch is charged one slot per operation (#802). Refused whole when
		// it does not fit — running part of a batch and then 429ing is the
		// mid-write corruption that issue opens with.
		$weight = max( 1, (int) $weight );
		$bucket = ':diag' === substr( (string) $identifier, -5 ) ? 'diagnostic' : 'write';
		$this->reported_data = array();

		if ( ! $this->is_enabled() ) {
			return true;
		}

		if ( null === $identifier ) {
			$identifier = $this->get_client_identifier();
		}

		// Check whitelist. A key whitelisted as key:<id> is whitelisted for its
		// diagnostics too — the ':diag' suffix is a bucket, not an identity.
		if ( $this->is_whitelisted( preg_replace( '/:diag$/', '', (string) $identifier ) ) ) {
			return true;
		}

		// Apply per-key rate limit overrides if present.
		$effective_limits = $this->get_effective_limits( $key_record );

		// GET/HEAD/OPTIONS requests get 2x burst and minute limits (reads are non-destructive).
		$is_read      = in_array( strtoupper( $method ), array( 'GET', 'HEAD', 'OPTIONS' ), true );
		$burst_limit  = $is_read ? $effective_limits['burst_limit'] * 2 : $effective_limits['burst_limit'];
		$minute_limit = $is_read ? $effective_limits['requests_per_minute'] * 2 : $effective_limits['requests_per_minute'];
		$hour_limit   = $effective_limits['requests_per_hour'];

		// A request heavier than a whole window can never fit, at any time.
		// Saying "try again in N seconds" to it is a loop; say what it is.
		$ceiling = min( $burst_limit, $minute_limit, $hour_limit );
		if ( $weight > $ceiling ) {
			return new WP_Error(
				'batch_exceeds_limit',
				sprintf(
					/* translators: 1: operations requested 2: the smallest window ceiling */
					__( 'This request carries %1$d operations but the rate limit allows at most %2$d per window. Waiting will not help; split it.', 'mcpwp' ),
					$weight,
					$ceiling
				),
				array(
					'status'    => 413,
					'requested' => $weight,
					'limit'     => $ceiling,
					'bucket'    => $bucket,
					'hint'      => 'Send fewer operations per call (at most the smallest of burst_limit, requests_per_minute and requests_per_hour), or raise those limits for this key with wp_update_rate_limit / a key rate_limits override.',
				)
			);
		}

		$cache_key_minute = 'mcpwp_rate_' . md5( $identifier . '_minute' );
		$cache_key_hour   = 'mcpwp_rate_' . md5( $identifier . '_hour' );
		$cache_key_burst  = 'mcpwp_rate_' . md5( $identifier . '_burst' );

		// Get current counts.
		$minute_data = get_transient( $cache_key_minute );
		$hour_data   = get_transient( $cache_key_hour );
		$burst_data  = get_transient( $cache_key_burst );

		$now = time();
		$burst_window = $this->get_burst_window();

		$minute_data = $this->initialize_window_data( $minute_data, 60, $now );
		$hour_data   = $this->initialize_window_data( $hour_data, 3600, $now );
		$burst_data  = $this->initialize_window_data( $burst_data, $burst_window, $now );

		// Check short burst limit first.
		if ( $burst_data['count'] + $weight > $burst_limit ) {
			$retry_after = max( 0, $burst_data['reset'] - $now );

			$this->current_data = array(
				'limit'       => $burst_limit,
				'remaining'   => max( 0, $burst_limit - $burst_data['count'] ),
				'reset'       => $burst_data['reset'],
				'window'      => 'burst',
				'bucket'      => $bucket,
				'retry_after' => $retry_after,
			);

			return new WP_Error(
				'rate_limit_exceeded',
				sprintf(
					/* translators: 1: burst request limit 2: burst window in seconds 3: seconds until retry */
					__( 'Burst limit exceeded. %1$d requests per %2$d seconds allowed. Try again in %3$d seconds.', 'mcpwp' ),
					$burst_limit,
					$burst_window,
					$retry_after
				),
				array(
					'status'           => 429,
					'retry_after'      => $retry_after,
					'reset_in'         => $retry_after,
					'bucket'           => $bucket,
					'window'           => 'burst',
					'limit'            => $burst_limit,
					'remaining'        => max( 0, $burst_limit - $burst_data['count'] ),
					'requested'        => $weight,
					'reset'            => $burst_data['reset'],
					'hint'             => sprintf(
						'Burst rate limit exceeded (%d/%ds). Wait %d seconds before retrying. To increase limits, the site admin can adjust settings in WP Admin > MCPWP > Settings, or create a key with custom rate_limits.',
						$burst_limit,
						$burst_window,
						$retry_after
					),
				)
			);
		}

		// Check minute limit.
		if ( $minute_data['count'] + $weight > $minute_limit ) {
			$retry_after = max( 0, $minute_data['reset'] - $now );

			$this->current_data = array(
				'limit'     => $minute_limit,
				'remaining' => max( 0, $minute_limit - $minute_data['count'] ),
				'reset'     => $minute_data['reset'],
				'window'    => 'minute',
				'bucket'      => $bucket,
				'retry_after' => $retry_after,
			);

			return new WP_Error(
				'rate_limit_exceeded',
				sprintf(
					/* translators: 1: request limit per minute 2: seconds until retry */
					__( 'Rate limit exceeded. %1$d requests per minute allowed. Try again in %2$d seconds.', 'mcpwp' ),
					$minute_limit,
					$retry_after
				),
				array(
					'status'           => 429,
					'retry_after'      => $retry_after,
					'reset_in'         => $retry_after,
					'bucket'           => $bucket,
					'window'           => 'minute',
					'limit'            => $minute_limit,
					'remaining'        => max( 0, $minute_limit - $minute_data['count'] ),
					'requested'        => $weight,
					'reset'            => $minute_data['reset'],
					'hint'             => sprintf(
						'Per-minute rate limit exceeded (%d/min). Wait %d seconds before retrying. To increase limits, the site admin can adjust rate limit settings or create a key with custom rate_limits.',
						$minute_limit,
						$retry_after
					),
				)
			);
		}

		// Check hour limit.
		if ( $hour_data['count'] + $weight > $hour_limit ) {
			$retry_after = max( 0, $hour_data['reset'] - $now );

			$this->current_data = array(
				'limit'     => $hour_limit,
				'remaining' => max( 0, $hour_limit - $hour_data['count'] ),
				'reset'     => $hour_data['reset'],
				'window'    => 'hour',
				'bucket'      => $bucket,
				'retry_after' => $retry_after,
			);

			return new WP_Error(
				'rate_limit_exceeded',
				sprintf(
					/* translators: 1: request limit per hour 2: seconds until retry */
					__( 'Rate limit exceeded. %1$d requests per hour allowed. Try again in %2$d seconds.', 'mcpwp' ),
					$hour_limit,
					$retry_after
				),
				array(
					'status'           => 429,
					'retry_after'      => $retry_after,
					'reset_in'         => $retry_after,
					'bucket'           => $bucket,
					'window'           => 'hour',
					'limit'            => $hour_limit,
					'remaining'        => max( 0, $hour_limit - $hour_data['count'] ),
					'requested'        => $weight,
					'reset'            => $hour_data['reset'],
					'hint'             => sprintf(
						'Hourly rate limit exceeded (%d/hr). Wait %d seconds before retrying. To increase limits, the site admin can adjust rate limit settings or create a key with custom rate_limits.',
						$hour_limit,
						$retry_after
					),
				)
			);
		}

		// Increment counts.
		$minute_data['count'] += $weight;
		$hour_data['count']   += $weight;
		$burst_data['count']  += $weight;

		// Store updated counts using remaining window TTL (fixed window, no sliding expiration).
		set_transient( $cache_key_minute, $minute_data, max( 1, $minute_data['reset'] - $now ) );
		set_transient( $cache_key_hour, $hour_data, max( 1, $hour_data['reset'] - $now ) );
		set_transient( $cache_key_burst, $burst_data, max( 1, $burst_data['reset'] - $now ) );

		// Store current data for headers: the TIGHTEST window, so a key at
		// 999/1000 on the hour is warned about the hour, not told it has 59
		// minute-slots left.
		$this->current_data = $this->tightest_window(
			array(
				'burst'  => array( $burst_limit, $burst_data ),
				'minute' => array( $minute_limit, $minute_data ),
				'hour'   => array( $hour_limit, $hour_data ),
			),
			$now,
			$bucket
		);

		return true;
	}

	/**
	 * Get rate limit headers.
	 *
	 * @return array Headers array.
	 */
	public function get_headers() {
		$data = ! empty( $this->reported_data ) ? $this->reported_data : $this->current_data;

		if ( empty( $data ) ) {
			return array();
		}

		$now = time();

		$headers = array(
			'X-RateLimit-Limit'     => $data['limit'],
			'X-RateLimit-Remaining' => max( 0, $data['remaining'] ),
			'X-RateLimit-Reset'     => max( 0, $data['reset'] - $now ),
			'X-RateLimit-Bucket'    => isset( $data['bucket'] ) ? $data['bucket'] : 'write',
			'X-RateLimit-Window'    => isset( $data['window'] ) ? $data['window'] : 'minute',
		);

		if ( isset( $data['retry_after'] ) && $data['remaining'] <= 0 ) {
			$headers['Retry-After'] = max( 0, (int) $data['retry_after'] );
		}

		return $headers;
	}

	/**
	 * The budget left for the caller metered on this request, for in-band
	 * reporting (#802). Null when nothing was metered yet.
	 *
	 * @return array|null {limit, remaining, reset_in, window}
	 */
	public function get_budget() {
		$data = ! empty( $this->reported_data ) ? $this->reported_data : $this->current_data;

		if ( empty( $data ) ) {
			return null;
		}

		return array(
			'limit'     => (int) $data['limit'],
			'remaining' => max( 0, (int) $data['remaining'] ),
			'reset_in'  => max( 0, (int) $data['reset'] - time() ),
			'window'    => (string) $data['window'],
			'bucket'    => isset( $data['bucket'] ) ? (string) $data['bucket'] : 'write',
		);
	}

	/**
	 * Report a bucket other than the one just metered (#802).
	 *
	 * A diagnostic request is metered in its own bucket, but the budget a
	 * client wants to know about is the WRITE budget it is about to spend.
	 * This peeks at that bucket without charging it, and get_budget() /
	 * get_headers() answer from it for the rest of the request.
	 *
	 * @param string     $identifier Bucket identifier (no ':diag' suffix).
	 * @param string     $method     HTTP method the writes would use.
	 * @param array|null $key_record Key record, for per-key ceilings.
	 */
	public function report_bucket( $identifier, $method = 'POST', $key_record = null ) {
		$limits       = $this->get_effective_limits( $key_record );
		$is_read      = in_array( strtoupper( (string) $method ), array( 'GET', 'HEAD', 'OPTIONS' ), true );
		$burst_limit  = $is_read ? $limits['burst_limit'] * 2 : $limits['burst_limit'];
		$minute_limit = $is_read ? $limits['requests_per_minute'] * 2 : $limits['requests_per_minute'];
		$hour_limit   = $limits['requests_per_hour'];
		$now          = time();
		$burst_window = $this->get_burst_window();

		$this->reported_data = $this->tightest_window(
			array(
				'burst'  => array( $burst_limit, $this->initialize_window_data( get_transient( 'mcpwp_rate_' . md5( $identifier . '_burst' ) ), $burst_window, $now ) ),
				'minute' => array( $minute_limit, $this->initialize_window_data( get_transient( 'mcpwp_rate_' . md5( $identifier . '_minute' ) ), 60, $now ) ),
				'hour'   => array( $hour_limit, $this->initialize_window_data( get_transient( 'mcpwp_rate_' . md5( $identifier . '_hour' ) ), 3600, $now ) ),
			),
			$now,
			'write'
		);
	}

	/**
	 * The window with the least room, as current_data / reported_data.
	 *
	 * @param array  $windows {name: [limit, data]}.
	 * @param int    $now     Time.
	 * @param string $bucket  Bucket label.
	 * @return array
	 */
	private function tightest_window( array $windows, $now, $bucket ) {
		$best = null;

		foreach ( $windows as $name => $pair ) {
			list( $limit, $data ) = $pair;
			$remaining = max( 0, (int) $limit - (int) $data['count'] );

			if ( null === $best || $remaining < $best['remaining'] ) {
				$best = array(
					'limit'       => (int) $limit,
					'remaining'   => $remaining,
					'reset'       => (int) $data['reset'],
					'window'      => $name,
					'retry_after' => max( 0, (int) $data['reset'] - $now ),
					'bucket'      => $bucket,
				);
			}
		}

		return $best;
	}

	/**
	 * Get client identifier.
	 *
	 * @return string Client identifier (IP address).
	 */
	private function get_client_identifier() {
		// Proxy-supplied client-IP headers are spoofable; trusting them lets an
		// anonymous caller rotate the value to evade the per-IP throttle or forge
		// a whitelisted IP. Honour them only behind the explicit
		// MCPWP_TRUST_PROXY_HEADERS opt-in; otherwise use REMOTE_ADDR.
		$trust_proxy = defined( 'MCPWP_TRUST_PROXY_HEADERS' ) && MCPWP_TRUST_PROXY_HEADERS;

		$ip_keys = $trust_proxy
			? array(
				'HTTP_CF_CONNECTING_IP',
				'HTTP_X_FORWARDED_FOR',
				'HTTP_X_REAL_IP',
				'REMOTE_ADDR',
			)
			: array( 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return 'unknown';
	}

	/**
	 * Check if identifier is whitelisted.
	 *
	 * @param string $identifier Client identifier.
	 * @return bool True if whitelisted.
	 */
	private function is_whitelisted( $identifier ) {
		if ( empty( $this->settings['whitelist'] ) ) {
			return false;
		}

		$whitelist = array_map( 'trim', $this->settings['whitelist'] );
		return in_array( $identifier, $whitelist, true );
	}

	/**
	 * Get current settings.
	 *
	 * @return array Settings.
	 */
	public function get_settings() {
		return $this->settings;
	}

	/**
	 * Update settings.
	 *
	 * @param array $new_settings New settings.
	 * @return bool Success.
	 */
	public function update_settings( $new_settings ) {
		$allowed = array( 'enabled', 'requests_per_minute', 'requests_per_hour', 'burst_limit', 'whitelist' );

		foreach ( $new_settings as $key => $value ) {
			if ( in_array( $key, $allowed, true ) ) {
				switch ( $key ) {
					case 'enabled':
						$this->settings['enabled'] = (bool) $value;
						break;
					case 'requests_per_minute':
					case 'requests_per_hour':
					case 'burst_limit':
						$this->settings[ $key ] = max( 1, min( 100000, (int) $value ) );
						break;
					case 'whitelist':
						$this->settings['whitelist'] = $this->sanitize_whitelist( $value );
						break;
				}
			}
		}

		if ( $this->settings['burst_limit'] > $this->settings['requests_per_minute'] ) {
			$this->settings['burst_limit'] = $this->settings['requests_per_minute'];
		}

		return update_option( 'mcpwp_rate_limit_settings', $this->settings );
	}

	/**
	 * Reset rate limit for identifier.
	 *
	 * @param string $identifier Client identifier.
	 * @return bool Success.
	 */
	public function reset_limit( $identifier ) {
		$cache_key_minute = 'mcpwp_rate_' . md5( $identifier . '_minute' );
		$cache_key_hour   = 'mcpwp_rate_' . md5( $identifier . '_hour' );
		$cache_key_burst  = 'mcpwp_rate_' . md5( $identifier . '_burst' );

		delete_transient( $cache_key_minute );
		delete_transient( $cache_key_hour );
		delete_transient( $cache_key_burst );

		return true;
	}

	/**
	 * Get usage stats for identifier.
	 *
	 * @param string $identifier Client identifier.
	 * @return array Usage stats.
	 */
	public function get_usage( $identifier = null ) {
		if ( null === $identifier ) {
			$identifier = $this->get_client_identifier();
		}

		$cache_key_minute = 'mcpwp_rate_' . md5( $identifier . '_minute' );
		$cache_key_hour   = 'mcpwp_rate_' . md5( $identifier . '_hour' );
		$cache_key_burst  = 'mcpwp_rate_' . md5( $identifier . '_burst' );

		$minute_data = get_transient( $cache_key_minute );
		$hour_data   = get_transient( $cache_key_hour );
		$burst_data  = get_transient( $cache_key_burst );
		$now         = time();

		if ( $this->is_window_expired( $minute_data, $now ) ) {
			delete_transient( $cache_key_minute );
			$minute_data = false;
		}

		if ( $this->is_window_expired( $hour_data, $now ) ) {
			delete_transient( $cache_key_hour );
			$hour_data = false;
		}

		if ( $this->is_window_expired( $burst_data, $now ) ) {
			delete_transient( $cache_key_burst );
			$burst_data = false;
		}

		return array(
			'identifier'         => $identifier,
			'burst' => array(
				'used'      => $burst_data ? $burst_data['count'] : 0,
				'limit'     => $this->settings['burst_limit'],
				'remaining' => max( 0, $this->settings['burst_limit'] - ( $burst_data ? $burst_data['count'] : 0 ) ),
				'reset'     => $burst_data ? $burst_data['reset'] : null,
			),
			'minute' => array(
				'used'      => $minute_data ? $minute_data['count'] : 0,
				'limit'     => $this->settings['requests_per_minute'],
				'remaining' => max( 0, $this->settings['requests_per_minute'] - ( $minute_data ? $minute_data['count'] : 0 ) ),
				'reset'     => $minute_data ? $minute_data['reset'] : null,
			),
			'hour' => array(
				'used'      => $hour_data ? $hour_data['count'] : 0,
				'limit'     => $this->settings['requests_per_hour'],
				'remaining' => max( 0, $this->settings['requests_per_hour'] - ( $hour_data ? $hour_data['count'] : 0 ) ),
				'reset'     => $hour_data ? $hour_data['reset'] : null,
			),
		);
	}

	/**
	 * Initialize/reset fixed-window data.
	 *
	 * @param mixed $window_data Existing window data.
	 * @param int   $window_size Window size in seconds.
	 * @param int   $now         Current unix timestamp.
	 * @return array Normalized window data.
	 */
	private function initialize_window_data( $window_data, $window_size, $now ) {
		if ( ! is_array( $window_data ) || ! isset( $window_data['count'], $window_data['reset'] ) ) {
			return array(
				'count' => 0,
				'reset' => $now + $window_size,
			);
		}

		if ( $window_data['reset'] <= $now ) {
			return array(
				'count' => 0,
				'reset' => $now + $window_size,
			);
		}

		$window_data['count'] = max( 0, (int) $window_data['count'] );
		$window_data['reset'] = (int) $window_data['reset'];

		return $window_data;
	}

	/**
	 * Check if a rate-limit window has expired or is malformed.
	 *
	 * @param mixed $window_data Existing window data.
	 * @param int   $now         Current unix timestamp.
	 * @return bool True when expired/invalid.
	 */
	private function is_window_expired( $window_data, $now ) {
		if ( ! is_array( $window_data ) || ! isset( $window_data['reset'] ) ) {
			return false;
		}

		return (int) $window_data['reset'] <= $now;
	}

	/**
	 * Get burst window length in seconds.
	 *
	 * @return int Window size in seconds.
	 */
	private function get_burst_window() {
		return 10;
	}

	/**
	 * Get effective rate limits, applying per-key overrides if present.
	 *
	 * Scoped API keys stored in the `mcpwp_scoped_api_keys` option may include
	 * an optional `rate_limits` field, e.g. {"burst": 50, "per_minute": 120, "per_hour": 2000}.
	 * These values override the global defaults for that specific key.
	 *
	 * @param array|null $key_record Scoped API key record (or null for defaults).
	 * @return array Effective limits with keys: burst_limit, requests_per_minute, requests_per_hour.
	 */
	private function get_effective_limits( $key_record = null ) {
		$limits = array(
			'burst_limit'         => $this->settings['burst_limit'],
			'requests_per_minute' => $this->settings['requests_per_minute'],
			'requests_per_hour'   => $this->settings['requests_per_hour'],
		);

		if ( ! is_array( $key_record ) || empty( $key_record['rate_limits'] ) ) {
			return $limits;
		}

		$overrides = $key_record['rate_limits'];
		if ( ! is_array( $overrides ) ) {
			return $limits;
		}

		if ( isset( $overrides['burst'] ) ) {
			$limits['burst_limit'] = max( 1, min( 100000, (int) $overrides['burst'] ) );
		}

		if ( isset( $overrides['per_minute'] ) ) {
			$limits['requests_per_minute'] = max( 1, min( 100000, (int) $overrides['per_minute'] ) );
		}

		if ( isset( $overrides['per_hour'] ) ) {
			$limits['requests_per_hour'] = max( 1, min( 100000, (int) $overrides['per_hour'] ) );
		}

		// Burst cannot exceed per-minute limit.
		if ( $limits['burst_limit'] > $limits['requests_per_minute'] ) {
			$limits['burst_limit'] = $limits['requests_per_minute'];
		}

		return $limits;
	}

	/**
	 * Sanitize whitelist identifiers.
	 *
	 * @param mixed $value Whitelist input.
	 * @return array Sanitized list.
	 */
	private function sanitize_whitelist( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$output = array();
		foreach ( $value as $item ) {
			$item = trim( sanitize_text_field( (string) $item ) );
			if ( '' === $item ) {
				continue;
			}
			$output[] = $item;
		}

		return array_values( array_unique( $output ) );
	}
}
