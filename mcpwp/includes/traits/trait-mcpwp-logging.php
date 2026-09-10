<?php
/**
 * Logging Trait
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activity logging functionality.
 */
trait Mcpwp_Logging {

	/**
	 * Get configured redaction keys.
	 *
	 * @return array
	 */
	protected function get_log_redaction_keys() {
		$defaults = array( 'api_key', 'x-api-key', 'authorization', 'password', 'secret', 'token' );
		$settings = get_option( 'mcpwp_settings', array() );
		$keys = isset( $settings['log_redaction_keys'] ) ? $settings['log_redaction_keys'] : $defaults;

		if ( is_string( $keys ) ) {
			$keys = preg_split( '/[\r\n,]+/', $keys );
		}

		if ( ! is_array( $keys ) ) {
			return $defaults;
		}

		$out = array();
		foreach ( $keys as $key ) {
			$key = strtolower( trim( sanitize_text_field( (string) $key ) ) );
			if ( '' === $key ) {
				continue;
			}
			$out[] = $key;
		}

		return ! empty( $out ) ? array_values( array_unique( $out ) ) : $defaults;
	}

	/**
	 * Redact sensitive values recursively.
	 *
	 * @param mixed $data Data.
	 * @param array $redaction_keys Keys to redact.
	 * @return mixed
	 */
	protected function redact_log_data( $data, $redaction_keys, $under_secret = false ) {
		// One walker for the whole plugin, through every encoding level.
		if ( class_exists( 'Mcpwp_Option_Access' ) && method_exists( 'Mcpwp_Option_Access', 'redact_for_log' ) ) {
			return Mcpwp_Option_Access::redact_for_log( $data, array_map( 'strtolower', array_map( 'strval', (array) $redaction_keys ) ), $under_secret );
		}

		if ( is_array( $data ) ) {
			// {key: 'woocommerce_api_key', value: 'Hunter2!!'} — the option
			// name says the value is a secret even when the value's own shape
			// does not.
			$value_is_secret = isset( $data['key'] ) && is_string( $data['key'] ) && class_exists( 'Mcpwp_Option_Access' ) && Mcpwp_Option_Access::is_secret_field( $data['key'] );

			$out = array();
			foreach ( $data as $key => $value ) {
				if ( $value_is_secret && 'value' === $key ) {
					// A secret option's value is redacted whatever its shape —
					// an array under a secret name is walked with every leaf
					// marked, not passed through because it is not a string.
					$out[ $key ] = is_array( $value ) ? $this->redact_log_data( $value, $redaction_keys, true ) : '[redacted]';
					continue;
				}
				$key_norm = is_string( $key ) ? strtolower( (string) $key ) : '';
				if ( '' !== $key_norm && in_array( $key_norm, $redaction_keys, true ) ) {
					$out[ $key ] = '[redacted]';
					continue;
				}
				// One detector for the whole plugin (#800): any secret-shaped
				// field name, anywhere in the tree, marks everything beneath it;
				// a credential-shaped value is redacted whatever it is called.
				$secret = $under_secret || ( is_string( $key ) && class_exists( 'Mcpwp_Option_Access' ) && Mcpwp_Option_Access::is_secret_field( $key ) );
				if ( is_string( $value ) && class_exists( 'Mcpwp_Option_Access' ) && ( ( $secret && Mcpwp_Option_Access::looks_like_secret_value( $value ) ) || Mcpwp_Option_Access::looks_like_credential( $value ) ) ) {
					$out[ $key ] = '[redacted]';
					continue;
				}
				$out[ $key ] = $this->redact_log_data( $value, $redaction_keys, $secret );
			}
			return $out;
		}

		return $data;
	}

	/**
	 * Log API activity.
	 *
	 * @param string          $action      Action name.
	 * @param WP_REST_Request $request     Request object.
	 * @param mixed           $response    Response data.
	 * @param int             $status_code HTTP status code.
	 */
	protected function log_activity( $action, $request, $response = null, $status_code = 200 ) {
		$settings = get_option( 'mcpwp_settings', array() );

		if ( empty( $settings['enable_logging'] ) ) {
			return;
		}

		$redaction_keys = $this->get_log_redaction_keys();

		global $wpdb;
		$table = $wpdb->prefix . 'mcpwp_activity_log';

		$data = array(
			'action'      => sanitize_key( $action ),
			'endpoint'    => $request->get_route(),
			'method'      => $request->get_method(),
			'status_code' => absint( $status_code ),
			'ip_address'  => $this->get_client_ip_for_logging(),
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'created_at'  => current_time( 'mysql' ),
		);

		// Optionally log request data (excluding sensitive info)
		$request_data = $this->get_loggable_request_data( $request );
		if ( ! empty( $request_data ) ) {
			$data['request_data'] = wp_json_encode( $this->redact_log_data( $request_data, $redaction_keys ) );
		}

		// Log response size if available
		// A refusal's payload is the reason it was refused — small, redacted,
		// and the one thing an audit needs. It is kept whatever the response
		// storage setting says; success payloads still obey the setting.
		if ( null !== $response && ( ! empty( $settings['log_store_response_data'] ) || $status_code >= 400 ) ) {
			$redacted_response = $this->redact_log_data( $response, $redaction_keys );
			$response_json = wp_json_encode( $redacted_response );
			$data['response_data'] = strlen( $response_json ) > 1000 ? null : $response_json;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			$data,
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get loggable request data (excluding sensitive fields).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array Loggable data.
	 */
	protected function get_loggable_request_data( $request ) {
		$params = $request->get_params();

		// Truncate large fields
		foreach ( $params as $key => $value ) {
			if ( is_string( $value ) && strlen( $value ) > 500 ) {
				$params[ $key ] = substr( $value, 0, 500 ) . '...[truncated]';
			}
		}

		return $params;
	}

	/**
	 * Get client IP address.
	 *
	 * Proxy-supplied client-IP headers are spoofable; trusting them
	 * unconditionally would let an anonymous caller write an arbitrary
	 * address into the activity log by setting a header, corrupting the
	 * audit trail in exactly the incident where it would be consulted.
	 * Honour them only behind the explicit MCPWP_TRUST_PROXY_HEADERS
	 * opt-in — the same gate used by the rate limiter, the auth trait, and
	 * the feedback relay throttle.
	 *
	 * Note: This method may also be defined in Mcpwp_Api_Auth trait.
	 * When both traits are used, PHP will use one of them.
	 *
	 * @return string IP address.
	 */
	protected function get_client_ip_for_logging() {
		$trust_proxy = defined( 'MCPWP_TRUST_PROXY_HEADERS' ) && MCPWP_TRUST_PROXY_HEADERS;

		return $this->resolve_logging_client_ip( $trust_proxy );
	}

	/**
	 * Resolve the client IP for the activity log under an explicit trust flag.
	 *
	 * Split from get_client_ip_for_logging() so tests can exercise both the
	 * trusted and untrusted branches without define()-ing
	 * MCPWP_TRUST_PROXY_HEADERS — a PHP constant cannot be undefined once
	 * set, and this suite runs in a single process, so defining it here
	 * would permanently poison every later test.
	 *
	 * @param bool $trust_proxy Whether proxy-supplied headers may be believed.
	 * @return string IP address, or 'unknown' if none could be resolved.
	 */
	private function resolve_logging_client_ip( $trust_proxy ) {
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
	 * Clean old log entries.
	 *
	 * @param int $days Days to retain.
	 * @return int Number of deleted rows.
	 */
	public function clean_old_logs( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'mcpwp_activity_log';

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table WHERE created_at < %s",
				$cutoff
			)
		);

		return $deleted;
	}

	/**
	 * Get recent activity.
	 *
	 * @param int $limit Number of entries.
	 * @return array Activity entries.
	 */
	public function get_recent_activity( $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'mcpwp_activity_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table ORDER BY created_at DESC LIMIT %d",
				absint( $limit )
			),
			ARRAY_A
		);
	}
}
