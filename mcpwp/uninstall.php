<?php
/** Multisite-aware plugin-owned data cleanup. @package mumcp */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

$mcpwp_uninstall_site = static function () {
	global $wpdb;
	delete_option( 'mcpwp_api_key' );
	delete_option( 'mcpwp_api_keys' );
	delete_option( 'mcpwp_settings' );
	delete_option( 'mcpwp_rate_limit_settings' );
	delete_option( 'mcpwp_disabled_tool_categories' );
	delete_option( 'mcpwp_db_version' );
	delete_option( 'mcpwp_migrated_from_spai' );
	delete_option( 'mcpwp_integrations' );
	delete_option( 'mcpwp_license' );
	delete_option( 'mcpwp_update_info' );
	delete_option( 'mcpwp_version_url' );
	delete_option( 'mcpwp_analytics_consent' );
	delete_option( 'mcpwp_feedback_history' );
	delete_option( 'mcpwp_oauth_clients' );
	delete_option( 'mcpwp_oauth_codes' );
	delete_option( 'mcpwp_webhooks' );
	delete_option( 'mcpwp_approvals' );
	delete_option( 'mcpwp_site_blueprints' );
	delete_option( 'mcpwp_design_references' );
	wp_clear_scheduled_hook( 'mcpwp_cleanup_logs' );
	wp_clear_scheduled_hook( 'mcpwp_check_alerts' );
	wp_clear_scheduled_hook( 'mcpwp_verify_screenshot' );
	wp_clear_scheduled_hook( 'mcpwp_compute_signals' );
	wp_clear_scheduled_hook( 'mcpwp_action_log_daily_prune' );
	wp_clear_scheduled_hook( 'mcpwp_webhook_retry' );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcpwp_activity_log" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcpwp_webhooks" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcpwp_webhook_logs" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcpwp_feedback" );
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $blog_id ) {
		switch_to_blog( $blog_id );
		$mcpwp_uninstall_site();
		restore_current_blog();
	}
} else {
	$mcpwp_uninstall_site();
}
