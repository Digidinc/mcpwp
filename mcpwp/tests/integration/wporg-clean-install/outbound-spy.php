<?php
/**
 * Records and blocks every WordPress HTTP API attempt during the approval lane.
 *
 * @package MumegaMcpWporgHarness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'MCPWP_WPORG_ATTEMPTS_FILE' ) ) {
	define( 'MCPWP_WPORG_ATTEMPTS_FILE', WP_CONTENT_DIR . '/mcpwp-wporg-http-attempts.jsonl' );
}
if ( ! defined( 'MCPWP_WPORG_COVERAGE_FILE' ) ) {
	define( 'MCPWP_WPORG_COVERAGE_FILE', WP_CONTENT_DIR . '/mcpwp-wporg-contexts.txt' );
}

if ( ! function_exists( 'mcpwp_wporg_mark_context' ) ) {
	function mcpwp_wporg_mark_context( $context ) {
		$GLOBALS['mcpwp_wporg_context'] = sanitize_key( (string) $context );
		file_put_contents( MCPWP_WPORG_COVERAGE_FILE, $GLOBALS['mcpwp_wporg_context'] . "\n", FILE_APPEND | LOCK_EX );
	}
}

if ( ! function_exists( 'mcpwp_wporg_reset_spy' ) ) {
	function mcpwp_wporg_reset_spy() {
		$GLOBALS['mcpwp_wporg_context'] = 'bootstrap';
		foreach ( array( MCPWP_WPORG_ATTEMPTS_FILE, MCPWP_WPORG_COVERAGE_FILE ) as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}
}

if ( ! function_exists( 'mcpwp_wporg_block_http_attempt' ) ) {
	function mcpwp_wporg_block_http_attempt( $preempt, $arguments, $url ) {
		$method = isset( $arguments['method'] ) ? strtoupper( (string) $arguments['method'] ) : 'GET';
		$caller = 'unknown';
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 ) as $frame ) {
			if ( empty( $frame['file'] ) || __FILE__ === $frame['file'] || false !== strpos( $frame['file'], '/wp-includes/class-wp-hook.php' ) || false !== strpos( $frame['file'], '/wp-includes/plugin.php' ) ) {
				continue;
			}
			$caller = $frame['file'] . ':' . ( isset( $frame['line'] ) ? (int) $frame['line'] : 0 );
			break;
		}
		$record = array(
			'context' => isset( $GLOBALS['mcpwp_wporg_context'] ) ? $GLOBALS['mcpwp_wporg_context'] : 'unknown',
			'method'  => $method,
			'url'     => (string) $url,
			'caller'  => $caller,
		);
		file_put_contents( MCPWP_WPORG_ATTEMPTS_FILE, wp_json_encode( $record ) . "\n", FILE_APPEND | LOCK_EX );
		return new WP_Error(
			'mcpwp_wporg_outbound_http_blocked',
			$record['context'] . ': blocked outbound ' . $method . ' ' . (string) $url
		);
	}
}

add_filter( 'pre_http_request', 'mcpwp_wporg_block_http_attempt', -999999, 3 );
