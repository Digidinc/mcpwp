<?php
/** Plugin activation. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_Activator {
	public static function activate() {
		self::create_api_role();
		self::create_activity_table();
		self::create_defaults();
	}
	private static function create_api_role() {
		$capabilities = array( 'read' => true, 'edit_posts' => true, 'upload_files' => true, 'mcpwp_manage_settings' => true );
		$role = get_role( 'mcpwp_api_agent' );
		if ( ! $role ) { $role = add_role( 'mcpwp_api_agent', __( 'Mumega MCP Agent', 'mumega-mcp' ), $capabilities ); }
		if ( $role ) { foreach ( $capabilities as $capability => $grant ) { $role->add_cap( $capability, $grant ); } }
		$administrator = get_role( 'administrator' );
		if ( $administrator ) { $administrator->add_cap( 'mcpwp_manage_settings' ); }
	}
	private static function create_activity_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'mcpwp_activity_log';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			method varchar(12) NOT NULL DEFAULT '',
			endpoint varchar(255) NOT NULL DEFAULT '',
			status_code smallint(5) unsigned NOT NULL DEFAULT 200,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			request_data longtext NULL,
			response_data longtext NULL,
			api_key_id varchar(64) NULL,
			PRIMARY KEY (id), KEY created_at (created_at), KEY endpoint (endpoint)
		) {$charset};" );
	}
	private static function create_defaults() {
		if ( false === get_option( 'mcpwp_settings', false ) ) { update_option( 'mcpwp_settings', array( 'enable_logging' => true, 'log_retention_days' => 30, 'log_store_response_data' => false, 'log_redaction_keys' => array( 'api_key', 'authorization', 'token' ) ), false ); }
		if ( false === get_option( 'mcpwp_rate_limit_settings', false ) ) { update_option( 'mcpwp_rate_limit_settings', array( 'enabled' => true, 'requests_per_minute' => 60, 'requests_per_hour' => 1000, 'burst_limit' => 10, 'whitelist' => array() ), false ); }
		update_option( 'mcpwp_db_version', MCPWP_VERSION, false );
	}
}
