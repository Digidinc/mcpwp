<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
trait Mcpwp_Admin_Settings_Trait {
	public function render_settings_page() {
		if ( ! current_user_can( 'mcpwp_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mumega-mcp' ) ); }
		$settings_controller = new Mcpwp_Settings();
		$save_result         = null;
		if ( isset( $_POST['mcpwp_save_settings'] ) ) {
			$save_result = $settings_controller->handle_admin_save( $_POST );
		}
		include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-settings-display.php';
	}
}
