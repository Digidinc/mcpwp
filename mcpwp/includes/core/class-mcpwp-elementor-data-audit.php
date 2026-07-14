<?php
/**
 * Diagnostic audit trail for _elementor_data writes/deletes.
 *
 * Added for #612 — wp_set_elementor reports success but a subsequent read
 * finds the page empty, on at least one shared-hosting + Elementor Pro
 * environment. The write path's own in-request verification (including a
 * raw $wpdb read added in 3.4.6) confirms the row is correct at write time,
 * so whatever clears it happens later, from somewhere outside this request.
 * This logs every single write/delete to _elementor_data with a backtrace,
 * so the culprit shows up in the site's PHP error log even without SSH/DB
 * access (cPanel's Error Log / File Manager can read wp-content/debug.log
 * or the host's PHP error log on shared hosting like HostGator).
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mcpwp_Elementor_Data_Audit {

	const META_KEY = '_elementor_data';
	const LOG_PREFIX = '[MCPWP-612]';

	public static function init() {
		add_action( 'added_post_meta', array( __CLASS__, 'on_write' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_write' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_delete' ), 10, 3 );
	}

	public static function on_write( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( self::META_KEY !== $meta_key ) {
			return;
		}

		$decoded = is_string( $meta_value ) ? json_decode( $meta_value, true ) : null;
		$count   = is_array( $decoded ) ? count( $decoded ) : 'invalid_or_empty';

		error_log( sprintf(
			'%s WRITE post=%d elements=%s bytes=%d backtrace=%s',
			self::LOG_PREFIX,
			$post_id,
			$count,
			is_string( $meta_value ) ? strlen( $meta_value ) : 0,
			self::backtrace_summary()
		) );
	}

	public static function on_delete( $meta_ids, $post_id, $meta_key ) {
		if ( self::META_KEY !== $meta_key ) {
			return;
		}

		error_log( sprintf(
			'%s DELETE post=%d backtrace=%s',
			self::LOG_PREFIX,
			$post_id,
			self::backtrace_summary()
		) );
	}

	/**
	 * Short, readable call chain — who actually triggered this write/delete.
	 *
	 * @return string
	 */
	private static function backtrace_summary() {
		if ( function_exists( 'wp_debug_backtrace_summary' ) ) {
			return wp_debug_backtrace_summary( null, 0, true );
		}
		return 'unavailable';
	}
}
