<?php
/**
 * Plugin activation routines.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Provision the retained role, activity table, and default options on activation.
 */
class Mcpwp_Activator {
	/**
	 * Run all activation provisioning routines.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_api_role();
		self::create_activity_table();
		self::create_defaults();
	}
	/**
	 * Create or update the API-agent role and administrative capability.
	 *
	 * @return void
	 */
	private static function create_api_role() {
		$capabilities = array(
			'read'         => true,
			'edit_posts'   => true,
			'upload_files' => true,
		);
		$role         = get_role( 'mcpwp_api_agent' );
		if ( ! $role ) {
			$role = add_role( 'mcpwp_api_agent', __( 'Mumega MCP Agent', 'mumega-mcp' ), $capabilities ); }
		if ( $role ) {
			foreach ( $capabilities as $capability => $grant ) {
				$role->add_cap( $capability, $grant ); }
			// Defense-in-depth for sites upgrading from a version that granted
			// this cap: an agent-bound key must never carry admin-route access,
			// only its scoped API-key permissions. add_cap() above only sets the
			// retained caps — it does not strip a stale cap already on the role.
			$role->remove_cap( 'mcpwp_manage_settings' );
		}
		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator->add_cap( 'mcpwp_manage_settings' ); }
	}
	/**
	 * Create or update the local activity-log database table.
	 *
	 * @return void
	 */
	private static function create_activity_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'mcpwp_activity_log';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			action varchar(191) NOT NULL DEFAULT '',
			status_code smallint(5) unsigned NOT NULL DEFAULT 200,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			api_key_id varchar(64) NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY action (action)
		) {$charset};"
		);
	}
	/**
	 * Persist initial plugin, rate-limit, migration-owner, and database settings.
	 *
	 * @return void
	 */
	private static function create_defaults() {
		$owner_id = get_current_user_id();
		if ( $owner_id > 0 && get_userdata( $owner_id ) && current_user_can( 'manage_options' ) ) {
			update_option( 'mcpwp_legacy_migration_owner_id', $owner_id, false ); }
		if ( false === get_option( 'mcpwp_settings', false ) ) {
			update_option(
				'mcpwp_settings',
				array(
					'enable_logging'     => true,
					'log_retention_days' => 30,
				),
				false
			); }
		if ( false === get_option( 'mcpwp_rate_limit_settings', false ) ) {
			update_option(
				'mcpwp_rate_limit_settings',
				array(
					'enabled'             => true,
					'requests_per_minute' => 60,
					'requests_per_hour'   => 1000,
					'burst_limit'         => 10,
					'whitelist'           => array(),
				),
				false
			); }
		update_option( 'mcpwp_db_version', MCPWP_VERSION, false );
	}
}
