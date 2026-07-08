<?php
/**
 * Admin activity page methods.
 *
 * Carved verbatim from Mcpwp_Admin (G3 split). Mixed back via trait — same class, same $this.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Admin_Activity_Trait {

	/**
	 * Render activity log page.
	 */
	public function render_activity_log_page() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mumcp' ) );
		}

		$page = new Mcpwp_Activity_Log_Page();
		$page->render();
	}

	/**
	 * Get recent API activity rows.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_recent_activity_rows( $limit = 10 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'mcpwp_activity_log';

		$limit = max( 1, min( 50, absint( $limit ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, action, endpoint, method, status_code, created_at
				 FROM {$table}
				 ORDER BY created_at DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
