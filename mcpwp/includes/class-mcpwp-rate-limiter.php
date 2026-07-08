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
	public function check_limit( $identifier = null, $method = 'POST', $key_record = null ) {
		if ( ! $this->is_enabled() ) {
			return true;
		}

		if ( null === $identifier ) {
			$identifier = $this->get_client_identifier();
		}

		// Check whitelist.
		if ( $this->is_whitelisted( $identifier ) ) {
			return true;
		}

		// Apply per-key rate limit overrides if present.
		$effective_limits = $this->get_effective_limits( $key_record );

		// GET/HEAD/OPTIONS requests get 2x burst and minute limits (reads are non-destructive).
		$is_read      = in_array( strtoupper( $method ), array( 'GET', 'HEAD', 'OPTIONS' ), true );
		$burst_limit  = $is_read ? $effective_limits['burst_limit'] * 2 : $effective_limits['burst_limit'];
		$minute_limit = $is_read ? $effective_limits['requests_per_minute'] * 2 : $effective_limits['requests_per_minute'];
		$hour_limit   = $effective_limits['requests_per_hour'];

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
		if ( $burst_data['count'] >= $burst_limit ) {
			$retry_after = max( 0, $burst_data['reset'] - $now );

			$this->current_data = array(
				'limit'       => $burst_limit,
				'remaining'   => 0,
				'reset'       => $burst_data['reset'],
				'window'      => 'burst',
				'retry_after' => $retry_after,
			);

			return new WP_Error(
				'rate_limit_exceeded',
				sprintf(
					/* translators: 1: burst request limit 2: burst window in seconds 3: seconds until retry */
					__( 'Burst limit exceeded. %1$d requests per %2$d seconds allowed. Try again in %3$d seconds.', 'mumcp' ),
					$burst_limit,
					$burst_window,
					$retry_after
				),
				array(
					'status'           => 429,
					'retry_after'      => $retry_after,
					'limit'            => $burst_limit,
					'remaining'        => 0,
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
		if ( $minute_data['count'] >= $minute_limit ) {
			$retry_after = max( 0, $minute_data['reset'] - $now );

			$this->current_data = array(
				'limit'     => $minute_limit,
				'remaining' => 0,
				'reset'     => $minute_data['reset'],
				'window'    => 'minute',
				'retry_after' => $retry_after,
			);

			return new WP_Error(
				'rate_limit_exceeded',
				sprintf(
					/* translators: 1: request limit per minute 2: seconds until retry */
					__( 'Rate limit exceeded. %1$d requests per minute allowed. Try again in %2$d seconds.', 'mumcp' ),
					$minute_limit,
					$retry_after
				),
				array(
					'status'           => 429,
					'retry_after'      => $retry_after,
					'limit'            => $minute_limit,
					'remaining'        => 0,
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
		if ( $hour_data['count'] >= $hour_limit ) {
			$retry_after = max( 0, $hour_data['reset'] - $now );

			$this->current_data = array(
				'limit'     => $hour_limit,
				'remaining' => 0,
				'reset'     => $hour_data['reset'],
				'window'    => 'hour',
				'retry_after' => $retry_after,
			);

			return new WP_Error(
				'rate_limit_exceeded',
				sprintf(
					/* translators: 1: request limit per hour 2: seconds until retry */
					__( 'Rate limit exceeded. %1$d requests per hour allowed. Try again in %2$d seconds.', 'mumcp' ),
					$hour_limit,
					$retry_after
				),
				array(
					'status'           => 429,
					'retry_after'      => $retry_after,
					'limit'            => $hour_limit,
					'remaining'        => 0,
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
		$minute_data['count']++;
		$hour_data['count']++;
		$burst_data['count']++;

		// Store updated counts using remaining window TTL (fixed window, no sliding expiration).
		set_transient( $cache_key_minute, $minute_data, max( 1, $minute_data['reset'] - $now ) );
		set_transient( $cache_key_hour, $hour_data, max( 1, $hour_data['reset'] - $now ) );
		set_transient( $cache_key_burst, $burst_data, max( 1, $burst_data['reset'] - $now ) );

		// Store current data for headers.
		$this->current_data = array(
			'limit'     => $minute_limit,
			'remaining' => $minute_limit - $minute_data['count'],
			'reset'     => $minute_data['reset'],
			'window'    => 'minute',
			'retry_after' => max( 0, $minute_data['reset'] - $now ),
		);

		return true;
	}

	/**
	 * Get rate limit headers.
	 *
	 * @return array Headers array.
	 */
	public function get_headers() {
		if ( empty( $this->current_data ) ) {
			return array();
		}

		$now = time();

		$headers = array(
			'X-RateLimit-Limit'     => $this->current_data['limit'],
			'X-RateLimit-Remaining' => max( 0, $this->current_data['remaining'] ),
			'X-RateLimit-Reset'     => max( 0, $this->current_data['reset'] - $now ),
		);

		if ( isset( $this->current_data['retry_after'] ) && $this->current_data['remaining'] <= 0 ) {
			$headers['Retry-After'] = max( 0, (int) $this->current_data['retry_after'] );
		}

		return $headers;
	}

	/**
	 * Get client identifier.
	 *
	 * @return string Client identifier (IP address).
	 */
	private function get_client_identifier() {
		$ip_keys = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

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
