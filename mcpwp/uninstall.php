<?php
/** Multisite-aware plugin-owned data cleanup. @package mumcp */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

$mcpwp_uninstall_site = static function () {
	global $wpdb;
	$options = array(
		'mcpwp_api_key', 'mcpwp_api_keys', 'mcpwp_settings', 'mcpwp_rate_limit_settings', 'mcpwp_disabled_tool_categories', 'mcpwp_db_version', 'mcpwp_migrated_from_spai',
		'mcpwp_integrations', 'mcpwp_license', 'mcpwp_update_info', 'mcpwp_version_url', 'mcpwp_analytics_consent', 'mcpwp_feedback_history', 'mcpwp_oauth_clients', 'mcpwp_oauth_codes', 'mcpwp_webhooks', 'mcpwp_approvals', 'mcpwp_site_blueprints', 'mcpwp_design_references',
	);
	foreach ( $options as $option ) { delete_option( $option ); }
	foreach ( array( 'mcpwp_cleanup_logs', 'mcpwp_check_alerts', 'mcpwp_verify_screenshot', 'mcpwp_compute_signals', 'mcpwp_action_log_daily_prune', 'mcpwp_webhook_retry' ) as $hook ) { wp_clear_scheduled_hook( $hook ); }
	foreach ( array( 'mcpwp_activity_log', 'mcpwp_webhooks', 'mcpwp_webhook_logs', 'mcpwp_feedback' ) as $suffix ) {
		$table = $wpdb->prefix . $suffix;
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $blog_id ) { switch_to_blog( $blog_id ); $mcpwp_uninstall_site(); restore_current_blog(); }
} else { $mcpwp_uninstall_site(); }
