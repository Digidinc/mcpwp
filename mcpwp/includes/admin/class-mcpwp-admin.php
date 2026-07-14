<?php
/** Minimal local administration. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class Mcpwp_Admin {
	use Mcpwp_Api_Auth;
	use Mcpwp_Admin_Setup_Trait;
	use Mcpwp_Admin_Settings_Trait;
	use Mcpwp_Admin_Activity_Trait;

	const PAGE_SLUG = 'mcpwp';
	const SETTINGS_PAGE_SLUG = 'mcpwp-settings';
	const ACTIVITY_LOG_PAGE_SLUG = 'mcpwp-activity-log';

	public function add_admin_menu() {
		add_menu_page( __( 'Mumega MCP', 'mumega-mcp' ), __( 'Mumega MCP', 'mumega-mcp' ), 'mcpwp_manage_settings', self::PAGE_SLUG, array( $this, 'render_setup_page' ), 'dashicons-rest-api', 80 );
		add_submenu_page( self::PAGE_SLUG, __( 'Setup & Keys', 'mumega-mcp' ), __( 'Setup & Keys', 'mumega-mcp' ), 'mcpwp_manage_settings', self::PAGE_SLUG, array( $this, 'render_setup_page' ) );
		add_submenu_page( self::PAGE_SLUG, __( 'Tool Inventory', 'mumega-mcp' ), __( 'Tool Inventory', 'mumega-mcp' ), 'mcpwp_manage_settings', Mcpwp_Tools_Admin::PAGE_SLUG, array( new Mcpwp_Tools_Admin(), 'render' ) );
		add_submenu_page( self::PAGE_SLUG, __( 'Settings', 'mumega-mcp' ), __( 'Settings', 'mumega-mcp' ), 'mcpwp_manage_settings', self::SETTINGS_PAGE_SLUG, array( $this, 'render_settings_page' ) );
		add_submenu_page( self::PAGE_SLUG, __( 'Activity', 'mumega-mcp' ), __( 'Activity', 'mumega-mcp' ), 'mcpwp_manage_settings', self::ACTIVITY_LOG_PAGE_SLUG, array( $this, 'render_activity_log_page' ) );
	}
	private function is_plugin_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return in_array( $page, array( self::PAGE_SLUG, self::SETTINGS_PAGE_SLUG, self::ACTIVITY_LOG_PAGE_SLUG, Mcpwp_Tools_Admin::PAGE_SLUG ), true );
	}
	public function enqueue_styles( $hook ) {
		if ( $this->is_plugin_page() ) {
			wp_enqueue_style( 'mcpwp-admin', MCPWP_PLUGIN_URL . 'admin/css/mcpwp-admin.css', array(), MCPWP_VERSION ); } }
	public function add_action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Setup', 'mumega-mcp' ) . '</a>' );
		return $links;
	}
}
