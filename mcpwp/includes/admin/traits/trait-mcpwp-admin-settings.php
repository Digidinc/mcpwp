<?php
/**
 * Settings-page administration methods.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
trait Mcpwp_Admin_Settings_Trait {
	/**
	 * Render retained settings and process an optional settings submission.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'mcpwp_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mumega-mcp' ) ); }
		$settings_controller = new Mcpwp_Settings();
		$save_result         = null;
		if ( isset( $_POST['mcpwp_save_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The delegated handler verifies the target's action-specific nonce before mutation.
			$save_result = $settings_controller->handle_admin_save( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Mcpwp_Settings::handle_admin_save() verifies the target's action-specific nonce before mutation.
		}
		include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-settings-display.php';
	}
}
