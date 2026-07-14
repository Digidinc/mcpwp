<?php
/**
 * Minimal local activity logging.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

trait Mcpwp_Logging {
	/**
	 * Store one local MCP activity metadata row when logging is enabled.
	 *
	 * @param string $action      Tool or operation name.
	 * @param int    $status_code Resulting status code.
	 * @param int    $duration_ms Operation duration in milliseconds.
	 * @param string $api_key_id  Scoped API-key identifier.
	 * @return void
	 */
	protected function log_activity( $action, $status_code, $duration_ms, $api_key_id ) {
		$settings = get_option( 'mcpwp_settings', array() );
		if ( empty( $settings['enable_logging'] ) ) {
			return; }
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mcpwp_activity_log',
			array(
				'action'      => sanitize_key( $action ),
				'status_code' => absint( $status_code ),
				'duration_ms' => absint( $duration_ms ),
				'api_key_id'  => sanitize_text_field( $api_key_id ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Delete local activity rows older than a retention period.
	 *
	 * @param int $days Retention period in days.
	 * @return int|false Number of deleted rows, or false on failure.
	 */
	public function clean_old_logs( $days = 30 ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'mcpwp_activity_log';
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . absint( $days ) . ' days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress 6.1 lacks the %i identifier placeholder; the table name is only $wpdb->prefix plus a fixed suffix, and the cutoff retains a %s placeholder.
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Get recent local activity metadata rows.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int,array<string,mixed>>|null Activity rows, or null on failure.
	 */
	public function get_recent_activity( $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'mcpwp_activity_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress 6.1 lacks the %i identifier placeholder; the table name is only $wpdb->prefix plus a fixed suffix, and the limit retains a %d placeholder.
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, action, status_code, duration_ms, api_key_id, created_at FROM {$table} ORDER BY created_at DESC LIMIT %d", absint( $limit ) ), ARRAY_A );
	}
}
