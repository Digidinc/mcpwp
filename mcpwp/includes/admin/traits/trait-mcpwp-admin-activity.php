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
		if ( ! current_user_can( 'mcpwp_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mumega-mcp' ) );
		}

		$page = new Mcpwp_Activity_Log_Page();
		$page->render();
	}
}
