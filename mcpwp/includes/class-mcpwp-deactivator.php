<?php
/** Plugin deactivation. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_Deactivator {
	public static function deactivate() {
		$hooks = array(
			'mcpwp_cleanup_logs',
			'mcpwp_check_alerts',
			'mcpwp_verify_screenshot',
			'mcpwp_compute_signals',
			'mcpwp_action_log_daily_prune',
			'mcpwp_webhook_retry',
		);
		foreach ( $hooks as $hook ) { wp_clear_scheduled_hook( $hook ); }
	}
}
