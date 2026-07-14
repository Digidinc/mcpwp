<?php
/** Exercise the exact installed candidate without Internet access. */

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$mcpwp_wporg_test_number = 0;
$mcpwp_wporg_failures    = 0;

function mcpwp_wporg_tap( $condition, $description, $diagnostic = '' ) {
	global $mcpwp_wporg_test_number, $mcpwp_wporg_failures;
	++$mcpwp_wporg_test_number;
	if ( ! $condition ) {
		++$mcpwp_wporg_failures;
	}
	echo ( $condition ? 'ok ' : 'not ok ' ) . $mcpwp_wporg_test_number . ' - ' . $description . "\n";
	if ( ! $condition && '' !== $diagnostic ) {
		echo '# ' . str_replace( "\n", "\n# ", $diagnostic ) . "\n";
	}
}

function mcpwp_wporg_rpc_wire_value( $value, $field = '' ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( empty( $value ) && in_array( $field, array( 'params', 'arguments' ), true ) ) {
		return (object) array();
	}
	$output = array();
	foreach ( $value as $key => $item ) {
		$output[ $key ] = mcpwp_wporg_rpc_wire_value( $item, is_string( $key ) ? $key : '' );
	}
	return $output;
}

function mcpwp_wporg_tool_call( $name, $arguments, $api_key ) {
	mcpwp_wporg_mark_context( $name );
	$payload = array(
		'jsonrpc' => '2.0',
		'id'      => $name,
		'method'  => 'tools/call',
		'params'  => array( 'name' => $name, 'arguments' => $arguments ),
	);
	$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' );
	$request->set_header( 'X-API-Key', $api_key );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( mcpwp_wporg_rpc_wire_value( $payload ) ) );
	$response = rest_do_request( $request );
	$data     = is_wp_error( $response ) ? array( 'transport_error' => $response->get_error_message() ) : $response->get_data();
	$success  = ! is_wp_error( $response ) && 200 === $response->get_status() && is_array( $data ) && isset( $data['result']['structuredContent'] ) && ! isset( $data['error'] );
	mcpwp_wporg_tap( $success, $name . ' reaches its successful MCP tool path', wp_json_encode( $data ) );
	return $success ? $data['result']['structuredContent'] : array();
}

function mcpwp_wporg_plugin_options_absent() {
	$marker = new stdClass();
	$names  = array(
		'mcpwp_api_key', 'mcpwp_api_keys', 'mcpwp_legacy_migration_owner_id', 'mcpwp_settings',
		'mcpwp_rate_limit_settings', 'mcpwp_disabled_tool_categories', 'mcpwp_db_version',
		'mcpwp_migrated_from_spai', 'mcpwp_integrations', 'mcpwp_license', 'mcpwp_update_info',
		'mcpwp_version_url', 'mcpwp_analytics_consent', 'mcpwp_feedback_history', 'mcpwp_oauth_clients',
		'mcpwp_oauth_codes', 'mcpwp_webhooks', 'mcpwp_approvals', 'mcpwp_site_blueprints',
		'mcpwp_design_references',
	);
	foreach ( $names as $name ) {
		if ( $marker !== get_option( $name, $marker ) ) {
			return false;
		}
	}
	return true;
}

echo "TAP version 13\n";

$plugin      = 'mumega-mcp/mumega-mcp.php';
$expected_wp = (string) getenv( 'MCPWP_WPORG_EXPECTED_WP' );
$expected_php_minor = (string) getenv( 'MCPWP_WPORG_EXPECTED_PHP' );
$admin       = get_user_by( 'login', 'wporg-admin' );
wp_set_current_user( $admin ? $admin->ID : 0 );

mcpwp_wporg_tap( $expected_wp === get_bloginfo( 'version' ), 'runs the exact pinned WordPress version' );
mcpwp_wporg_tap( 0 === strpos( PHP_VERSION, $expected_php_minor . '.' ), 'runs the exact required PHP minor' );
mcpwp_wporg_tap( is_plugin_active( $plugin ) && defined( 'MCPWP_PLUGIN_BASENAME' ) && $plugin === MCPWP_PLUGIN_BASENAME, 'runs only the final installed plugin basename' );
$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
mcpwp_wporg_tap( isset( $plugin_data['Version'] ) && '3.4.13' === $plugin_data['Version'] && defined( 'MCPWP_VERSION' ) && '3.4.13' === MCPWP_VERSION, 'installed plugin identity is version 3.4.13' );

global $wpdb;
$activity_table = $wpdb->prefix . 'mcpwp_activity_log';
$table_exists   = $activity_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $activity_table ) );
$agent_role     = get_role( 'mcpwp_api_agent' );
$admin_role     = get_role( 'administrator' );
mcpwp_wporg_tap( $table_exists && $agent_role && $admin_role && $admin_role->has_cap( 'mcpwp_manage_settings' ), 'activation creates the table, API role, and administrator capability' );
mcpwp_wporg_tap( false !== wp_next_scheduled( 'mcpwp_cleanup_logs' ), 'normal bootstrap schedules the single current cleanup hook' );

global $menu, $submenu, $_registered_pages, $_parent_pages;
$menu = array();
$submenu = array();
$_registered_pages = array();
$_parent_pages = array();
do_action( 'admin_menu' );
$entries        = isset( $submenu['mcpwp'] ) ? $submenu['mcpwp'] : array();
$expected_slugs = array( 'mcpwp', 'mcpwp-tools', 'mcpwp-settings', 'mcpwp-activity-log' );
$actual_slugs   = array_values( array_map( static function ( $entry ) { return $entry[2]; }, $entries ) );
$caps_ok        = 4 === count( $entries );
foreach ( $entries as $entry ) {
	$caps_ok = $caps_ok && 'mcpwp_manage_settings' === $entry[1];
}
mcpwp_wporg_tap( $expected_slugs === $actual_slugs && $caps_ok, 'registers exactly four custom-capability admin pages' );

$admin_page = new Mcpwp_Admin();
$_POST = array();
$_GET  = array( 'page' => 'mcpwp' );
mcpwp_wporg_mark_context( 'admin_setup' );
ob_start();
$admin_page->render_setup_page();
$setup_html = ob_get_clean();
mcpwp_wporg_tap( false !== strpos( $setup_html, 'Mumega MCP Setup' ), 'admin_setup renders successfully' );

mcpwp_wporg_mark_context( 'admin_tools' );
ob_start();
( new Mcpwp_Tools_Admin() )->render();
$tools_html = ob_get_clean();
mcpwp_wporg_tap( false !== strpos( $tools_html, 'MCP Tool Inventory' ), 'admin_tools renders successfully' );

$_GET['page'] = 'mcpwp-settings';
mcpwp_wporg_mark_context( 'admin_settings' );
ob_start();
$admin_page->render_settings_page();
$settings_html = ob_get_clean();
mcpwp_wporg_tap( false !== strpos( $settings_html, 'Mumega MCP Settings' ), 'admin_settings renders successfully' );

$_GET['page'] = 'mcpwp-activity-log';
mcpwp_wporg_mark_context( 'admin_activity' );
ob_start();
$admin_page->render_activity_log_page();
$activity_html = ob_get_clean();
mcpwp_wporg_tap( false !== strpos( $activity_html, 'Mumega MCP Activity' ), 'admin_activity renders successfully' );

mcpwp_wporg_mark_context( 'key_create' );
$created = $admin_page->handle_setup_action(
	array(
		'mcpwp_create_key' => '1',
		'_wpnonce'         => wp_create_nonce( 'mcpwp_create_key' ),
		'label'            => 'WordPress.org exact ZIP gate',
		'scopes'           => array( 'read', 'write', 'admin' ),
		'tool_categories'  => array( 'site', 'content', 'media', 'taxonomy' ),
	)
);
$created_record = ! is_wp_error( $created ) && isset( $created['created'] ) ? $created['created'] : array();
$api_key        = isset( $created_record['key'] ) ? $created_record['key'] : '';
$api_key_id     = isset( $created_record['id'] ) ? $created_record['id'] : '';
mcpwp_wporg_tap( '' !== $api_key && '' !== $api_key_id, 'key_create returns plaintext once with a stable ID' );

mcpwp_wporg_mark_context( 'key_list' );
$stored_keys = get_option( 'mcpwp_api_keys', array() );
$stored_key  = ! empty( $stored_keys ) ? end( $stored_keys ) : array();
$public_keys = $admin_page->list_scoped_api_keys();
$public_key  = ! empty( $public_keys ) ? end( $public_keys ) : array();
$key_stored_safely = isset( $stored_key['hash'] ) && wp_check_password( $api_key, $stored_key['hash'] ) && ! isset( $stored_key['key'] ) && (int) $admin->ID === (int) $stored_key['user_id']
	&& isset( $public_key['id'] ) && $api_key_id === $public_key['id'] && ! isset( $public_key['hash'], $public_key['key'] );
mcpwp_wporg_tap( $key_stored_safely, 'key_list exposes metadata while storage contains only a verifier' );

update_option(
	'mcpwp_rate_limit_settings',
	array(
		'enabled'             => false,
		'requests_per_minute' => 60,
		'requests_per_hour'   => 1000,
		'burst_limit'         => 10,
		'whitelist'           => array(),
	),
	false
);
do_action( 'rest_api_init' );
$route_names = array_values( array_filter( array_keys( rest_get_server()->get_routes() ), static function ( $route ) { return 0 === strpos( $route, '/mcpwp/v1/' ); } ) );
mcpwp_wporg_tap( 19 === count( $route_names ), 'the installed candidate registers exactly 19 REST route patterns' );
$expected_tools = array(
	'wp_site_info', 'wp_list_posts', 'wp_get_post', 'wp_create_post', 'wp_update_post',
	'wp_list_pages', 'wp_get_page', 'wp_create_page', 'wp_update_page', 'wp_list_drafts',
	'wp_delete_draft', 'wp_list_media', 'wp_get_media', 'wp_upload_media_b64',
	'wp_list_categories', 'wp_list_tags', 'wp_create_term',
);
$advertised_tools = array_column( ( new Mcpwp_MCP_Free_Tools() )->get_tools(), 'name' );
mcpwp_wporg_tap( $expected_tools === $advertised_tools, 'the installed candidate advertises the exact 17-tool inventory' );

mcpwp_wporg_mark_context( 'site_info' );
$site_request = new WP_REST_Request( 'GET', '/mcpwp/v1/site-info' );
$site_request->set_header( 'X-API-Key', $api_key );
$site_response = rest_do_request( $site_request );
$site_data = is_wp_error( $site_response ) ? array() : $site_response->get_data();
mcpwp_wporg_tap( ! is_wp_error( $site_response ) && 200 === $site_response->get_status() && isset( $site_data['counts'] ), 'site_info direct REST read reaches its successful path' );

mcpwp_wporg_tool_call( 'wp_site_info', array(), $api_key );
$term = mcpwp_wporg_tool_call( 'wp_create_term', array( 'taxonomy' => 'category', 'name' => 'WP.org Gate Category' ), $api_key );
$term_id = isset( $term['id'] ) ? (int) $term['id'] : 0;
$post = mcpwp_wporg_tool_call( 'wp_create_post', array( 'title' => 'WP.org Gate Post', 'status' => 'draft', 'categories' => array( $term_id ), 'tags' => array( 'wporg-gate-tag' ) ), $api_key );
$post_id = isset( $post['id'] ) ? (int) $post['id'] : 0;
mcpwp_wporg_tool_call( 'wp_get_post', array( 'id' => $post_id ), $api_key );
mcpwp_wporg_tool_call( 'wp_update_post', array( 'id' => $post_id, 'title' => 'WP.org Gate Post Updated' ), $api_key );
mcpwp_wporg_tool_call( 'wp_list_posts', array( 'post_status' => 'draft' ), $api_key );
$page = mcpwp_wporg_tool_call( 'wp_create_page', array( 'title' => 'WP.org Gate Page', 'status' => 'draft' ), $api_key );
$page_id = isset( $page['id'] ) ? (int) $page['id'] : 0;
mcpwp_wporg_tool_call( 'wp_get_page', array( 'id' => $page_id ), $api_key );
mcpwp_wporg_tool_call( 'wp_update_page', array( 'id' => $page_id, 'title' => 'WP.org Gate Page Updated' ), $api_key );
mcpwp_wporg_tool_call( 'wp_list_pages', array( 'post_status' => 'draft' ), $api_key );
mcpwp_wporg_tool_call( 'wp_list_drafts', array( 'type' => 'all' ), $api_key );
$delete_id = wp_insert_post( array( 'post_title' => 'Delete through MCP', 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => $admin->ID ) );
mcpwp_wporg_tool_call( 'wp_delete_draft', array( 'id' => (int) $delete_id ), $api_key );
$png = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAGUlEQVQokWPkSbnDQApgIkn1qIZRDUNKAwAppAFsr7L83wAAAABJRU5ErkJggg==';
$media = mcpwp_wporg_tool_call( 'wp_upload_media_b64', array( 'data' => $png, 'filename' => 'wporg-gate.png', 'mime_type' => 'image/png' ), $api_key );
$media_id = isset( $media['id'] ) ? (int) $media['id'] : 0;
mcpwp_wporg_tool_call( 'wp_get_media', array( 'id' => $media_id ), $api_key );
mcpwp_wporg_tool_call( 'wp_list_media', array(), $api_key );
mcpwp_wporg_tool_call( 'wp_list_categories', array( 'hide_empty' => false ), $api_key );
mcpwp_wporg_tool_call( 'wp_list_tags', array( 'hide_empty' => false ), $api_key );

mcpwp_wporg_mark_context( 'denied_scope' );
$limited = $admin_page->handle_setup_action(
	array(
		'mcpwp_create_key' => '1',
		'_wpnonce'         => wp_create_nonce( 'mcpwp_create_key' ),
		'label'            => 'Read-only denial probe',
		'scopes'           => array( 'read' ),
		'tool_categories'  => array( 'content' ),
	)
);
$limited_key = ! is_wp_error( $limited ) && isset( $limited['created']['key'] ) ? $limited['created']['key'] : '';
$denied_payload = array( 'jsonrpc' => '2.0', 'id' => 'denied', 'method' => 'tools/call', 'params' => array( 'name' => 'wp_create_post', 'arguments' => array( 'title' => 'Denied' ) ) );
$denied_request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' );
$denied_request->set_header( 'X-API-Key', $limited_key );
$denied_request->set_header( 'Content-Type', 'application/json' );
$denied_request->set_body( wp_json_encode( mcpwp_wporg_rpc_wire_value( $denied_payload ) ) );
$denied_response = rest_do_request( $denied_request );
$denied_data = is_wp_error( $denied_response ) ? array() : $denied_response->get_data();
mcpwp_wporg_tap( isset( $denied_data['error']['data']['code'] ) && 'insufficient_scope' === $denied_data['error']['data']['code'], 'denied_scope rejects a successful-path mutation with a read-only key', wp_json_encode( $denied_data ) );

$unauthorized_id = username_exists( 'wporg-unauthorized' );
if ( ! $unauthorized_id ) {
	$unauthorized_id = wp_create_user( 'wporg-unauthorized', 'unauthorized-password', 'unauthorized@example.test' );
}
$unauthorized = new WP_User( $unauthorized_id );
$unauthorized->set_role( 'subscriber' );
wp_set_current_user( $unauthorized_id );
mcpwp_wporg_mark_context( 'denied_capability' );
$denied_admin = ( new Mcpwp_Settings() )->handle_admin_save( array( 'mcpwp_save_settings' => 'plugin', '_wpnonce' => 'invalid' ) );
mcpwp_wporg_tap( is_wp_error( $denied_admin ) && 'forbidden' === $denied_admin->get_error_code(), 'denied_capability rejects an admin mutation without the custom capability' );
wp_set_current_user( $admin->ID );

mcpwp_wporg_mark_context( 'key_revoke' );
$revoked = $admin_page->handle_setup_action( array( 'mcpwp_revoke_key' => '1', '_wpnonce' => wp_create_nonce( 'mcpwp_revoke_key' ), 'key_id' => $api_key_id ) );
mcpwp_wporg_tap( ! is_wp_error( $revoked ) && isset( $revoked['revoked'] ) && $api_key_id === $revoked['revoked'], 'key_revoke revokes the created key by ID' );

update_option( 'wporg_unrelated_option', 'preserve-me', false );
$unrelated_post_id = wp_insert_post( array( 'post_title' => 'Preserve through uninstall', 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => $admin->ID ) );
$unrelated_table = $wpdb->prefix . 'wporg_unrelated';
$wpdb->query( "CREATE TABLE {$unrelated_table} (id bigint(20) unsigned NOT NULL)" );
wp_schedule_single_event( time() + DAY_IN_SECONDS, 'wporg_unrelated_schedule' );
$legacy_options = array(
	'mcpwp_integrations', 'mcpwp_license', 'mcpwp_update_info', 'mcpwp_version_url',
	'mcpwp_analytics_consent', 'mcpwp_feedback_history', 'mcpwp_oauth_clients',
	'mcpwp_oauth_codes', 'mcpwp_webhooks', 'mcpwp_approvals', 'mcpwp_site_blueprints',
	'mcpwp_design_references',
);
foreach ( $legacy_options as $legacy_option ) {
	update_option( $legacy_option, array( 'wporg-owned' => true ), false );
}
$legacy_tables = array(
	$wpdb->prefix . 'mcpwp_webhooks',
	$wpdb->prefix . 'mcpwp_webhook_logs',
	$wpdb->prefix . 'mcpwp_feedback',
);
foreach ( $legacy_tables as $legacy_table ) {
	$wpdb->query( "CREATE TABLE {$legacy_table} (id bigint(20) unsigned NOT NULL)" );
}
$rate_transient = 'mcpwp_rate_' . md5( 'wporg-exact-zip' );
$similar_transient = 'mcpwp_ratex_' . md5( 'wporg-exact-zip' );
set_transient( $rate_transient, array( 'count' => 1, 'reset' => time() + DAY_IN_SECONDS ), DAY_IN_SECONDS );
set_transient( $similar_transient, 'preserve-similar-prefix', DAY_IN_SECONDS );
foreach ( array( 'mcpwp_check_alerts', 'mcpwp_verify_screenshot', 'mcpwp_compute_signals', 'mcpwp_action_log_daily_prune', 'mcpwp_webhook_retry' ) as $legacy_hook ) {
	wp_schedule_single_event( time() + DAY_IN_SECONDS, $legacy_hook );
}

$owned_state_before = get_option( 'mcpwp_settings', false );
$owned_keys_before  = get_option( 'mcpwp_api_keys', false );
$activity_rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$activity_table}" );
mcpwp_wporg_mark_context( 'deactivation' );
deactivate_plugins( $plugin );
Mcpwp_Deactivator::deactivate( false );
$owned_hooks_cleared = false === wp_next_scheduled( 'mcpwp_cleanup_logs' );
foreach ( array( 'mcpwp_check_alerts', 'mcpwp_verify_screenshot', 'mcpwp_compute_signals', 'mcpwp_action_log_daily_prune', 'mcpwp_webhook_retry' ) as $legacy_hook ) {
	$owned_hooks_cleared = $owned_hooks_cleared && false === wp_next_scheduled( $legacy_hook );
}
$deactivation_preserved = ! is_plugin_active( $plugin ) && false !== $owned_state_before && $owned_state_before === get_option( 'mcpwp_settings', false ) && $owned_keys_before === get_option( 'mcpwp_api_keys', false ) && get_role( 'mcpwp_api_agent' ) && get_role( 'administrator' )->has_cap( 'mcpwp_manage_settings' ) && $activity_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $activity_table ) ) && $activity_rows_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$activity_table}" ) && get_post( $unrelated_post_id );
mcpwp_wporg_tap( $owned_hooks_cleared && $deactivation_preserved, 'deactivation clears owned schedules while preserving plugin state, role, capability, table, and content' );

mcpwp_wporg_mark_context( 'reactivation' );
$reactivated = activate_plugin( $plugin );
if ( ! is_wp_error( $reactivated ) && class_exists( 'Mcpwp_Loader' ) ) {
	( new Mcpwp_Loader() )->maybe_schedule_log_cleanup();
}
$reactivation_ok = ! is_wp_error( $reactivated ) && is_plugin_active( $plugin ) && $owned_state_before === get_option( 'mcpwp_settings', false ) && $owned_keys_before === get_option( 'mcpwp_api_keys', false ) && false !== wp_next_scheduled( 'mcpwp_cleanup_logs' ) && $activity_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $activity_table ) ) && $activity_rows_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$activity_table}" );
mcpwp_wporg_tap( $reactivation_ok, 'reactivation restores operation without losing state or duplicating schema' );

mcpwp_wporg_mark_context( 'uninstall' );
deactivate_plugins( $plugin );
uninstall_plugin( $plugin );
$owned_tables_absent = null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $activity_table ) );
foreach ( $legacy_tables as $legacy_table ) {
	$owned_tables_absent = $owned_tables_absent && null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_table ) );
}
$owned_transient_rows = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
		'_transient_' . $rate_transient,
		'_transient_timeout_' . $rate_transient
	)
);
$owned_transients_absent = 0 === $owned_transient_rows;
$owned_hooks_absent = false === wp_next_scheduled( 'mcpwp_cleanup_logs' );
foreach ( array( 'mcpwp_check_alerts', 'mcpwp_verify_screenshot', 'mcpwp_compute_signals', 'mcpwp_action_log_daily_prune', 'mcpwp_webhook_retry' ) as $legacy_hook ) {
	$owned_hooks_absent = $owned_hooks_absent && false === wp_next_scheduled( $legacy_hook );
}
$unrelated_table_present = $unrelated_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $unrelated_table ) );
$uninstall_ok = mcpwp_wporg_plugin_options_absent() && $owned_tables_absent && $owned_transients_absent && $owned_hooks_absent && ! get_role( 'mcpwp_api_agent' ) && ! get_role( 'administrator' )->has_cap( 'mcpwp_manage_settings' );
$unrelated_preserved = 'preserve-me' === get_option( 'wporg_unrelated_option' )
	&& get_post( $unrelated_post_id ) && get_post( $post_id ) && get_post( $page_id ) && get_post( $media_id )
	&& term_exists( $term_id, 'category' ) && get_userdata( $unauthorized_id )
	&& $unrelated_table_present && false !== wp_next_scheduled( 'wporg_unrelated_schedule' )
	&& 'preserve-similar-prefix' === get_transient( $similar_transient );
mcpwp_wporg_tap(
	$uninstall_ok,
	'uninstall removes every current/legacy option, table, rate transient, role, and administrator capability',
	wp_json_encode(
		array(
			'options_absent'    => mcpwp_wporg_plugin_options_absent(),
			'tables_absent'     => $owned_tables_absent,
			'transients_absent' => $owned_transients_absent,
			'hooks_absent'      => $owned_hooks_absent,
			'role_absent'       => ! get_role( 'mcpwp_api_agent' ),
			'capability_absent' => ! get_role( 'administrator' )->has_cap( 'mcpwp_manage_settings' ),
		)
	)
);
mcpwp_wporg_tap( $unrelated_preserved, 'uninstall preserves unrelated/similar-prefix options, transients, content, tables, and schedules' );

$expected_contexts = array(
	'activation', 'admin_setup', 'admin_tools', 'admin_settings', 'admin_activity',
	'key_create', 'key_list', 'key_revoke', 'site_info',
	'wp_site_info', 'wp_list_posts', 'wp_get_post', 'wp_create_post', 'wp_update_post',
	'wp_list_pages', 'wp_get_page', 'wp_create_page', 'wp_update_page', 'wp_list_drafts',
	'wp_delete_draft', 'wp_list_media', 'wp_get_media', 'wp_upload_media_b64',
	'wp_list_categories', 'wp_list_tags', 'wp_create_term',
	'denied_scope', 'denied_capability', 'deactivation', 'reactivation', 'uninstall',
);
$covered_contexts = is_file( MCPWP_WPORG_COVERAGE_FILE ) ? file( MCPWP_WPORG_COVERAGE_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array();
$covered_contexts = array_values( array_unique( $covered_contexts ) );
$expected_sorted = $expected_contexts;
$covered_sorted  = $covered_contexts;
sort( $expected_sorted, SORT_STRING );
sort( $covered_sorted, SORT_STRING );
mcpwp_wporg_tap( $expected_sorted === $covered_sorted, 'outbound spy coverage equals the exact required operation and 17-tool context set', 'covered: ' . implode( ', ', $covered_sorted ) );

$attempts = is_file( MCPWP_WPORG_ATTEMPTS_FILE ) ? trim( (string) file_get_contents( MCPWP_WPORG_ATTEMPTS_FILE ) ) : '';
mcpwp_wporg_tap( '' === $attempts, 'pre-activation WordPress HTTP spy observed zero outbound attempts', $attempts );

$debug_log  = WP_CONTENT_DIR . '/debug.log';
$debug_size = is_file( $debug_log ) ? filesize( $debug_log ) : 0;
mcpwp_wporg_tap( 0 === $debug_size, 'plugin-phase debug log is absent or exactly zero bytes', 0 < $debug_size ? (string) file_get_contents( $debug_log ) : '' );

echo '1..' . $mcpwp_wporg_test_number . "\n";
exit( $mcpwp_wporg_failures > 0 ? 1 : 0 );
