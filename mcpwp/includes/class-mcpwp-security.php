<?php
/**
 * Security utilities
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security helper methods for SSRF protection and input validation.
 */
class Mcpwp_Security {

	/**
	 * Validate that a URL does not point to internal/private networks (SSRF protection).
	 *
	 * @param string $url URL to validate.
	 * @return true|WP_Error True if safe, WP_Error if blocked.
	 */
	public static function validate_external_url( $url ) {
		$parsed = wp_parse_url( $url );

		if ( empty( $parsed['host'] ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'URL must contain a valid host.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		$host = strtolower( $parsed['host'] );

		// Block localhost variants.
		$blocked_hosts = array( 'localhost', '127.0.0.1', '::1', '0.0.0.0', '[::1]' );
		if ( in_array( $host, $blocked_hosts, true ) ) {
			return new WP_Error(
				'ssrf_blocked',
				__( 'URLs pointing to localhost are not allowed.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}

		// Block .local and .internal domains.
		if ( preg_match( '/\.(local|internal|localhost|test|invalid|example)$/i', $host ) ) {
			return new WP_Error(
				'ssrf_blocked',
				__( 'URLs pointing to local/internal domains are not allowed.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}

		// Resolve hostname to IP and check for private ranges.
		$ip = gethostbyname( $host );

		// gethostbyname returns the hostname if resolution fails.
		if ( $ip === $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return new WP_Error(
				'dns_resolution_failed',
				__( 'Could not resolve hostname.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		if ( ! self::is_public_ip( $ip ) ) {
			return new WP_Error(
				'ssrf_blocked',
				__( 'URLs pointing to private/reserved IP addresses are not allowed.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}

		// Block non-HTTP(S) schemes.
		$scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'invalid_scheme',
				__( 'Only HTTP and HTTPS URLs are allowed.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Check whether a URL uses the HTTPS scheme.
	 *
	 * @param string $url URL to check.
	 * @return bool True when the URL scheme is https.
	 */
	public static function is_https_url( $url ) {
		return 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	}

	/**
	 * Check whether a URL is a trusted plugin-update package source.
	 *
	 * Requires HTTPS and a host on the trusted allowlist. Used to hard-restrict
	 * caller-supplied package_url values on the self-update endpoint so a
	 * write-scoped key cannot point the updater at an attacker-controlled zip.
	 *
	 * @param string $url Package URL to validate.
	 * @return bool True when the URL is HTTPS and its host is allowlisted.
	 */
	public static function is_trusted_package_url( $url ) {
		if ( ! self::is_https_url( $url ) ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		$allowed = apply_filters(
			'mcpwp_trusted_package_hosts',
			array( 'updates.mcpwp.net', 'mumega.com' )
		);
		$allowed = array_map( 'strtolower', (array) $allowed );

		return in_array( $host, $allowed, true );
	}

	/**
	 * Check if an IP address is public (not private/reserved/loopback).
	 *
	 * @param string $ip IP address to check.
	 * @return bool True if the IP is public.
	 */
	public static function is_public_ip( $ip ) {
		// Use PHP's built-in filter to reject private and reserved ranges.
		// This covers: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.0/8,
		//              169.254.0.0/16, 0.0.0.0/8, 224.0.0.0/4, and IPv6 equivalents.
		return false !== filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Validate Elementor data size and depth to prevent abuse.
	 *
	 * @param string $json_string JSON string to validate.
	 * @param int    $max_size    Max size in bytes (default 5MB).
	 * @param int    $max_depth   Max nesting depth (default 20).
	 * @return true|WP_Error True if valid, WP_Error if too large/deep.
	 */
	public static function validate_json_payload( $json_string, $max_size = 5242880, $max_depth = 20 ) {
		if ( strlen( $json_string ) > $max_size ) {
			return new WP_Error(
				'payload_too_large',
				sprintf(
					/* translators: %s: max size in MB */
					__( 'JSON payload exceeds maximum size of %s MB.', 'mcpwp' ),
					round( $max_size / 1048576, 1 )
				),
				array( 'status' => 413 )
			);
		}

		// Test JSON depth by decoding with depth limit.
		json_decode( $json_string, true, $max_depth );
		if ( json_last_error() === JSON_ERROR_DEPTH ) {
			return new WP_Error(
				'json_too_deep',
				sprintf(
					/* translators: %d: max depth */
					__( 'JSON nesting exceeds maximum depth of %d levels.', 'mcpwp' ),
					$max_depth
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}
}
