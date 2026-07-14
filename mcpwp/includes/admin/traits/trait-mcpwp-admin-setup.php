<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait Mcpwp_Admin_Setup_Trait {
	public function render_setup_page() {
		if ( ! current_user_can( 'mcpwp_manage_settings' ) ) { wp_die( esc_html__( 'You do not have permission to view this page.', 'mumega-mcp' ) ); }
		$new_key = '';
		if ( isset( $_POST['mcpwp_create_key'] ) && check_admin_referer( 'mcpwp_create_key' ) ) {
			Mcpwp_Migrate::setup_owner( get_current_user_id() );
			$created = $this->create_scoped_api_key( sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ), array( 'read', 'write', 'admin' ) );
			$new_key = $created['key'];
		}
		$keys = $this->list_scoped_api_keys();
		include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-setup-display.php';
	}
}
