<?php
/** Minimal local activity logging. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

trait Mcpwp_Logging {
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

	public function clean_old_logs( $days = 30 ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'mcpwp_activity_log';
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . absint( $days ) . ' days' ) );
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	public function get_recent_activity( $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'mcpwp_activity_log';
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, action, status_code, duration_ms, api_key_id, created_at FROM {$table} ORDER BY created_at DESC LIMIT %d", absint( $limit ) ), ARRAY_A );
	}
}
