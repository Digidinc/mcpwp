<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$test_number = 0;
$failures = 0;
function mcpwp_wp70_tap( $condition, $description ) {
	global $test_number, $failures;
	$test_number++;
	if ( ! $condition ) { $failures++; }
	echo ( $condition ? 'ok ' : 'not ok ' ) . $test_number . ' - ' . $description . "\n";
}

echo "TAP version 13\n";
echo "1..19\n";

$admin = get_user_by( 'login', 'integration-admin' );
wp_set_current_user( $admin->ID );
mcpwp_wp70_tap( '7.0.1' === get_bloginfo( 'version' ), 'runs exact WordPress 7.0.1' );
mcpwp_wp70_tap( PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400, 'runs PHP 8.3' );
mcpwp_wp70_tap( is_plugin_active( 'mumega-mcp/mcpwp.php' ), 'plugin is active' );
mcpwp_wp70_tap( current_user_can( 'mcpwp_manage_settings' ), 'activation grants the custom capability to administrators' );

global $menu, $submenu, $_registered_pages, $_parent_pages;
$menu = array(); $submenu = array(); $_registered_pages = array(); $_parent_pages = array();
do_action( 'admin_menu' );
$entries = isset( $submenu['mcpwp'] ) ? $submenu['mcpwp'] : array();
$expected_slugs = array( 'mcpwp', 'mcpwp-tools', 'mcpwp-settings', 'mcpwp-activity-log' );
$actual_slugs = array_values( array_map( static function ( $entry ) { return $entry[2]; }, $entries ) );
mcpwp_wp70_tap( $expected_slugs === $actual_slugs, 'registers exactly the four approved admin pages' );
$menu_caps_ok = 4 === count( $entries );
foreach ( $entries as $entry ) { $menu_caps_ok = $menu_caps_ok && 'mcpwp_manage_settings' === $entry[1]; }
mcpwp_wp70_tap( $menu_caps_ok, 'all four admin pages use only the custom capability' );

$operator_id = username_exists( 'integration-operator' );
if ( ! $operator_id ) { $operator_id = wp_create_user( 'integration-operator', 'operator-password', 'operator@example.test' ); }
$operator = new WP_User( $operator_id );
$operator->set_role( 'subscriber' );
$operator->add_cap( 'mcpwp_manage_settings' );
wp_set_current_user( $operator_id );
mcpwp_wp70_tap( current_user_can( 'mcpwp_manage_settings' ) && ! current_user_can( 'manage_options' ), 'custom-capability operator does not need manage_options' );

$_POST = array(); $_GET = array( 'page' => 'mcpwp' );
$admin_page = new Mcpwp_Admin();
ob_start(); $admin_page->render_setup_page(); $setup_html = ob_get_clean();
mcpwp_wp70_tap( false !== strpos( $setup_html, 'Mumega MCP Setup &amp; Keys' ), 'Setup & Keys renders for the custom-capability operator' );
$_GET['page'] = 'mcpwp-settings';
ob_start(); $admin_page->render_settings_page(); $settings_html = ob_get_clean();
mcpwp_wp70_tap( false !== strpos( $settings_html, 'Mumega MCP Settings' ), 'Settings renders for the custom-capability operator' );
$_GET['page'] = 'mcpwp-tools';
ob_start(); ( new Mcpwp_Tools_Admin() )->render(); $tools_html = ob_get_clean();
mcpwp_wp70_tap( false !== strpos( $tools_html, 'MCP Tool Inventory' ), 'Tool Inventory renders for the custom-capability operator' );
$_GET['page'] = 'mcpwp-activity-log';
ob_start(); $admin_page->render_activity_log_page(); $activity_html = ob_get_clean();
mcpwp_wp70_tap( false !== strpos( $activity_html, 'Mumega MCP Activity' ), 'Activity renders for the custom-capability operator' );

$settings = new Mcpwp_Settings();
$saved = $settings->handle_admin_save( array(
	'mcpwp_save_settings' => 'plugin', '_wpnonce' => wp_create_nonce( 'mcpwp_save_plugin_settings' ),
	'mcpwp_settings' => array( 'enable_logging' => '1', 'log_retention_days' => '45' ),
) );
mcpwp_wp70_tap( ! is_wp_error( $saved ) && array( 'enable_logging' => true, 'log_retention_days' => 45 ) === get_option( 'mcpwp_settings' ), 'custom-capability settings save round-trips' );

$created = $admin_page->handle_setup_action( array(
	'mcpwp_create_key' => '1', '_wpnonce' => wp_create_nonce( 'mcpwp_create_key' ), 'label' => 'WP 7 operator',
	'scopes' => array( 'read' ), 'tool_categories' => array( 'content' ),
) );
$created_record = ! is_wp_error( $created ) && isset( $created['created'] ) ? $created['created'] : array();
$raw_keys = get_option( 'mcpwp_api_keys', array() );
$raw_key = ! empty( $raw_keys ) ? end( $raw_keys ) : array();
$key_ok = isset( $created_record['key'], $created_record['id'] )
	&& (int) $operator_id === (int) $created_record['user_id']
	&& array( 'read' ) === $created_record['scopes'] && array( 'content' ) === $created_record['tool_categories']
	&& isset( $raw_key['hash'] ) && wp_check_password( $created_record['key'], $raw_key['hash'] ) && ! isset( $raw_key['key'] );
mcpwp_wp70_tap( $key_ok, 'Setup creates a one-time plaintext key bound to the current explicit user' );
$revoked = $admin_page->handle_setup_action( array(
	'mcpwp_revoke_key' => '1', '_wpnonce' => wp_create_nonce( 'mcpwp_revoke_key' ), 'key_id' => $created_record['id'],
) );
mcpwp_wp70_tap( ! is_wp_error( $revoked ) && $created_record['id'] === $revoked['revoked'], 'Setup revokes the created key by ID' );

$unauthorized_id = username_exists( 'integration-unauthorized' );
if ( ! $unauthorized_id ) { $unauthorized_id = wp_create_user( 'integration-unauthorized', 'unauthorized-password', 'unauthorized@example.test' ); }
$unauthorized = new WP_User( $unauthorized_id ); $unauthorized->set_role( 'subscriber' );
wp_set_current_user( $unauthorized_id );
$denied = $settings->handle_admin_save( array( 'mcpwp_save_settings' => 'plugin', '_wpnonce' => 'invalid' ) );
mcpwp_wp70_tap( is_wp_error( $denied ) && 'forbidden' === $denied->get_error_code(), 'admin mutation fails closed without the custom capability' );

wp_set_current_user( $admin->ID );
do_action( 'rest_api_init' );
$route_names = array_filter( array_keys( rest_get_server()->get_routes() ), static function ( $route ) { return 0 === strpos( $route, '/mcpwp/v1/' ); } );
mcpwp_wp70_tap( 19 === count( $route_names ), 'registers exactly 19 retained REST route patterns' );
mcpwp_wp70_tap( 17 === count( ( new Mcpwp_MCP_Free_Tools() )->get_tools() ), 'advertises exactly 17 retained MCP tools' );

$cleanup_was_scheduled = false !== wp_next_scheduled( 'mcpwp_cleanup_logs' );
deactivate_plugins( 'mumega-mcp/mcpwp.php' );
mcpwp_wp70_tap( $cleanup_was_scheduled && false === wp_next_scheduled( 'mcpwp_cleanup_logs' ) && ! is_plugin_active( 'mumega-mcp/mcpwp.php' ), 'deactivation clears current cleanup schedule and deactivates the plugin' );

$debug_log = WP_CONTENT_DIR . '/debug.log';
$debug = is_file( $debug_log ) ? file_get_contents( $debug_log ) : '';
mcpwp_wp70_tap( ! preg_match( '/PHP (Deprecated|Warning|Notice|Fatal|Parse error)/i', $debug ), 'debug log has no PHP deprecation warning notice or fatal' );

exit( $failures > 0 ? 1 : 0 );
